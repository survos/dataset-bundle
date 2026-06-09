<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Service\DataPaths;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Bytes;

/**
 * One-time fixer for the legacy raw layout: turn real `work/<p>/<c>/_raw` directories into the
 * spec's tier portal (a symlink to `vault/<p>/<c>/`, the canonical durable raw home).
 *
 * For each dataset with a *real* `_raw` dir it:
 *   1. moves the raw cores (`*.jsonl[.gz]`) into the vault dataset dir,
 *   2. deletes the derived sidecars left behind (`*.profile.json`, `*.idx.json`, `*.sidecar.json`,
 *      `*.jsonl.db[-wal|-shm]`) — they regenerate, and vault should hold source bytes only,
 *   3. replaces `_raw` with a symlink to the vault dir (via DataPaths::ensureRawPortal).
 *
 * Dry-run by default; pass --force to apply. Idempotent: a `_raw` that is already a symlink (or a
 * dataset with no `_raw`) is skipped. Never clobbers a core that already exists in the vault.
 *
 * See md/docs/data-layout.md ("Fix the raw tier on disk").
 */
#[AsCommand('dataset:raw:migrate', 'Move real _raw dirs into the vault and replace them with portal symlinks')]
final class DataRawMigrateCommand
{
    private const CORE_SUFFIXES    = ['.jsonl', '.jsonl.gz'];
    private const DERIVED_SUFFIXES = ['.profile.json', '.idx.json', '.sidecar.json', '.jsonl.db', '.jsonl.db-wal', '.jsonl.db-shm'];

    public function __construct(
        private readonly DataPaths $paths,
        private readonly Filesystem $filesystem = new Filesystem(),
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Dataset key (provider/code) or a bare code; omit to do all (optionally filtered by --provider)')]
        ?string $ref = null,
        #[Option('Restrict to this provider, e.g. mus')]
        ?string $provider = null,
        #[Option('Apply changes (default: dry-run preview only)')]
        bool $force = false,
        #[Option('Keep derived raw sidecars (move them to vault too) instead of deleting them')]
        bool $keepSidecars = false,
    ): int {
        $io->title('Migrate _raw → vault portal' . ($force ? '' : '  [DRY RUN]'));

        $targets = $this->targets($ref, $provider);
        if ($targets === []) {
            $io->success('Nothing to migrate — no real _raw directories found (already portals?).');
            return Command::SUCCESS;
        }

        $movedCores = 0;
        $deleted = 0;
        $portaled = 0;
        $skipped = 0;

        foreach ($targets as $datasetKey) {
            $rawDir   = $this->paths->stageDir($datasetKey, Stage::Raw);
            $vaultDir = $this->paths->vaultDatasetDir($datasetKey);
            $io->section($datasetKey);

            $rows = [];
            $blocked = false;
            $leftover = false;

            foreach (new \DirectoryIterator($rawDir) as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $name = $entry->getFilename();
                $size = (string) Bytes::parse((int) $entry->getSize())->humanize();

                if ($this->hasSuffix($name, self::CORE_SUFFIXES) || ($keepSidecars && $this->hasSuffix($name, self::DERIVED_SUFFIXES))) {
                    $dest = $vaultDir . '/' . $name;
                    if (is_file($dest)) {
                        $rows[] = ['<fg=yellow>skip</>', $name, $size, 'already in vault — left in place'];
                        $blocked = true;
                        continue;
                    }
                    $rows[] = ['move', $name, $size, '→ vault/' . basename($vaultDir) . '/'];
                    if ($force) {
                        $this->filesystem->mkdir($vaultDir);
                        $this->filesystem->rename($rawDir . '/' . $name, $dest);
                    }
                    $movedCores++;
                } elseif ($this->hasSuffix($name, self::DERIVED_SUFFIXES)) {
                    $rows[] = ['<fg=red>delete</>', $name, $size, 'derived/regenerable'];
                    if ($force) {
                        $this->filesystem->remove($rawDir . '/' . $name);
                    }
                    $deleted++;
                } else {
                    $rows[] = ['<fg=yellow>leave</>', $name, $size, 'unrecognized — not touched'];
                    $leftover = true;
                }
            }

            if ($rows !== []) {
                $io->table(['action', 'file', 'size', 'note'], $rows);
            }

            if ($blocked || $leftover) {
                $io->warning('_raw not emptied (vault conflict or unrecognized files); leaving as a real dir. Resolve manually, then re-run.');
                $skipped++;
                continue;
            }

            // _raw will be empty after the moves/deletes → finalize the portal symlink.
            if ($force) {
                $portal = $this->paths->ensureRawPortal($datasetKey);
                if (is_link($portal)) {
                    $io->writeln(sprintf('  _raw → %s', readlink($portal)));
                    $portaled++;
                }
            } else {
                $io->writeln(sprintf('  would symlink _raw → %s', $vaultDir));
                $portaled++;
            }
        }

        $verb = $force ? 'Done' : 'Plan';
        $io->success(sprintf(
            '%s: %d core(s) moved, %d derived file(s) deleted, %d portal(s) created, %d dataset(s) skipped.',
            $verb, $movedCores, $deleted, $portaled, $skipped
        ));
        if (!$force) {
            $io->note('Dry run — re-run with --force to apply.');
        }

        return Command::SUCCESS;
    }

    /** @return list<string> dataset keys with a real (non-symlink) _raw dir */
    private function targets(?string $ref, ?string $provider): array
    {
        $root = $this->paths->workRoot;
        $keys = [];

        $ref = $ref !== null ? strtolower(trim($ref)) : '';
        if ($ref !== '') {
            if (str_contains($ref, '/')) {
                $keys = [$ref];
            } else {
                foreach (glob("{$root}/*/{$ref}", GLOB_ONLYDIR) ?: [] as $dir) {
                    $keys[] = basename(dirname($dir)) . '/' . basename($dir);
                }
            }
        } else {
            $pattern = $provider !== null && trim($provider) !== ''
                ? "{$root}/" . strtolower(trim($provider)) . '/*'
                : "{$root}/*/*";
            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $dir) {
                $keys[] = basename(dirname($dir)) . '/' . basename($dir);
            }
        }

        return array_values(array_filter($keys, function (string $key): bool {
            $rawDir = $this->paths->stageDir($key, Stage::Raw);
            return is_dir($rawDir) && !is_link($rawDir);
        }));
    }

    /** @param list<string> $suffixes */
    private function hasSuffix(string $name, array $suffixes): bool
    {
        foreach ($suffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

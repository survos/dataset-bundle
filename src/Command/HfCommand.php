<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Command;

use Survos\DatasetBundle\Service\DataPaths;
use Survos\DatasetBundle\Service\HfHubClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Bytes;

/**
 * HuggingFace sync for the canonical archive — pull and push as two commands on
 * one service (shared constructor + helpers).
 *
 *   c hf:pull dc                                  # museado/dc → archive/dc/<code>.jsonl.gz
 *   c hf:pull dc --repo=museado/digitalcommonwealth-data
 *   c hf:push dc                                  # archive/dc/*.jsonl.gz → museado/dc (needs HF_TOKEN)
 *
 * Repo defaults to museado/<provider>, overridable with --repo. Both sides are
 * incremental: pull skips files already on disk (size match vs the HF tree),
 * push skips files already in the repo (sha256 via git-LFS).
 */
final class HfCommand
{
    public function __construct(
        private readonly DataPaths $paths,
        private readonly HfHubClient $hub,
    ) {
    }

    #[AsCommand('hf:pull', 'Pull a HuggingFace dataset into APP_DATA_DIR/archive/<provider>')]
    public function pull(
        SymfonyStyle $io,

        #[Argument('Provider code, e.g. dc')]
        ?string $provider = null,

        #[Option('HF dataset repo (default: museado/<provider>)', shortcut: 'r')]
        ?string $repo = null,

        #[Option('Only this collection code (e.g. b8517907j)')]
        ?string $dataset = null,

        #[Option('Limit number of files')]
        ?int $limit = null,

        #[Option('List what would be pulled; download nothing')]
        bool $dryRun = false,
    ): int {
        if (null === $provider || '' === $provider) {
            $io->error('Provide a provider, e.g. hf:pull dc');
            return Command::FAILURE;
        }
        $repo ??= 'museado/' . $provider;
        $io->title(sprintf('hf:pull  %s  ←  %s', $provider, $repo));

        $files = array_values(array_filter(
            $this->hub->listFiles($repo),
            static fn (array $f) => str_ends_with($f['path'], '.jsonl.gz'),
        ));
        if (null !== $dataset && '' !== $dataset) {
            $files = array_values(array_filter($files, static fn (array $f) => str_starts_with($f['path'], $dataset)));
        }
        $io->note(sprintf('%d archive file(s) in %s', count($files), $repo));

        $pulled = $skipped = $failed = 0;
        $bytes = 0;
        $n = 0;
        foreach ($files as $f) {
            if (null !== $limit && $n >= $limit) {
                break;
            }
            $n++;
            $dest = $this->paths->providerArchiveFile($provider, $f['path']);

            // skip-existing: present locally with matching size (no checksum needed).
            if (is_file($dest) && (int) filesize($dest) === $f['size']) {
                $skipped++;
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  <comment>skip</comment> %s (%s)', $f['path'], Bytes::parse($f['size'])->humanize()));
                }
                continue;
            }
            if ($dryRun) {
                $io->writeln(sprintf('  would pull %s (%s)', $f['path'], Bytes::parse($f['size'])->humanize()));
                continue;
            }
            try {
                $written = $this->hub->download($repo, $f['path'], $dest);
                $bytes += $written;
                $pulled++;
                if ($io->isVerbose()) {
                    $io->writeln(sprintf('  <info>pull</info> %s (%s)', $f['path'], Bytes::parse($written)->humanize()));
                }
            } catch (\Throwable $e) {
                $failed++;
                $io->writeln(sprintf('  <error>fail</error> %s: %s', $f['path'], $e->getMessage()));
            }
        }

        $io->success(sprintf(
            'pulled %d · skipped %d · failed %d  (%s)  →  %s',
            $pulled, $skipped, $failed, Bytes::parse($bytes)->humanize(), $this->paths->providerArchiveRoot($provider),
        ));
        if ($pulled > 0 && !$dryRun) {
            $io->note('Next: symlink → import:convert (normalize) → folio:build.');
        }
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    #[AsCommand('hf:push', 'Push APP_DATA_DIR/archive/<provider> to a HuggingFace dataset (needs HF_TOKEN)')]
    public function push(
        SymfonyStyle $io,

        #[Argument('Provider code, e.g. dc')]
        ?string $provider = null,

        #[Option('HF dataset repo (default: museado/<provider>)', shortcut: 'r')]
        ?string $repo = null,

        #[Option('Only this collection code (e.g. b8517907j)')]
        ?string $dataset = null,

        #[Option('Limit number of files')]
        ?int $limit = null,

        #[Option('List what would be pushed; upload nothing')]
        bool $dryRun = false,
    ): int {
        if (null === $provider || '' === $provider) {
            $io->error('Provide a provider, e.g. hf:push dc');
            return Command::FAILURE;
        }
        $repo ??= 'museado/' . $provider;
        $archiveRoot = $this->paths->providerArchiveRoot($provider);
        $io->title(sprintf('hf:push  %s  →  %s', $provider, $repo));

        if (!is_dir($archiveRoot)) {
            $io->error('No local archive directory: ' . $archiveRoot);
            return Command::FAILURE;
        }

        // Map repo-path => local file. Repo path is the basename (flat <code>.jsonl.gz).
        $map = [];
        $n = 0;
        foreach (glob($archiveRoot . '/*.jsonl.gz') ?: [] as $local) {
            $base = basename($local);
            if (null !== $dataset && '' !== $dataset && !str_starts_with($base, $dataset)) {
                continue;
            }
            if (null !== $limit && $n >= $limit) {
                break;
            }
            $map[$base] = $local;
            $n++;
        }

        if ([] === $map) {
            $io->warning('No .jsonl.gz files to push under ' . $archiveRoot);
            return Command::SUCCESS;
        }
        $io->note(sprintf('%d local file(s) staged', count($map)));

        if ($dryRun) {
            foreach ($map as $repoPath => $local) {
                $io->writeln(sprintf('  would push %s (%s)', $repoPath, Bytes::parse((int) filesize($local))->humanize()));
            }
            return Command::SUCCESS;
        }

        $result = $this->hub->uploadFiles($repo, $map, summary: sprintf('hf:push %s (%d files)', $provider, count($map)));

        $io->success(sprintf('uploaded %d · skipped %d (already present)', count($result['uploaded']), count($result['skipped'])));
        return Command::SUCCESS;
    }
}

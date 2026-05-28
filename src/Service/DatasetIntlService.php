<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\DatasetBundle\Enum\Stage;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\LinguaBundle\Service\LinguaClient;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Translation-pipeline commands for a dataset's 25_intl artifacts.
 *
 * Reads <dataset>/25_intl/phrases.<sourceLocale>.jsonl, queries the Lingua
 * server for translations, and writes <dataset>/25_intl/tr.<targetLocale>.jsonl
 * with matched results.
 *
 * Push (sending new phrases to be translated) is deferred — pulling against a
 * Lingua server that already has cached translations for these hashes is
 * sufficient for an initial pass; new phrases come back as "missing" and can
 * be queued in a later pass.
 */
final class DatasetIntlService
{
    public function __construct(
        private readonly DataPaths $paths,
        private readonly LinguaClient $lingua,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    #[AsCommand('dataset:intl:pull', 'fetch translations for a dataset from the Lingua server into 25_intl/tr.<locale>.jsonl')]
    public function pull(
        SymfonyStyle $io,
        #[Argument('dataset key, e.g. mus/larco')] string $dataset,
        #[Option('comma-separated target locales (e.g. en,de,fr)')] string $targets = 'en',
        #[Option('preferred engine filter (libre, deepl, …)')] ?string $engine = null,
    ): int {
        $intlDir = $this->paths->stageDir($dataset, Stage::Intl->value);
        if (!is_dir($intlDir)) {
            $io->error("No 25_intl directory for $dataset. Run import:convert --stage=normalize first.");
            return Command::FAILURE;
        }

        $sourceFiles = glob("$intlDir/phrases.*.jsonl") ?: [];
        if ($sourceFiles === []) {
            $io->error("No phrases.<locale>.jsonl files found in $intlDir.");
            return Command::FAILURE;
        }

        $targetLocales = $this->parseTargets($targets);
        if ($targetLocales === []) {
            $io->error('No target locales. Pass --targets=en,de,…');
            return Command::INVALID;
        }

        $codes = [];
        foreach ($sourceFiles as $file) {
            foreach (JsonlReader::open($file) as $row) {
                if (is_array($row) && isset($row['code'])) {
                    $codes[(string) $row['code']] = true;
                }
            }
        }
        $codes = array_keys($codes);

        $io->title("Lingua PULL: $dataset");
        $io->definitionList(
            ['dataset' => $dataset],
            ['source phrase codes' => (string) count($codes)],
            ['target locales' => implode(', ', $targetLocales)],
            ['lingua server' => $this->lingua->baseUri ?? '(unset)'],
        );

        if ($codes === []) {
            $io->warning('No phrase codes to pull.');
            return Command::SUCCESS;
        }

        $totalFound = 0;
        foreach ($targetLocales as $locale) {
            $map = $this->lingua->pullBabelByHashes($codes, locale: $locale, engine: $engine);
            $outFile = "$intlDir/tr.$locale.jsonl";
            $writer  = JsonlWriter::open($outFile);
            $written = 0;
            try {
                foreach ($map as $code => $text) {
                    if (!is_string($text) || $text === '') {
                        continue;
                    }
                    $writer->write(['code' => $code, 'locale' => $locale, 'text' => $text]);
                    $written++;
                }
            } finally {
                $writer->close();
            }
            $missing = count($codes) - $written;
            $io->writeln(sprintf(
                '  %s: <info>%d</info> translated, %d missing → %s',
                $locale, $written, $missing, $outFile
            ));
            $totalFound += $written;
        }

        $io->success(sprintf(
            'Pulled %d translation(s) across %d locale(s).',
            $totalFound,
            count($targetLocales)
        ));
        return Command::SUCCESS;
    }

    /** @return list<string> */
    private function parseTargets(string $targets): array
    {
        $parts = preg_split('/[,\s]+/', trim($targets)) ?: [];
        return array_values(array_unique(array_filter(array_map('trim', $parts))));
    }
}

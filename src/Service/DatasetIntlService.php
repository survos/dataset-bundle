<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\DatasetBundle\Enum\Stage;
use Survos\JsonlBundle\IO\JsonlReader;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\Lingua\Contracts\Dto\BatchRequest;
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
 */
final class DatasetIntlService
{
    public function __construct(
        private readonly DataPaths $paths,
        private readonly LinguaClient $lingua,
        private readonly PhraseExtractor $phraseExtractor,
        ?LoggerInterface $logger = null,
    ) {
        unset($logger);
    }

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
                if (isset($row['code'])) {
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
            ['lingua server' => $this->lingua->baseUri],
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
                    if ($text === '') {
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

    /**
     * Extracts everything translatable for a dataset into one phrases.<locale>.jsonl:
     *
     *  - Term/termSet labels, small in number with a lot of repetition across datasets (the
     *    same "Photograph"/"Silver gelatin print" recur everywhere) -- via
     *    PhraseExtractor::acceptTermLabel().
     *  - Row-level free text (title, description, ... -- whichever fields a row's DTO class
     *    marks #[Translatable], per TranslatableReflector) -- via PhraseExtractor::accept(),
     *    normally driven automatically by import:convert's row events, but replayed here
     *    directly against the already-normalized JSONL so re-extraction doesn't require
     *    reconverting the whole dataset.
     *
     * Both feed the same phrase queue, deduplicated by content hash, in one reset/flush cycle --
     * PhraseExtractor::flush() truncates phrases.<locale>.jsonl, so splitting this across two
     * separate command invocations would make the second overwrite the first's output.
     */
    #[AsCommand('dataset:intl:extract-terms', 'Extract term/termSet labels + translatable row content into 25_intl/phrases.<locale>.jsonl for translation')]
    public function extractTerms(
        SymfonyStyle $io,
        #[Argument('dataset key, e.g. mus/larco')] string $dataset,
    ): int {
        $termsDir = $this->paths->stageDir($dataset, Stage::Terms->value);
        $normDir = $this->paths->stageDir($dataset, Stage::Normalize->value);
        $termSetFile = "$termsDir/termSet.jsonl";
        $termFile = "$termsDir/term.jsonl";

        if (!is_file($termSetFile) || !is_file($termFile)) {
            // FolioIngestService currently reads term.jsonl/termSet.jsonl from the
            // normalize stage rather than the (newer) terms stage -- fall back so
            // this works against whichever layout a dataset actually has on disk.
            $termSetFile = "$normDir/termSet.jsonl";
            $termFile = "$normDir/term.jsonl";
        }

        $rowFiles = array_values(array_filter(
            glob("$normDir/*.jsonl") ?: [],
            static fn (string $f): bool => !in_array(basename($f), ['term.jsonl', 'termSet.jsonl'], true),
        ));

        if (!is_file($termFile) && $rowFiles === []) {
            $io->error("Nothing to extract for $dataset: no term.jsonl and no normalize/*.jsonl rows found.");
            return Command::FAILURE;
        }

        $this->phraseExtractor->reset($dataset);

        $termCount = 0;
        if (is_file($termFile)) {
            foreach (JsonlReader::open($termFile) as $row) {
                $label = $row['label'] ?? null;
                if (!is_string($label) || trim($label) === '') {
                    continue;
                }
                $setCode = isset($row['termSet']) && is_scalar($row['termSet']) ? (string) $row['termSet'] : null;
                $this->phraseExtractor->acceptTermLabel($label, $setCode);
                $termCount++;
            }
        }

        $rowCount = 0;
        foreach ($rowFiles as $rowFile) {
            foreach (JsonlReader::open($rowFile) as $row) {
                $this->phraseExtractor->accept($row);
                $rowCount++;
            }
        }

        $written = $this->phraseExtractor->flush();

        $io->success(sprintf(
            'Processed %d term label(s) + %d row(s), wrote %d distinct phrase(s) (repeats collapsed by content hash).',
            $termCount,
            $rowCount,
            $written,
        ));

        return Command::SUCCESS;
    }

    #[AsCommand('dataset:intl:push', 'Push a dataset\'s extracted phrases to the Lingua server for translation')]
    public function push(
        SymfonyStyle $io,
        #[Argument('dataset key, e.g. mus/larco')] string $dataset,
        #[Option('comma-separated target locales (e.g. es,hu)')] string $targets = 'es,hu',
        #[Option('preferred engine (libre, deepl, …)')] string $engine = 'libre',
        #[Option('batch size per request')] int $batch = 200,
    ): int {
        $intlDir = $this->paths->stageDir($dataset, Stage::Intl->value);
        $sourceFiles = glob("$intlDir/phrases.*.jsonl") ?: [];
        if ($sourceFiles === []) {
            $io->error("No phrases.<locale>.jsonl in $intlDir. Run dataset:intl:extract-terms (or the row-level pipeline) first.");
            return Command::FAILURE;
        }

        $targetLocales = $this->parseTargets($targets);
        if ($targetLocales === []) {
            $io->error('No target locales. Pass --targets=es,hu,…');
            return Command::INVALID;
        }

        $totalSent = 0;
        foreach ($sourceFiles as $file) {
            if (!preg_match('/phrases\.([a-zA-Z_-]+)\.jsonl$/', $file, $m)) {
                continue;
            }
            $sourceLocale = $m[1];

            $texts = [];
            foreach (JsonlReader::open($file) as $row) {
                if (isset($row['text']) && is_string($row['text']) && $row['text'] !== '') {
                    $texts[] = $row['text'];
                }
            }
            $texts = array_values(array_unique($texts));

            if ($texts === []) {
                continue;
            }

            foreach (array_chunk($texts, $batch) as $chunk) {
                $this->lingua->requestBatch(new BatchRequest(
                    source: $sourceLocale,
                    target: $targetLocales,
                    texts: $chunk,
                    engine: $engine,
                ));
                $totalSent += count($chunk);
            }

            $io->writeln(sprintf('  %s: <info>%d</info> phrase(s) sent', $sourceLocale, count($texts)));
        }

        $io->success(sprintf(
            'Pushed %d phrase(s) for translation into %s. Run dataset:intl:pull once processed.',
            $totalSent,
            implode(', ', $targetLocales),
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

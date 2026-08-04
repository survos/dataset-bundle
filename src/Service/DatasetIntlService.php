<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\GeonamesBundle\Dto\GeoRecord;
use Survos\GeonamesBundle\Service\GeoService;
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
 *
 * push() also tries geonames-bundle before Lingua for each phrase (survos-sites/musdig#31):
 * a bare proper noun like a Chilean place name ("santiago") isn't really a "translation" task,
 * and geonames-bundle's alternateNames() is real authoritative locale-tagged data rather than a
 * machine-translation guess. See resolvePlace()/mergeTranslationRows() below. $geoService is
 * optional (SurvosDatasetBundle wires it NULL_ON_INVALID_REFERENCE) -- push()/pull() work with
 * Lingua alone when survos/geonames-bundle isn't installed, geonames resolution is a bonus.
 */
final class DatasetIntlService
{
    public function __construct(
        private readonly DataPaths $paths,
        private readonly LinguaClient $lingua,
        private readonly PhraseExtractor $phraseExtractor,
        private readonly DatasetInfoRepository $datasets,
        private readonly ?GeoService $geoService = null,
        ?LoggerInterface $logger = null,
    ) {
        unset($logger);
    }

    /**
     * --targets/--engine, if passed, always win. Otherwise fall back to this dataset's
     * _meta/dataset.json locale.targets/preferredEngine (DatasetInfo::$targetLocales/
     * $preferredEngine, populated by data:scan-datasets), and only then to the hardcoded
     * command default — so a dataset like mus/cazma (hr, deepl-only) doesn't need --targets=en
     * --engine=deepl typed on every push/pull once its harvester's writeMeta() sets it once.
     *
     * @return array{targets: list<string>, engine: ?string}
     */
    private function resolveTargetsAndEngine(string $dataset, ?string $targetsArg, ?string $engineArg, string $targetsDefault, ?string $engineDefault = null): array
    {
        $info = $this->datasets->find($dataset);

        $targets = $targetsArg !== null
            ? $this->parseTargets($targetsArg)
            : ($info?->targetLocales ?: $this->parseTargets($targetsDefault));

        $engine = $engineArg ?? $info?->preferredEngine ?? $engineDefault;

        return ['targets' => $targets, 'engine' => $engine];
    }

    #[AsCommand('dataset:intl:pull', 'fetch translations for a dataset from the Lingua server into 25_intl/tr.<locale>.jsonl')]
    public function pull(
        SymfonyStyle $io,
        #[Argument('dataset key, e.g. mus/larco')] string $dataset,
        #[Option('comma-separated target locales (e.g. en,de,fr) — defaults to the dataset\'s configured locale.targets')] ?string $targets = null,
        #[Option('preferred engine filter (libre, deepl, …) — defaults to the dataset\'s configured locale.preferredEngine')] ?string $engine = null,
    ): int {
        $resolved = $this->resolveTargetsAndEngine($dataset, $targets, $engine, 'en');
        $targetLocales = $resolved['targets'];
        $engine = $resolved['engine'];

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

        if ($targetLocales === []) {
            $io->error('No target locales. Pass --targets=en,de,… or set locale.targets in _meta/dataset.json.');
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

            $rows = [];
            foreach ($map as $code => $text) {
                if ($text === '') {
                    continue;
                }
                $rows[] = ['code' => (string) $code, 'locale' => $locale, 'text' => $text];
            }
            // Merge, not truncate-and-write: push() may have already written geonames-resolved
            // rows into this same file for codes Lingua was never even asked about (deliberately
            // excluded from the batch it queued) -- a plain overwrite here would silently delete
            // them on every re-pull. See mergeTranslationRows().
            $this->mergeTranslationRows($outFile, $rows);
            $written = count($rows);

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
        #[Option('comma-separated target locales (e.g. es,hu) — defaults to the dataset\'s configured locale.targets')] ?string $targets = null,
        #[Option('preferred engine (libre, deepl, …) — defaults to the dataset\'s configured locale.preferredEngine')] ?string $engine = null,
        #[Option('batch size per request')] int $batch = 200,
    ): int {
        $resolved = $this->resolveTargetsAndEngine($dataset, $targets, $engine, 'es,hu', 'libre');
        $targetLocales = $resolved['targets'];
        $engine = $resolved['engine'];

        $intlDir = $this->paths->stageDir($dataset, Stage::Intl->value);
        $sourceFiles = glob("$intlDir/phrases.*.jsonl") ?: [];
        if ($sourceFiles === []) {
            $io->error("No phrases.<locale>.jsonl in $intlDir. Run dataset:intl:extract-terms (or the row-level pipeline) first.");
            return Command::FAILURE;
        }

        if ($targetLocales === []) {
            $io->error('No target locales. Pass --targets=es,hu,… or set locale.targets in _meta/dataset.json.');
            return Command::INVALID;
        }

        $countryCode = $this->datasets->find($dataset)?->country;

        $totalSent = 0;
        $totalGeoResolved = 0;
        foreach ($sourceFiles as $file) {
            if (!preg_match('/phrases\.([a-zA-Z_-]+)\.jsonl$/', $file, $m)) {
                continue;
            }
            $sourceLocale = $m[1];

            // code => text, not just unique texts: geonames resolution and tr.<locale>.jsonl rows
            // both key on the phrase code, and PhraseExtractor already dedupes by content hash
            // within one source-locale file, so code<->text is already 1:1 here.
            $texts = [];
            /** @var array<string, list<array{code: string, locale: string, text: string}>> $geoResolvedByLocale */
            $geoResolvedByLocale = [];
            foreach (JsonlReader::open($file) as $row) {
                $code = isset($row['code']) ? (string) $row['code'] : '';
                $text = $row['text'] ?? null;
                if ($code === '' || !is_string($text) || $text === '') {
                    continue;
                }

                $place = null !== $countryCode ? $this->resolvePlace($text, $countryCode) : null;
                if (null !== $place) {
                    $alternateNames = $this->geoAlternateNames($place->geonameId, $countryCode);
                    foreach ($targetLocales as $targetLocale) {
                        $geoResolvedByLocale[$targetLocale][] = [
                            'code' => $code,
                            'locale' => $targetLocale,
                            // Prefer a real locale-tagged alternate name; a place with no recorded
                            // variant in this language almost always looks the same or close enough
                            // across languages anyway — safer than routing a proper noun through MT.
                            'text' => $alternateNames[$targetLocale][0] ?? $place->name,
                        ];
                    }
                    $totalGeoResolved++;
                    continue; // never queued for Lingua
                }

                $texts[$code] = $text;
            }

            foreach ($geoResolvedByLocale as $targetLocale => $rows) {
                $this->mergeTranslationRows("$intlDir/tr.$targetLocale.jsonl", $rows);
            }

            if ($texts === []) {
                continue;
            }

            foreach (array_chunk(array_values($texts), $batch) as $chunk) {
                $this->lingua->requestBatch(new BatchRequest(
                    source: $sourceLocale,
                    target: $targetLocales,
                    texts: $chunk,
                    engine: $engine,
                ));
                $totalSent += count($chunk);
            }

            $io->writeln(sprintf('  %s: <info>%d</info> phrase(s) sent to Lingua', $sourceLocale, count($texts)));
        }

        if ($totalGeoResolved > 0) {
            $io->writeln(sprintf('  <info>%d</info> phrase(s) resolved via geonames instead (no Lingua request)', $totalGeoResolved));
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

    /**
     * Resolve $text as a place name within $countryCode via geonames-bundle, tolerating a
     * missing/unfetched authority database (GeoService throws RuntimeException when the relevant
     * <countryCode>.sqlite hasn't been downloaded) — falls through to Lingua for that phrase
     * rather than crashing the whole push for datasets/countries geonames hasn't been fetched for.
     */
    private function resolvePlace(string $text, string $countryCode): ?GeoRecord
    {
        if (null === $this->geoService) {
            return null;
        }
        try {
            return $this->geoService->find($text, $countryCode);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, list<string>> */
    private function geoAlternateNames(int $geonameId, string $countryCode): array
    {
        if (null === $this->geoService) {
            return [];
        }
        try {
            return $this->geoService->alternateNames($geonameId, $countryCode);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Merges $newRows into an existing tr.<locale>.jsonl file, keyed by phrase code -- new rows win
     * per code, everything else already on disk is preserved. Used by both push() (writing
     * geonames-resolved place names) and pull() (writing Lingua's MT results) so neither ever
     * blindly truncates the other's already-resolved codes: without this, a push() that resolves
     * "santiago" via geonames would have its result silently wiped by the next pull(), which
     * previously always opened its output file in truncate mode.
     *
     * @param list<array{code: string, locale: string, text: string}> $newRows
     */
    private function mergeTranslationRows(string $path, array $newRows): void
    {
        $rows = [];
        if (is_file($path)) {
            foreach (JsonlReader::open($path) as $row) {
                if (isset($row['code'])) {
                    $rows[(string) $row['code']] = $row;
                }
            }
        }
        foreach ($newRows as $row) {
            $rows[$row['code']] = $row;
        }

        $writer = JsonlWriter::open($path, 'w');
        try {
            foreach ($rows as $row) {
                $writer->write($row);
            }
        } finally {
            $writer->close();
        }
    }
}

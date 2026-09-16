<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\DataContracts\Attribute\PropertyMeta;
use Survos\DataContracts\Metadata\ContentType;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
use Survos\FieldBundle\Attribute\Map;
use Survos\JsonlBundle\IO\JsonlWriter;
use Survos\Lingua\Contracts\Util\TranslatableReflector;
use Survos\Lingua\Core\Identity\HashUtil;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Accumulates translatable phrases from normalized rows and term labels,
 * deduplicated by xxh3(sourceLocale + "\n" + text).
 *
 * Two ways to drive it:
 *   1) Pipeline (event-driven): wired to ImportConvert* events; produces
 *      <dataset>/25_intl/phrases.jsonl when import:convert --stage=normalize
 *      finishes.
 *   2) Direct (replay): DatasetIntlService::extract() calls reset() / accept()
 *      / flush() against existing 20_normalize/*.jsonl without re-running
 *      convert.
 *
 * Internal state is per-run; one extract cycle is bracketed by reset()/flush()
 * or onStart()/onFinish(). Concurrent runs against the same service instance
 * are not supported.
 *
 * Output row shape:
 *   ['code' => xxh3_hex, 'locale' => sourceLocale, 'text' => string, 'sources' => list<string>, 'facet' => bool]
 *
 * `sources` records which DTO fields or term sets contributed the same phrase
 * — useful for debugging / future per-context splitting.
 *
 * Storage is a SQLite spool file per run, not a PHP array. A full newspaper run registers every
 * article's title and OCR description -- 808,254 records for the Rappahannock News 1949-2009 --
 * and holding those texts in memory exhausted the 768 MB normalize worker, which then retried
 * forever. The spool keeps memory flat at any size; the output file and its row order are
 * unchanged (first-seen order, `sources` in first-seen order).
 *
 * Free text is skipped when nothing will ever translate it: an English source with no configured
 * target locales. Harvest's translation registrar only requests non-English phrases, so for such a
 * dataset those rows were written and never read. Facet fields and term labels are always kept.
 *
 * `facet` is true when at least one contributing source is a controlled-vocabulary
 * field (#[PropertyMeta(facet: true)] on the DTO, or a term label via
 * acceptTermLabel()) rather than free text (title, description, ...). Consumed by
 * FolioIngestService::ingestTermTranslations() to skip per-item free-text content
 * that dwarfs the actual term vocabulary at full-archive scale (measured: 90% of
 * mus/fortepan's phrases.hu.jsonl was $description, not $tags, 2026-08-04) — that
 * content is handled by the separate localized-folio-build pipeline instead
 * (folio:build --locale), not this term-label-focused one.
 */
final class PhraseExtractor
{
    private ?\PDO $spool = null;
    private ?string $spoolPath = null;
    private ?\PDOStatement $insertPhrase = null;
    private ?\PDOStatement $insertSource = null;
    private ?string $dataset = null;
    private ?string $sourceLocale = null;
    /** False when free-text fields would never be translated; see the class doc. */
    private bool $freeText = true;

    public function __construct(
        private readonly DataPaths $paths,
        private readonly DatasetInfoRepository $datasets,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // ── Direct API ───────────────────────────────────────────────────────────

    public function reset(string $datasetKey, ?string $sourceLocale = null): void
    {
        $this->discardSpool();
        $this->dataset      = $datasetKey;
        $this->sourceLocale = $sourceLocale ?? $this->resolveSourceLocale($datasetKey);
        $this->freeText     = $this->wantsFreeText($datasetKey, $this->sourceLocale);
    }

    /** @param array<string, mixed> $normalizedRow */
    public function accept(array $normalizedRow): void
    {
        if ($this->sourceLocale === null) {
            return; // reset() not called — silently ignore (e.g. wrong stage)
        }

        $type = $normalizedRow['contentType'] ?? $normalizedRow['content_type'] ?? null;
        if (!is_string($type) || $type === '') {
            return;
        }

        $dtoClass = ContentType::dtoClass($type);
        if (!class_exists($dtoClass)) {
            return;
        }

        foreach (TranslatableReflector::fieldsFor($dtoClass) as $field) {
            $isFacet = $this->isFacetField($dtoClass, $field);
            if (!$isFacet && !$this->freeText) {
                continue;
            }
            $value = $this->resolveMappedValue($normalizedRow, $dtoClass, $field);

            // list<string> fields (e.g. BaseItemDto::$tags) -- each element is its own phrase,
            // registered under the same content hash a term label with identical text would get
            // (HashUtil::calcSourceKey is a pure function of text+locale), so tags that are also
            // extracted as term labels share translations instead of double-requesting them.
            if (is_array($value)) {
                foreach ($value as $item) {
                    if (!is_string($item)) {
                        continue;
                    }
                    $item = trim($item);
                    if ($item !== '') {
                        $this->register($item, $field, $isFacet);
                    }
                }
                continue;
            }

            if (!is_string($value)) {
                continue;
            }
            $text = trim($value);
            if ($text === '') {
                continue;
            }
            $this->register($text, $field, $isFacet);
        }
    }

    /** True for controlled-vocabulary fields (#[PropertyMeta(facet: true)]), false for free text. */
    private function isFacetField(string $dtoClass, string $field): bool
    {
        try {
            $property = new \ReflectionProperty($dtoClass, $field);
        } catch (\ReflectionException) {
            return false;
        }

        foreach ($property->getAttributes(PropertyMeta::class) as $attribute) {
            if ($attribute->newInstance()->facet) {
                return true;
            }
        }

        return false;
    }

    /**
     * A #[Translatable] field's normalized-row key isn't always its own property name --
     * #[Map(source: [...])] declares aliases (e.g. BaseItemDto::$tags reads either 'tags' or
     * the legacy 'source_tags' key a *SetRecordListener still writes). Check each declared
     * alias in priority order before falling back to the bare field name.
     */
    private function resolveMappedValue(array $row, string $dtoClass, string $field): mixed
    {
        foreach ($this->mapSourcesFor($dtoClass, $field) as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return $row[$field] ?? null;
    }

    /** @return list<string> */
    private function mapSourcesFor(string $dtoClass, string $field): array
    {
        try {
            $property = new \ReflectionProperty($dtoClass, $field);
        } catch (\ReflectionException) {
            return [];
        }

        $sources = [];
        foreach ($property->getAttributes(Map::class) as $attribute) {
            $sources = [...$sources, ...$attribute->newInstance()->sources()];
        }

        return $sources;
    }

    /**
     * Accept a controlled-vocabulary term label (e.g. 'oil', 'canvas').
     * Term labels go through the same phrase queue so they get translated once
     * per source language and shared across all rows referencing them.
     */
    public function acceptTermLabel(string $label, ?string $setCode = null): void
    {
        if ($this->sourceLocale === null) {
            return;
        }
        $label = trim($label);
        if ($label === '') {
            return;
        }
        // Always facet=true: a term label is controlled vocabulary by definition.
        $this->register($label, $setCode !== null ? "term:$setCode" : 'term', true);
    }

    /** Writes the accumulator to disk and clears state. Returns count written. */
    public function flush(?string $outFile = null): int
    {
        if ($this->dataset === null) {
            return 0;
        }

        $outFile ??= $this->defaultOutputPath($this->dataset);
        $writer  = JsonlWriter::open($outFile);
        $written = 0;
        try {
            if ($this->spool !== null) {
                $this->spool->commit();
                // Aggregate ORDER BY (SQLite 3.44+) keeps each phrase's sources in first-seen order.
                $rows = $this->spool->query(
                    'SELECT p.code, p.locale, p.text, p.facet, '
                    . '(SELECT json_group_array(s.source ORDER BY s.rowid) FROM phrase_source s WHERE s.code = p.code) AS sources '
                    . 'FROM phrase p ORDER BY p.rowid'
                );
                foreach ($rows as $row) {
                    $writer->write([
                        'code'    => $row['code'],
                        'locale'  => $row['locale'],
                        'text'    => $row['text'],
                        'sources' => json_decode((string) $row['sources'], true, flags: JSON_THROW_ON_ERROR),
                        'facet'   => (bool) $row['facet'],
                    ]);
                    $written++;
                }
            }
        } finally {
            $writer->close();
        }

        $this->logger?->info('Phrase extraction complete', [
            'dataset' => $this->dataset,
            'phrases' => $written,
            'path'    => $outFile,
        ]);

        $this->discardSpool();
        $this->dataset      = null;
        $this->sourceLocale = null;

        return $written;
    }

    public function count(): int
    {
        return $this->spool === null ? 0 : (int) $this->spool->query('SELECT COUNT(*) FROM phrase')->fetchColumn();
    }

    public function __destruct()
    {
        $this->discardSpool();
    }

    // ── Event API (pipeline wiring) ──────────────────────────────────────────

    #[AsEventListener(event: ImportConvertStartedEvent::class)]
    public function onStart(ImportConvertStartedEvent $event): void
    {
        if (!$event->dataset) {
            return;
        }
        // Stage isn't on this event — defer the decision to onRow.
        // We seed dataset early so onRow can lazy-init source locale on the first qualifying row.
        $this->discardSpool();
        $this->dataset      = $event->dataset;
        $this->sourceLocale = null;
    }

    #[AsEventListener(event: ImportConvertRowEvent::class)]
    public function onRow(ImportConvertRowEvent $event): void
    {
        // Phrase extraction is a dataset-only feature; a bare-file convert
        // (no dataset) has nowhere to write phrases, so do nothing.
        if (!$event->dataset || !Stage::isNormalized($event) || $event->row === null) {
            return;
        }
        // The row event is the source of truth for the dataset: onStart may have
        // bailed (empty started-event dataset) while rows carry an inferred key.
        $this->dataset ??= $event->dataset;
        if ($this->sourceLocale === null) {
            $this->sourceLocale = $this->resolveSourceLocale($event->dataset);
            $this->freeText     = $this->wantsFreeText($event->dataset, $this->sourceLocale);
        }
        $this->accept($event->row);
    }

    #[AsEventListener(event: ImportConvertFinishedEvent::class)]
    public function onFinish(ImportConvertFinishedEvent $event): void
    {
        if ($this->sourceLocale === null) {
            // No qualifying rows seen — nothing to do.
            $this->discardSpool();
            $this->dataset = null;
            return;
        }
        $this->flush();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function register(string $text, string $source, bool $isFacet): void
    {
        $this->openSpool();
        $code = HashUtil::calcSourceKey($text, $this->sourceLocale);
        // First text wins (identical text by definition). Identical text can come from both a
        // facet field and free text (e.g. a tag value that also appears in a description) --
        // facet is true if any source is a facet field.
        $this->insertPhrase->execute([$code, $this->sourceLocale, $text, (int) $isFacet]);
        $this->insertSource->execute([$code, $source]);
    }

    /** One spool file per run, beside the output, created on the first phrase. */
    private function openSpool(): void
    {
        if ($this->spool !== null) {
            return;
        }
        $dir = $this->paths->stageDir((string) $this->dataset, Stage::Intl->value, create: true);
        $this->spoolPath = rtrim($dir, '/') . '/.phrases-spool-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->spool = new \PDO('sqlite:' . $this->spoolPath, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        // A scratch file rebuilt on every run: no journal, no fsync.
        $this->spool->exec('PRAGMA journal_mode = OFF');
        $this->spool->exec('PRAGMA synchronous = OFF');
        $this->spool->exec('CREATE TABLE phrase (code TEXT PRIMARY KEY, locale TEXT NOT NULL, text TEXT NOT NULL, facet INTEGER NOT NULL)');
        $this->spool->exec('CREATE TABLE phrase_source (code TEXT NOT NULL, source TEXT NOT NULL, UNIQUE (code, source))');
        $this->insertPhrase = $this->spool->prepare(
            'INSERT INTO phrase (code, locale, text, facet) VALUES (?, ?, ?, ?) '
            . 'ON CONFLICT (code) DO UPDATE SET facet = phrase.facet OR excluded.facet'
        );
        $this->insertSource = $this->spool->prepare('INSERT OR IGNORE INTO phrase_source (code, source) VALUES (?, ?)');
        $this->spool->beginTransaction();
    }

    private function discardSpool(): void
    {
        $this->insertPhrase = null;
        $this->insertSource = null;
        $this->spool = null;
        if ($this->spoolPath !== null && is_file($this->spoolPath)) {
            @unlink($this->spoolPath);
        }
        $this->spoolPath = null;
    }

    /**
     * Whether free-text fields are worth extracting. Only an English source with no configured
     * target locales is skipped: nothing translates English automatically (harvest's
     * DatasetTranslationRegistrar requests non-English phrases only), and with no targets
     * dataset:intl:push has nothing it was asked to do. Any other source keeps free text, because
     * translating it into English happens whether or not targets are configured.
     */
    private function wantsFreeText(string $datasetKey, string $sourceLocale): bool
    {
        if (strtolower(explode('-', str_replace('_', '-', $sourceLocale))[0]) !== 'en') {
            return true;
        }
        $targets = $this->datasets->find($datasetKey)?->targetLocales ?: ($this->metaLocale($datasetKey)['targets'] ?? []);
        if ($targets === []) {
            $this->logger?->info('phrase extract [{dataset}]: English source with no target locales; free text skipped, facets and terms kept.', ['dataset' => $datasetKey]);
        }

        return $targets !== [];
    }

    /**
     * The source locale decides the phrases.<locale>.jsonl filename, the `locale` on every phrase,
     * and the hash each phrase is stored under (HashUtil::calcSourceKey is a pure function of
     * text + locale). Get it wrong and the whole round trip silently no-ops: Lingua is asked to
     * translate en→en, returns nothing, and dataset:intl:pull reports "0 translated, N missing"
     * long after the push claimed success.
     *
     * That is not hypothetical — it is exactly what happened to 1,250 musdig datasets. The
     * registry's DatasetInfo::$locale is empty for that whole provider (agg:musdig:meta writes
     * locale.default into _meta/dataset.json but never populates the entity), so the old
     * `?: 'en'` shipped Hungarian text to Lingua labelled English.
     *
     * Hence two sources before any fallback, and a warning rather than a shrug when neither
     * knows. Deliberately NOT throwing: this also runs inside the normalize row listener, and a
     * hard failure here would take out the whole pipeline for any dataset that legitimately has
     * no recorded locale. Loud and recoverable beats silent or fatal.
     */
    private function resolveSourceLocale(string $datasetKey): string
    {
        $locale = $this->datasets->find($datasetKey)?->locale;
        if (is_string($locale) && $locale !== '') {
            return $locale;
        }

        // The provider writes _meta/dataset.json; the registry is only supposed to mirror it.
        // When they disagree, the file on disk is the one that came from the source.
        $fromMeta = $this->localeFromMeta($datasetKey);
        if ($fromMeta !== null) {
            $this->logger?->warning(
                'phrase extract [{dataset}]: DatasetInfo.locale is empty; using locale.default "{locale}" '
                . 'from _meta/dataset.json. The registry is stale for this dataset.',
                ['dataset' => $datasetKey, 'locale' => $fromMeta],
            );

            return $fromMeta;
        }

        $this->logger?->error(
            'phrase extract [{dataset}]: no source locale in DatasetInfo or _meta/dataset.json — '
            . 'falling back to "en". If this dataset is not English its phrases will be pushed to '
            . 'Lingua mislabelled and every translation will come back missing.',
            ['dataset' => $datasetKey],
        );

        return 'en';
    }

    /** locale.default from the dataset's own _meta/dataset.json, or null when unreadable. */
    private function localeFromMeta(string $datasetKey): ?string
    {
        $locale = $this->metaLocale($datasetKey)['default'] ?? null;

        return is_string($locale) && $locale !== '' ? $locale : null;
    }

    /** The `locale` block of the dataset's own _meta/dataset.json, or [] when unreadable. */
    private function metaLocale(string $datasetKey): array
    {
        $file = rtrim($this->paths->stageDir($datasetKey, Stage::Meta), '/') . '/dataset.json';
        if (!is_file($file)) {
            return [];
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        // DatasetMetadataEnsurer writes {"dataset": {"locale": {...}}}; older files had it at the top.
        $locale = is_array($data) ? ($data['dataset']['locale'] ?? $data['locale'] ?? null) : null;

        return is_array($locale) ? $locale : [];
    }

    private function defaultOutputPath(string $datasetKey): string
    {
        $dir = $this->paths->stageDir($datasetKey, Stage::Intl->value, create: true);
        return rtrim($dir, '/') . '/phrases.' . $this->sourceLocale . '.jsonl';
    }
}

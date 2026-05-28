<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Psr\Log\LoggerInterface;
use Survos\DataContracts\Metadata\ContentType;
use Survos\DatasetBundle\Enum\Stage;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\ImportBundle\Event\ImportConvertFinishedEvent;
use Survos\ImportBundle\Event\ImportConvertRowEvent;
use Survos\ImportBundle\Event\ImportConvertStartedEvent;
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
 *   ['code' => xxh3_hex, 'locale' => sourceLocale, 'text' => string, 'sources' => list<string>]
 *
 * `sources` records which DTO fields or term sets contributed the same phrase
 * — useful for debugging / future per-context splitting; ignored by downstream
 * consumers today.
 */
final class PhraseExtractor
{
    /** @var array<string, array{locale:string, text:string, sources:list<string>}> hash => row */
    private array $phrases = [];
    private ?string $dataset = null;
    private ?string $sourceLocale = null;

    public function __construct(
        private readonly DataPaths $paths,
        private readonly DatasetInfoRepository $datasets,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    // ── Direct API ───────────────────────────────────────────────────────────

    public function reset(string $datasetKey, ?string $sourceLocale = null): void
    {
        $this->phrases      = [];
        $this->dataset      = $datasetKey;
        $this->sourceLocale = $sourceLocale ?? $this->resolveSourceLocale($datasetKey);
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
            $text = $normalizedRow[$field] ?? null;
            if (!is_string($text)) {
                continue;
            }
            $text = trim($text);
            if ($text === '') {
                continue;
            }
            $this->register($text, $field);
        }
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
        $this->register($label, $setCode !== null ? "term:$setCode" : 'term');
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
            foreach ($this->phrases as $code => $row) {
                $writer->write(['code' => $code] + $row);
                $written++;
            }
        } finally {
            $writer->close();
        }

        $this->logger?->info('Phrase extraction complete', [
            'dataset' => $this->dataset,
            'phrases' => $written,
            'path'    => $outFile,
        ]);

        $this->phrases      = [];
        $this->dataset      = null;
        $this->sourceLocale = null;

        return $written;
    }

    public function count(): int
    {
        return \count($this->phrases);
    }

    // ── Event API (pipeline wiring) ──────────────────────────────────────────

    #[AsEventListener(event: ImportConvertStartedEvent::class)]
    public function onStart(ImportConvertStartedEvent $event): void
    {
        if ($event->dataset === '') {
            return;
        }
        // Stage isn't on this event — defer the decision to onRow.
        // We seed dataset early so onRow can lazy-init source locale on the first qualifying row.
        $this->dataset      = $event->dataset;
        $this->sourceLocale = null;
        $this->phrases      = [];
    }

    #[AsEventListener(event: ImportConvertRowEvent::class)]
    public function onRow(ImportConvertRowEvent $event): void
    {
        if (!Stage::isNormalized($event) || $event->row === null) {
            return;
        }
        // $this->dataset is guaranteed non-null by onStart, which fires first.
        // Let the type system surface a violation rather than silently dropping rows.
        $this->sourceLocale ??= $this->resolveSourceLocale($this->dataset);
        $this->accept($event->row);
    }

    #[AsEventListener(event: ImportConvertFinishedEvent::class)]
    public function onFinish(ImportConvertFinishedEvent $event): void
    {
        if ($this->sourceLocale === null) {
            // No qualifying rows seen — nothing to do.
            $this->phrases = [];
            $this->dataset = null;
            return;
        }
        $this->flush();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function register(string $text, string $source): void
    {
        $code = HashUtil::calcSourceKey($text, $this->sourceLocale);
        if (!isset($this->phrases[$code])) {
            $this->phrases[$code] = [
                'locale'  => $this->sourceLocale,
                'text'    => $text,
                'sources' => [$source],
            ];
            return;
        }

        if (!in_array($source, $this->phrases[$code]['sources'], true)) {
            $this->phrases[$code]['sources'][] = $source;
        }
    }

    private function resolveSourceLocale(string $datasetKey): string
    {
        $info   = $this->datasets->find($datasetKey);
        $locale = $info?->locale;
        return is_string($locale) && $locale !== '' ? $locale : 'en';
    }

    private function defaultOutputPath(string $datasetKey): string
    {
        $dir = $this->paths->stageDir($datasetKey, Stage::Intl->value, create: true);
        return rtrim($dir, '/') . '/phrases.' . $this->sourceLocale . '.jsonl';
    }
}

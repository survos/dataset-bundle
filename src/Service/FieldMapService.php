<?php
declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Reads config/field_map.yaml and applies the incoming field map to
 * normalize any aggregator's raw/normalized record into canonical DC terms.
 *
 * Usage:
 *   $canonical = $fieldMap->apply('dc', $rawRecord);
 *   // Returns ['dcterms:title' => '...', 'dcterms:subject' => [...], ...]
 *
 *   $missing = $fieldMap->missingAiFields('pp', $canonical);
 *   // Returns fields that are null and have ai_extract: true — feed to AI
 */
final class FieldMapService
{
    /** @var array<string, mixed> */
    private array $map;

    public function __construct(private readonly string $mapFile)
    {
        $this->map = Yaml::parseFile($mapFile) ?? [];
    }

    /**
     * Map a raw/normalized record from $aggregator to canonical DC terms.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>  keyed by dc_term e.g. 'dcterms:title'
     */
    public function apply(string $aggregator, array $record): array
    {
        $canonical = [];

        foreach ($this->map['fields'] ?? [] as $fieldName => $fieldDef) {
            $dcTerm = $fieldDef['dc_term'] ?? null;
            if (!$dcTerm || $dcTerm === '~') {
                continue;
            }

            $sourceKey = $fieldDef['sources'][$aggregator] ?? null;
            if (!$sourceKey || $sourceKey === '~') {
                continue;
            }

            // Support dot-notation for nested: "media[0].url" → skip (handled by normalizer)
            if (str_contains($sourceKey, '.') || str_contains($sourceKey, '[')) {
                continue;
            }

            $value = $record[$sourceKey] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            // Coerce to declared type
            $value = $this->coerce($value, $fieldDef['type'] ?? 'text');
            if ($value === null) {
                continue;
            }

            // Multiple fields can map to the same dc_term (e.g. subject + subject_name → dcterms:subject)
            if (isset($canonical[$dcTerm]) && is_array($canonical[$dcTerm]) && is_array($value)) {
                $canonical[$dcTerm] = array_unique(array_merge($canonical[$dcTerm], $value));
            } else {
                $canonical[$dcTerm] = $value;
            }
        }

        return $canonical;
    }

    /**
     * Return the list of field names that are missing from $canonical
     * and have ai_extract: true, ordered by priority.
     *
     * @param array<string, mixed> $canonical  Output of apply()
     * @return string[]  field names (not dc_terms)
     */
    public function missingAiFields(string $aggregator, array $canonical): array
    {
        $priorities = $this->map['ai_extraction']['fields_by_priority'] ?? [];
        $overrides  = $this->map['ai_extraction']['aggregator_overrides'][$aggregator]['high'] ?? null;
        $never      = $this->map['ai_extraction']['never_extract'] ?? [];

        // Build ordered list: aggregator overrides first, then high/medium/low
        $ordered = array_merge(
            $overrides ?? $priorities['high'] ?? [],
            $priorities['medium'] ?? [],
            $priorities['low'] ?? [],
        );

        $missing = [];
        foreach (array_unique($ordered) as $fieldName) {
            if (in_array($fieldName, $never, true)) {
                continue;
            }
            $fieldDef = $this->map['fields'][$fieldName] ?? null;
            if ($fieldDef === null || !($fieldDef['ai_extract'] ?? false)) {
                continue;
            }
            $dcTerm = $fieldDef['dc_term'] ?? null;
            if ($dcTerm && ($canonical[$dcTerm] ?? null) !== null) {
                continue; // already populated
            }
            $missing[] = $fieldName;
        }

        return $missing;
    }

    /**
     * Build the AI extraction prompt for fields missing from $canonical.
     * Returns null if nothing is missing.
     */
    public function buildAiPrompt(string $aggregator, array $canonical, ?string $contextText = null): ?string
    {
        $missing = $this->missingAiFields($aggregator, $canonical);
        if ($missing === []) {
            return null;
        }

        $preamble = $this->map['ai_extraction']['prompt_preamble'] ?? '';
        $aggNote  = $this->map['ai_extraction']['aggregator_overrides'][$aggregator]['note'] ?? null;

        $fieldLines = [];
        foreach ($missing as $fieldName) {
            $def   = $this->map['fields'][$fieldName] ?? [];
            $label = $def['label'] ?? $fieldName;
            $type  = $def['type'] ?? 'text';
            $vals  = isset($def['values']) ? ' (one of: ' . implode(', ', $def['values']) . ')' : '';
            $fieldLines[] = "  - {$label} [{$type}{$vals}] → \"{$fieldName}\"";
        }

        $context = $contextText ? "\n\nContent to analyze:\n{$contextText}" : '';
        $note    = $aggNote ? "\n\nNote: {$aggNote}" : '';

        return trim($preamble)
            . $note
            . "\n\nExtract these fields:\n"
            . implode("\n", $fieldLines)
            . $context
            . "\n\nReturn JSON with field names as keys.";
    }

    /**
     * Get the dc_term for a normalized field name.
     */
    public function dcTerm(string $fieldName): ?string
    {
        return $this->map['fields'][$fieldName]['dc_term'] ?? null;
    }

    /**
     * Get all field definitions.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->map['fields'] ?? [];
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function coerce(mixed $value, string $type): mixed
    {
        return match ($type) {
            'array'   => is_array($value) ? array_values(array_filter(array_map('trim', $value))) : [trim((string) $value)],
            'text'    => is_array($value) ? implode('; ', $value) : trim((string) $value),
            'date'    => $this->coerceDate($value),
            'numeric' => is_numeric($value) ? (float) $value : null,
            'uri'     => filter_var($value, FILTER_VALIDATE_URL) ? $value : null,
            'select'  => is_array($value) ? ($value[0] ?? null) : $value,
            default   => $value,
        };
    }

    private function coerceDate(mixed $value): ?string
    {
        $str = is_array($value) ? ($value[0] ?? '') : (string) $value;
        $str = trim($str);
        // Already ISO-ish
        if (preg_match('/^\d{4}(-\d{2}(-\d{2})?)?/', $str)) {
            return $str;
        }
        // Try to parse fuzzy dates
        try {
            return (new \DateTime($str))->format('Y-m-d');
        } catch (\Throwable) {
            return $str ?: null; // keep as string if unparseable
        }
    }
}

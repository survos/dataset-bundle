<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Enum;

/**
 * Canonical pipeline stages for a dataset under APP_DATA_DIR/work/<provider>/<code>/.
 *
 * Single source of truth for stage identity AND on-disk dir names:
 *   - the backed value is the stable semantic key (events + import:convert --stage)
 *   - dir() is the ONLY place work-tree directory names live (see docs/data-layout.md)
 *
 * dir() rules:
 *   - no numeric prefixes; names sort in pipeline order
 *   - "_"-prefixed dirs are TIER PORTALS (config / vault / folio), often symlinks
 *   - AI claims are NOT a work stage — they live in the durable vault
 *     (DataPaths::aiDir()). Stage::Ai exists only for --stage=ai / event matching;
 *     dir() throws for it.
 *
 * Reference Stage cases in code, never the raw strings. fromKey() is the single,
 * fail-loud string boundary (CLI / events); add aliases there deliberately.
 */
enum Stage: string
{
    case Meta      = 'meta';
    case Raw       = 'raw';
    case Extract   = 'extract';
    case Normalize = 'normalize';
    case Intl      = 'intl';
    case Terms     = 'terms';
    case Ai        = 'ai';
    case Enrich    = 'enrich';   // the "assemble" step → _folio

    /** Work-tree directory name. Throws for stages with no work dir (Ai → vault). */
    public function dir(): string
    {
        return match ($this) {
            self::Meta      => '_meta',
            self::Raw       => '_raw',
            self::Extract   => 'extract',
            self::Normalize => 'norm',
            self::Intl      => 'trans',
            self::Terms     => 'voc',
            self::Enrich    => '_folio',
            self::Ai        => throw new \LogicException('AI claims live in the vault; use DataPaths::aiDir(), not a work stage dir.'),
        };
    }

    /**
     * Resolve a string to a Stage. Unknown → throw (fail loud, fix the caller).
     * Add aliases below only deliberately.
     */
    public static function fromKey(string $key): self
    {
        $k = strtolower(trim($key, '/'));

        return self::tryFrom($k) ?? match ($k) {
            'normalized'           => self::Normalize,
            'enriched', 'assemble' => self::Enrich,
            default => throw new \InvalidArgumentException(
                sprintf('Unknown stage "%s". Reference a Stage case; do not pass raw strings.', $key)
            ),
        };
    }

    /**
     * True when $event->stage equals this case's string value. Tolerant of
     * missing or non-string `stage` properties so callers don't have to guard.
     */
    public function matches(object $event): bool
    {
        return property_exists($event, 'stage')
            && is_string($event->stage)
            && $event->stage === $this->value;
    }

    /** Shortcut for the most commonly-checked stage in pipeline listeners. */
    public static function isNormalized(object $event): bool
    {
        return self::Normalize->matches($event);
    }
}

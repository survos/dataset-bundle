<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Enum;

/**
 * Canonical pipeline stages for a dataset under APP_DATA_DIR/work/<provider>/<dataset>/.
 *
 * Values match the semantic keys consumed by DataPaths::stageDir() and the
 * --stage option on import:convert. The directory names (e.g. 20_normalize)
 * are an implementation detail of DataPaths::stageMap.
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
    case Enrich    = 'enrich';

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

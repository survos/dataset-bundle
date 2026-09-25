<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Runs WAL-mode + integrity pragmas on every new SQLite connection.
 *
 * WAL:              concurrent readers + writer without lock contention.
 * busy_timeout:     wait up to 30 s before "database is locked".
 * synchronous=NORMAL: safe durability, cheaper than FULL.
 * foreign_keys=ON:  SQLite doesn't enforce FKs (incl. onDelete: CASCADE) unless told to per
 *                   connection — without this, deleting a DatasetInfo row silently leaves
 *                   orphaned Artifact rows behind instead of cascading or refusing the delete.
 *
 * Register as a doctrine.middleware service (see SurvosDatasetBundle).
 */
final class SqliteWalMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);

                // Folio connections are left to folio-bundle's FolioConnectionWrapper, which sets WAL
                // only for writes. Asserting it here on connect wrote WAL into every folio a
                // request merely read -- ~90 a day on fsn1 from zm's web workers alone -- and a WAL
                // folio cannot be opened from a read-only mount (inkstory.org, 2026-09-24).
                $isFolio = is_a((string) ($params['wrapperClass'] ?? ''), 'Survos\\FolioBundle\\DBAL\\FolioConnectionWrapper', true);
                if (!$isFolio && str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
                    // Published folio readers explicitly open SQLite in mode=ro.
                    if (!($params['readOnly'] ?? false)) {
                        $connection->exec('PRAGMA journal_mode=WAL');
                        $connection->exec('PRAGMA synchronous=NORMAL');
                    }
                    $connection->exec('PRAGMA busy_timeout=30000');
                    $connection->exec('PRAGMA foreign_keys=ON');
                }

                return $connection;
            }
        };
    }
}

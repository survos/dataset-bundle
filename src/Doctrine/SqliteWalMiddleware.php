<?php

declare(strict_types=1);

namespace Survos\DataBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Runs WAL-mode pragmas on every new SQLite connection.
 *
 * WAL:              concurrent readers + writer without lock contention.
 * busy_timeout:     wait up to 30 s before "database is locked".
 * synchronous=NORMAL: safe durability, cheaper than FULL.
 *
 * Register as a doctrine.middleware service (see SurvosDataBundle).
 */
final class SqliteWalMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): Connection
            {
                $connection = parent::connect($params);

                if (str_contains((string) ($params['driver'] ?? ''), 'sqlite')) {
                    $connection->exec('PRAGMA journal_mode=WAL');
                    $connection->exec('PRAGMA busy_timeout=30000');
                    $connection->exec('PRAGMA synchronous=NORMAL');
                }

                return $connection;
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Survos\DataBundle\DBAL;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

final class MultiDbConnectionWrapper extends Connection implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private string $currentDatabase;

    public function __construct(
        array $params,
        Driver $driver,
        ?Configuration $config = null,
    ) {
        parent::__construct($params, $driver, $config);
        $this->currentDatabase = (string) ($params['dbname'] ?? '');
    }

    public function getCurrentDatabase(): string
    {
        return $this->currentDatabase;
    }

    public function selectDatabase(string $dbName): void
    {
        if ($this->currentDatabase === $dbName) {
            return;
        }

        if ($this->isConnected()) {
            $this->close();
        }

        $params = $this->getParams();
        $params['dbname'] = $dbName;
        $this->currentDatabase = $dbName;

        parent::__construct($params, $this->getDriver(), $this->_config);
    }
}

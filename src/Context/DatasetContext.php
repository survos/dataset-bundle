<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Context;

use Survos\ImportBundle\Contract\DatasetContextInterface;
use Symfony\Contracts\Service\ResetInterface;

use function trim;

trait DatasetContextBehavior
{
    private ?string $dataset = null;

    public function set(string $dataset): void
    {
        $dataset = trim($dataset);
        if ($dataset === '') {
            throw new \InvalidArgumentException('Dataset cannot be empty.');
        }

        $this->dataset = $dataset;
    }

    public function has(): bool
    {
        return $this->dataset !== null;
    }

    public function getOrNull(): ?string
    {
        return $this->dataset;
    }

    public function get(): string
    {
        if ($this->dataset === null) {
            throw new \RuntimeException('Dataset is not set.');
        }

        return $this->dataset;
    }

    /**
     * Request-scoped: under FrankenPHP worker mode the holder outlives the response, so a
     * request that never calls set() would otherwise read the previous request's dataset --
     * and has() would answer true when nothing set it.
     */
    public function reset(): void
    {
        $this->dataset = null;
    }
}

if (interface_exists(DatasetContextInterface::class)) {
    final class DatasetContext implements DatasetContextInterface, ResetInterface
    {
        use DatasetContextBehavior;
    }
} else {
    final class DatasetContext implements ResetInterface
    {
        use DatasetContextBehavior;
    }
}

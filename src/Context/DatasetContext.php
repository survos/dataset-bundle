<?php
declare(strict_types=1);

namespace Survos\DataBundle\Context;

use Survos\ImportBundle\Contract\DatasetContextInterface;

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
}

if (interface_exists(DatasetContextInterface::class)) {
    final class DatasetContext implements DatasetContextInterface
    {
        use DatasetContextBehavior;
    }
} else {
    final class DatasetContext
    {
        use DatasetContextBehavior;
    }
}

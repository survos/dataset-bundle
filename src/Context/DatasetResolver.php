<?php
declare(strict_types=1);

namespace Survos\DataBundle\Context;

use Survos\DataBundle\Service\DataPaths;
use Symfony\Component\Console\Input\InputInterface;

use function getenv;
use function is_string;
use function trim;

final class DatasetResolver
{
    public const array ENV_KEYS = ['DATASET', 'CODE', 'TENANT'];

    public function __construct(
        private readonly DataPaths $dataPaths,
    ) {
    }

    public function resolve(InputInterface $input): ?string
    {
        $dataset = $this->resolveFromInput($input);
        if ($dataset !== null) {
            return $dataset;
        }

        return $this->resolveFromEnvironment();
    }

    public function resolveFromInput(InputInterface $input): ?string
    {
        foreach (['dataset', 'code', 'tenant'] as $option) {
            if (!$input->hasOption($option)) {
                continue;
            }

            $value = $input->getOption($option);
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            return $this->dataPaths->datasetKeyFromRef($value);
        }

        return null;
    }

    public function resolveFromEnvironment(): ?string
    {
        foreach (self::ENV_KEYS as $envVar) {
            $value = getenv($envVar);
            if (!is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            return $this->dataPaths->datasetKeyFromRef($value);
        }

        return null;
    }
}

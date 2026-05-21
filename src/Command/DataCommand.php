<?php
declare(strict_types=1);

namespace Survos\DataBundle\Command;

use Survos\DataBundle\Service\DataPaths;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Base command for any app using data-bundle.
 *
 * #[Required] on the setter means Symfony's DI container calls it
 * automatically after construction — no need to declare DataPaths
 * in every command's constructor.
 *
 * Commands that need APP_DATA_DIR paths should extend this.
 */
abstract class DataCommand
{
    protected DataPaths $dataPaths;

    #[Required]
    public function setDataPaths(DataPaths $dataPaths): void
    {
        $this->dataPaths = $dataPaths;
    }
}

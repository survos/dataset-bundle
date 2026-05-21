<?php
declare(strict_types=1);

namespace Survos\DataBundle\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

final class ProviderSnapshot
{
    #[Groups(['provider:snapshot'])]
    public ?string $code = null;
    #[Groups(['provider:snapshot'])]
    public ?string $label = null;
    #[Groups(['provider:snapshot'])]
    public ?string $description = null;
    #[Groups(['provider:snapshot'])]
    public ?string $homepage = null;
    #[Groups(['provider:snapshot'])]
    public ?string $logo = null;
    #[Groups(['provider:snapshot'])]
    public ?int $approxInstCount = null;
    #[Groups(['provider:snapshot'])]
    public ?int $approxObjCount = null;
    #[Groups(['provider:snapshot'])]
    public ?string $defaultLocale = null;
    #[Groups(['provider:snapshot'])]
    public ?string $dataReuse = null;
    #[Groups(['provider:snapshot'])]
    public ?string $termsUrl = null;
    #[Groups(['provider:snapshot'])]
    public ?string $entityProviderClass = null;
}

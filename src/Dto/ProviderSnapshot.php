<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Dto;

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

    // ── Operational metadata ──────────────────────────────────────────────────────
    // Carried so provider.json is a complete description of how a provider is administered,
    // not just how it is labelled. Before these existed the answers lived only in the app's
    // #[Aggregator] attribute, invisible to anything reading the registry.

    /** FQCN of the #[Aggregator]-annotated adapter class. */
    #[Groups(['provider:snapshot'])]
    public ?string $adapterClass = null;
    /** Which authority key is the provider's primary item, e.g. "object". */
    #[Groups(['provider:snapshot'])]
    public ?string $primaryItem = null;
    /** @var array<string, class-string> emit key => entity class */
    #[Groups(['provider:snapshot'])]
    public array $authorities = [];
    /** Raw lives in the vault and is expensive to regenerate. */
    #[Groups(['provider:snapshot'])]
    public bool $durableRaw = false;
    /** Raw core is gzip-compressed. */
    #[Groups(['provider:snapshot'])]
    public bool $compressedRaw = false;
    /** Description works as a fallback for institutions/collections within the provider. */
    #[Groups(['provider:snapshot'])]
    public bool $descriptionIsContentFallback = true;
    /** When and how _meta/dataset.json is created. */
    #[Groups(['provider:snapshot'])]
    public ?string $metaCreation = null;
    /** How raw is acquired from source, including any provider-wide capture step. */
    #[Groups(['provider:snapshot'])]
    public ?string $rawAcquisition = null;
    /** Capture location when the provider has a distinct capture step. */
    #[Groups(['provider:snapshot'])]
    public ?string $capturePath = null;
    /** Console command that loads this provider's vault. */
    #[Groups(['provider:snapshot'])]
    public ?string $vaultCommand = null;
    /** Prerequisites, cost and gotchas for the vault step. */
    #[Groups(['provider:snapshot'])]
    public ?string $vaultNotes = null;
    /** @var list<array{name: string, description: string}> every console command scoped to this provider */
    #[Groups(['provider:snapshot'])]
    public array $providerCommands = [];
}

<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;

/**
 * Provider entity - represents a data provider like Smithsonian, NARA, DC, etc.
 * 
 * In data-bundle:
 *   data/{provider}/provider.json    ← persisted from #[Aggregator] attribute
 *   data/{provider}/{code}/         ← datasets
 */
#[ORM\Entity(repositoryClass: \Survos\DatasetBundle\Repository\ProviderRepository::class)]
#[ORM\Table(name: 'provider')]
#[RouteIdentity(field: 'code')]
class Provider implements RouteParametersInterface, \Stringable
{
    use RouteIdentityTrait;

    /** Has an adapter class and at least one discovered dataset. */
    public const STAGE_ACTIVE = 'active';
    /** Has an adapter class but no datasets yet — registered, never harvested. */
    public const STAGE_IDLE = 'idle';
    /** A row with no adapter class: left behind by a removed or renamed provider. */
    public const STAGE_ORPHAN = 'orphan';
    #[ORM\Id]
    #[ORM\Column(length: 32)]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $homepage = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $logo = null;

    #[ORM\Column(nullable: true)]
    private ?int $approxInstCount = null;

    #[ORM\Column(nullable: true)]
    private ?int $approxObjCount = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $defaultLocale = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $dataReuse = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $termsUrl = null;

    #[ORM\Column(nullable: true)]
    private ?int $datasetCount = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $syncedAt = null;

    /**
     * FQCN of the application's #[Aggregator]-annotated provider class, recorded by the app's
     * agg:sync. Null means no adapter claims this code — an orphan row left behind by a renamed
     * or removed provider, which is why it is worth storing rather than inferring.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $adapterClass = null;

    /** Provider-specific working entity used during discovery/enrichment, e.g. App\Entity\Dc. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $entityProviderClass = null;

    /** Which authority key is this provider's primary item, e.g. "object". */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $primaryItem = null;

    /** @var array<string, class-string> emit key => entity class */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $authorities = [];

    /** Raw is expensive to regenerate, so it lives in the vault and work/<code>/_raw is a portal. */
    #[ORM\Column(options: ['default' => false])]
    private bool $durableRaw = false;

    /** Raw core is <code>.jsonl.gz rather than plain <code>.jsonl. */
    #[ORM\Column(options: ['default' => false])]
    private bool $compressedRaw = false;

    /** Whether the provider description works as a fallback for its institutions/collections. */
    #[ORM\Column(options: ['default' => true])]
    private bool $descriptionIsContentFallback = true;

    /** When and how _meta/dataset.json is created for this provider. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $metaCreation = null;

    /** How raw is acquired from source, including any provider-wide capture step. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $rawAcquisition = null;

    /** Capture location when the provider has a distinct capture step. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $capturePath = null;

    /**
     * Cached count of datasets that are known upstream but not acquired — the workflow's initial
     * `new` place, whose metadata calls it "candidate". Refreshed by dataset:scan.
     */
    #[ORM\Column(nullable: true)]
    private ?int $candidateCount = null;

    /** @var array<string, int> dataset status => count, refreshed by dataset:scan. */
    #[ORM\Column(type: 'json', options: ['default' => '{}'])]
    private array $datasetStatusCounts = [];

    /**
     * Last time dataset:scan walked this provider's data directory. Distinct from syncedAt, which
     * records when the provider.json snapshot was last applied — the question "are these counts
     * current?" is not the question "is this metadata current?".
     */
    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTime $lastScanAt = null;

    /**
     * The console command that loads this provider's vault. Vault loading is the hardest step to
     * administer and its command names are not uniform (agg:/dataset:/harvest:/provider:
     * namespaces all appear), so the name is recorded rather than derived.
     */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $vaultCommand = null;

    /** Prerequisites, cost and gotchas for the vault step, shown verbatim to whoever runs it. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $vaultNotes = null;

    /**
     * Every console command scoped to this provider (any `<ns>:<code>:<verb>`), discovered by
     * agg:sync. This is the provider's runbook: what can be run, in one place, without grepping
     * #[AsCommand] attributes across src/.
     *
     * @var list<array{name: string, description: string}>
     */
    #[ORM\Column(type: 'json', options: ['default' => '[]'])]
    private array $providerCommands = [];

    /** @var Collection<int, DatasetInfo> */
    #[ORM\OneToMany(mappedBy: 'providerEntity', targetEntity: DatasetInfo::class)]
    private Collection $datasets;

    public function __construct(string $code)
    {
        $this->code = $code;
        $this->datasets = new ArrayCollection();
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setHomepage(?string $homepage): self
    {
        $this->homepage = $homepage;
        return $this;
    }

    public function getHomepage(): ?string
    {
        return $this->homepage;
    }

    public function setLogo(?string $logo): self
    {
        $this->logo = $logo;
        return $this;
    }

    public function getLogo(): ?string
    {
        return $this->logo;
    }

    public function setApproxInstCount(?int $count): self
    {
        $this->approxInstCount = $count;
        return $this;
    }

    public function getApproxInstCount(): ?int
    {
        return $this->approxInstCount;
    }

    public function setApproxObjCount(?int $count): self
    {
        $this->approxObjCount = $count;
        return $this;
    }

    public function getApproxObjCount(): ?int
    {
        return $this->approxObjCount;
    }

    public function setDefaultLocale(?string $locale): self
    {
        $this->defaultLocale = $locale;
        return $this;
    }

    public function getDefaultLocale(): ?string
    {
        return $this->defaultLocale;
    }

    public function setDataReuse(?string $dataReuse): self
    {
        $this->dataReuse = $dataReuse;
        return $this;
    }

    public function getDataReuse(): ?string
    {
        return $this->dataReuse;
    }

    public function setTermsUrl(?string $termsUrl): self
    {
        $this->termsUrl = $termsUrl;
        return $this;
    }

    public function getTermsUrl(): ?string
    {
        return $this->termsUrl;
    }

    public function setDatasetCount(?int $count): self
    {
        $this->datasetCount = $count;
        return $this;
    }

    public function getDatasetCount(): ?int
    {
        return $this->datasetCount;
    }

    public function setSyncedAt(?\DateTime $syncedAt): self
    {
        $this->syncedAt = $syncedAt;
        return $this;
    }

    public function getSyncedAt(): ?\DateTime
    {
        return $this->syncedAt;
    }

    public function setAdapterClass(?string $adapterClass): self
    {
        $this->adapterClass = $adapterClass;
        return $this;
    }

    public function getAdapterClass(): ?string
    {
        return $this->adapterClass;
    }

    public function getAdapterShortName(): ?string
    {
        if (!$this->adapterClass) {
            return null;
        }

        return substr((string) strrchr('\\' . $this->adapterClass, '\\'), 1);
    }

    public function setEntityProviderClass(?string $entityProviderClass): self
    {
        $this->entityProviderClass = $entityProviderClass;
        return $this;
    }

    public function getEntityProviderClass(): ?string
    {
        return $this->entityProviderClass;
    }

    public function getEntityProviderShortName(): ?string
    {
        if (!$this->entityProviderClass) {
            return null;
        }

        return substr((string) strrchr('\\' . $this->entityProviderClass, '\\'), 1);
    }

    public function setPrimaryItem(?string $primaryItem): self
    {
        $this->primaryItem = $primaryItem;
        return $this;
    }

    public function getPrimaryItem(): ?string
    {
        return $this->primaryItem;
    }

    /** @param array<string, class-string> $authorities */
    public function setAuthorities(array $authorities): self
    {
        $this->authorities = $authorities;
        return $this;
    }

    /** @return array<string, class-string> */
    public function getAuthorities(): array
    {
        return $this->authorities;
    }

    public function setDurableRaw(bool $durableRaw): self
    {
        $this->durableRaw = $durableRaw;
        return $this;
    }

    public function isDurableRaw(): bool
    {
        return $this->durableRaw;
    }

    public function setCompressedRaw(bool $compressedRaw): self
    {
        $this->compressedRaw = $compressedRaw;
        return $this;
    }

    public function isCompressedRaw(): bool
    {
        return $this->compressedRaw;
    }

    public function setDescriptionIsContentFallback(bool $descriptionIsContentFallback): self
    {
        $this->descriptionIsContentFallback = $descriptionIsContentFallback;
        return $this;
    }

    public function isDescriptionIsContentFallback(): bool
    {
        return $this->descriptionIsContentFallback;
    }

    public function setMetaCreation(?string $metaCreation): self
    {
        $this->metaCreation = $metaCreation;
        return $this;
    }

    public function getMetaCreation(): ?string
    {
        return $this->metaCreation;
    }

    public function setRawAcquisition(?string $rawAcquisition): self
    {
        $this->rawAcquisition = $rawAcquisition;
        return $this;
    }

    public function getRawAcquisition(): ?string
    {
        return $this->rawAcquisition;
    }

    public function setCapturePath(?string $capturePath): self
    {
        $this->capturePath = $capturePath;
        return $this;
    }

    public function getCapturePath(): ?string
    {
        return $this->capturePath;
    }

    public function setCandidateCount(?int $candidateCount): self
    {
        $this->candidateCount = $candidateCount;
        return $this;
    }

    public function getCandidateCount(): ?int
    {
        return $this->candidateCount;
    }

    /** @param array<string, int> $counts */
    public function setDatasetStatusCounts(array $counts): self
    {
        $this->datasetStatusCounts = $counts;
        return $this;
    }

    /** @return array<string, int> */
    public function getDatasetStatusCounts(): array
    {
        return $this->datasetStatusCounts;
    }

    public function setLastScanAt(?\DateTime $lastScanAt): self
    {
        $this->lastScanAt = $lastScanAt;
        return $this;
    }

    public function getLastScanAt(): ?\DateTime
    {
        return $this->lastScanAt;
    }

    public function setVaultCommand(?string $vaultCommand): self
    {
        $this->vaultCommand = $vaultCommand;
        return $this;
    }

    public function getVaultCommand(): ?string
    {
        return $this->vaultCommand;
    }

    public function setVaultNotes(?string $vaultNotes): self
    {
        $this->vaultNotes = $vaultNotes;
        return $this;
    }

    public function getVaultNotes(): ?string
    {
        return $this->vaultNotes;
    }

    /** @param list<array{name: string, description: string}> $providerCommands */
    public function setProviderCommands(array $providerCommands): self
    {
        $this->providerCommands = $providerCommands;
        return $this;
    }

    /** @return list<array{name: string, description: string}> */
    public function getProviderCommands(): array
    {
        return $this->providerCommands;
    }

    /**
     * Where this provider sits in its own lifecycle. The homepage and /providers group on this,
     * so an adapter that has never produced data is visibly distinct from a row whose adapter is
     * gone — the two used to be indistinguishable, or invisible.
     */
    public function getStage(): string
    {
        if ($this->adapterClass === null) {
            return self::STAGE_ORPHAN;
        }

        return ($this->datasetCount ?? 0) > 0 ? self::STAGE_ACTIVE : self::STAGE_IDLE;
    }

    public function isOrphan(): bool
    {
        return $this->getStage() === self::STAGE_ORPHAN;
    }

    /** @return Collection<int, DatasetInfo> */
    public function getDatasets(): Collection
    {
        return $this->datasets;
    }

    public function addDataset(DatasetInfo $dataset): self
    {
        if (!$this->datasets->contains($dataset)) {
            $this->datasets->add($dataset);
            $dataset->setProviderEntity($this);
        }

        return $this;
    }

    public function removeDataset(DatasetInfo $dataset): self
    {
        if ($this->datasets->removeElement($dataset) && $dataset->getProviderEntity() === $this) {
            $dataset->setProviderEntity(null);
        }

        return $this;
    }

    public function __toString()
    {
        return $this->label ?? $this->code ?? '';
    }
}

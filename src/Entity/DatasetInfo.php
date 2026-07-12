<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\FieldBundle\Attribute\EntityMeta;
use Survos\FieldBundle\Attribute\Field;
use Survos\FieldBundle\Attribute\RouteIdentity;
use Survos\FieldBundle\Entity\RouteIdentityTrait;
use Survos\FieldBundle\Entity\RouteParametersInterface;
use Survos\FieldBundle\Enum\Widget;
use Survos\StateBundle\Traits\MarkingInterface;
use Survos\StateBundle\Traits\MarkingTrait;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Registry of all known datasets — populated once by scanning 00_meta/dataset.yaml
 * files, then used for all subsequent lookups without touching the filesystem.
 *
 * Populate with: bin/console data:scan-datasets
 */
#[EntityMeta(icon: 'mdi:database-outline', group: 'Data', label: 'Datasets')]
#[RouteIdentity(field: 'datasetKey')]
#[ORM\Entity(repositoryClass: DatasetInfoRepository::class)]
#[ORM\Index(columns: ['aggregator'])]
#[ORM\Index(columns: ['locale'])]
#[ORM\Index(columns: ['status'])]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/dataset_infos', normalizationContext: ['groups' => ['dataset:read']]),
        // datasetKey contains a slash (e.g. "nara/rg_105"); without the `.+` requirement
        // Symfony can neither match nor GENERATE the IRI, breaking serialization/browsing.
        new Get(
            uriTemplate: '/dataset_infos/{datasetKey}',
            requirements: ['datasetKey' => '.+'],
            normalizationContext: ['groups' => ['dataset:read']],
        ),
    ],
    normalizationContext: ['groups' => ['dataset:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['datasetKey' => 'partial', 'label' => 'partial', 'aggregator' => 'exact', 'status' => 'exact', 'country' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['datasetKey', 'label', 'aggregator', 'status', 'objCount', 'normalizedCount', 'lastScanned'])]
final class DatasetInfo implements RouteParametersInterface, MarkingInterface, \Stringable
{
    use RouteIdentityTrait;
    // Workflow lifecycle place (meta → raw → normalize → enrich → folio) — see app IDatasetWorkflow.
    use MarkingTrait;
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 128)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, sortable: true, order: 10)]
    public readonly string $datasetKey;  // e.g. "fortepan/hu", "dc/0v83gg01j"

    // ── From 00_meta/dataset.yaml ─────────────────────────────────────────────

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, sortable: true, order: 20)]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, order: 30)]
    public ?string $description = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, sortable: true, filterable: true, facet: true, order: 40)]
    public ?string $aggregator = null;    // dc | pp | fortepan | mds | mus | etc.

    #[ORM\ManyToOne(targetEntity: Provider::class, inversedBy: 'datasets')]
    #[ORM\JoinColumn(name: 'provider_code', referencedColumnName: 'code', nullable: true, onDelete: 'SET NULL')]
    public ?Provider $providerEntity = null;

    /** @var Collection<int, Artifact> */
    #[ORM\OneToMany(mappedBy: 'dataset', targetEntity: Artifact::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['dataset:read'])]
    public Collection $artifacts;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, sortable: true, filterable: true, facet: true, order: 50)]
    public ?string $locale = null;        // default locale: en | de | hu | etc.

    /** From _meta/dataset.json's locale.targets — which locales dataset:intl:push/pull and
     *  folio:build --locale should translate this dataset into. Empty means "not configured yet",
     *  not "no translation wanted" — commands should fall back to their own --targets default.
     *  @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    #[Groups(['dataset:read'])]
    #[Field(visible: false)]
    public array $targetLocales = [];

    /** From _meta/dataset.json's locale.preferredEngine — e.g. "deepl" for a source language
     *  LibreTranslate doesn't support (see mus/cazma: hr isn't in babel.survos.com's language
     *  list). Null defers to dataset:intl:push/pull's own --engine default. */
    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, filterable: true, facet: true, visible: false)]
    public ?string $preferredEngine = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, sortable: true, filterable: true, facet: true, order: 60)]
    public ?string $country = null;       // ISO2: US | HU | GB | etc.

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, visible: false)]
    public ?string $contactUrl = null;    // collection homepage

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(searchable: true, filterable: true, facet: true, visible: false)]
    public ?string $rightsUri = null;

    #[ORM\Column]
    #[Groups(['dataset:read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, order: 70)]
    public int $objCount = 0;             // from extras.obj_count or profile recordCount

    // ── Paths (resolved at scan time, no filesystem access needed later) ──────

    #[ORM\Column(nullable: true)]
    #[Field(searchable: true, visible: false)]
    public ?string $metaPath = null;      // 00_meta/dataset.yaml

    #[ORM\Column(nullable: true)]
    #[Field(searchable: true, visible: false)]
    public ?string $rawPath = null;       // 05_raw/obj.jsonl

    #[ORM\Column(nullable: true)]
    #[Field(searchable: true, visible: false)]
    public ?string $profilePath = null;   // 21_profile/obj.profile.json

    // ── Pipeline status ───────────────────────────────────────────────────────

    /** One of: discovered | raw | normalized | profiled | folio | indexed */
    #[ORM\Column(length: 32)]
    #[Groups(['dataset:read'])]
    #[Field(sortable: true, filterable: true, facet: true, order: 80)]
    public string $status = 'discovered';

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, order: 90)]
    public ?int $normalizedCount = null;   // from profile recordCount

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(sortable: true, filterable: true, widget: Widget::Range, order: 100)]
    public ?int $meiliDocCount = null;     // docs in Meilisearch index

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(sortable: true, widget: Widget::Date, order: 110)]
    public ?\DateTimeImmutable $lastScanned = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(sortable: true, widget: Widget::Date, order: 120)]
    public ?\DateTimeImmutable $lastNormalized = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['dataset:read'])]
    #[Field(sortable: true, widget: Widget::Date, order: 130)]
    public ?\DateTimeImmutable $lastIndexed = null;

    // ── Compiled schema (from profile + field_map) ────────────────────────────

    /**
     * Core names available for this dataset (e.g. ["obj"] or ["obj", "cat", "type"]).
     * Populated by data:scan-datasets from profile files.
     * @var string[]
     */
    #[ORM\Column(type: Types::JSON)]
    public array $cores = [];

    /** @var array<string, string[]> */
    #[ORM\Column(type: Types::JSONB)]
    public array $fields = [];

    /**
     * Compact subset of obj.profile.json for fast registry decisions.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSONB, nullable: true)]
    public ?array $profileSummary = null;

    /**
     * Meilisearch settings derived from heuristic + field_map.
     * e.g. {"filterable": ["year","country","city"], "searchable": ["title","tags"]}
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSONB)]
    public array $meiliSettings = [];

    /**
     * Full 00_meta/dataset.yaml content cached as JSON.
     * Avoids re-reading the file for every registry lookup.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSONB)]
    public array $meta = [];


    /** @return array<string, int> */
    #[Groups(['dataset:read'])]
    public function getCoreCounts(): array
    {
        $counts = is_array($this->meta['coreCounts'] ?? null) ? $this->meta['coreCounts'] : [];

        return array_map('intval', $counts);
    }

    #[Groups(['dataset:read'])]
    public int $itemCount {
        get => $this->getCoreCounts()['obj'] ?? $this->objCount;
    }

    public function __construct(string $datasetKey)
    {
        $this->datasetKey = $datasetKey;
        // The workflow definition is app-owned; keep the bundle entity decoupled from app workflow constants.
        $this->marking = 'new';
        $this->artifacts = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->label ?? $this->datasetKey;
    }

    // ── Derived helpers ───────────────────────────────────────────────────────

    public function provider(): string
    {
        return explode('/', $this->datasetKey, 2)[0];
    }

    #[Groups(['dataset:read'])]
    public function getProvider(): string
    {
        return $this->provider();
    }

    public function code(): string
    {
        return explode('/', $this->datasetKey, 2)[1] ?? $this->datasetKey;
    }

    #[Groups(['dataset:read'])]
    public function getCode(): string
    {
        return $this->code();
    }

    #[Groups(['dataset:read'])]
    public function getSourceUrl(): ?string
    {
        $links = is_array($this->meta['source']['links'] ?? null) ? $this->meta['source']['links'] : [];
        foreach (['collection', 'source', 'site', 'website', 'record', 'institution'] as $key) {
            $url = $links[$key] ?? null;
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        if ($this->provider() === 'dc') {
            return 'https://www.digitalcommonwealth.org/collections/commonwealth:' . rawurlencode($this->code());
        }

        return $this->contactUrl;
    }

    public function hasRaw(): bool        { return $this->rawPath !== null; }
    public function hasNormalized(): bool { return ($this->normalizedCount ?? 0) >= 1; }
    public function hasProfile(): bool    { return $this->profilePath !== null; }

    public function hasArtifact(string $type): bool
    {
        return $this->artifact($type) !== null;
    }

    /** Whether a compressed (.gz) folio archive exists for this dataset — shown as a download icon. */
    public function hasArchive(): bool
    {
        return $this->hasArtifact(Artifact::TYPE_FOLIO_ARCHIVE);
    }

    public function artifact(string $type, string $code = Artifact::CODE_DEFAULT): ?Artifact
    {
        foreach ($this->artifacts as $artifact) {
            if ($artifact->type === $type && $artifact->code === $code) {
                return $artifact;
            }
        }

        return null;
    }

    public function addArtifact(Artifact $artifact): self
    {
        if (!$this->artifacts->contains($artifact)) {
            $this->artifacts->add($artifact);
            $artifact->dataset = $this;
        }

        return $this;
    }

    public function getProviderEntity(): ?Provider
    {
        return $this->providerEntity;
    }

    public function setProviderEntity(?Provider $provider): self
    {
        $this->providerEntity = $provider;

        return $this;
    }

    public function isReadyForMeili(): bool
    {
        return $this->hasNormalized();
    }
}

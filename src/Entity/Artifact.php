<?php
declare(strict_types=1);

namespace Survos\DataBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataBundle\Repository\ArtifactRepository;
use Survos\FieldBundle\Attribute\EntityMeta;
use Symfony\Component\Serializer\Attribute\Groups;

#[EntityMeta(icon: 'mdi:file-cog-outline', group: 'Data', label: 'Artifacts')]
#[ORM\Entity(repositoryClass: ArtifactRepository::class)]
#[ORM\Table(name: 'dataset_artifact')]
#[ORM\UniqueConstraint(name: 'uniq_dataset_artifact', columns: ['dataset_key', 'type', 'code'])]
#[ORM\Index(columns: ['type'])]
#[ORM\Index(columns: ['code'])]
#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/artifacts', normalizationContext: ['groups' => ['artifact:read']]),
        new Get(uriTemplate: '/artifacts/{id}', normalizationContext: ['groups' => ['artifact:read']]),
    ],
    normalizationContext: ['groups' => ['artifact:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['dataset.datasetKey' => 'partial', 'type' => 'exact', 'code' => 'exact', 'uri' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['type', 'code', 'sizeBytes', 'rowCount', 'updatedAt'])]
class Artifact
{
    public const TYPE_FOLIO = 'folio';
    public const TYPE_MEILI = 'meili';
    public const TYPE_ARCHIVE = 'archive';
    public const TYPE_JSONL = 'jsonl';
    public const TYPE_REPORT = 'report';

    public const CODE_DEFAULT = 'default';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['artifact:read', 'dataset:read'])]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DatasetInfo::class, inversedBy: 'artifacts')]
    #[ORM\JoinColumn(name: 'dataset_key', referencedColumnName: 'dataset_key', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['artifact:read'])]
    public DatasetInfo $dataset;

    #[ORM\Column(length: 32)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public string $type;

    #[ORM\Column(length: 128)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public string $code = self::CODE_DEFAULT;

    #[ORM\Column(length: 1024, nullable: true)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public ?string $uri = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public ?int $sizeBytes = null;

    #[Groups(['artifact:read', 'dataset:read'])]
    public ?int $liveSize {
        get {
            if (!$this->uri || !is_file($this->uri)) {
                return null;
            }

            clearstatcache(true, $this->uri);

            return filesize($this->uri) ?: null;
        }
    }

    #[ORM\Column(nullable: true)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public ?int $rowCount = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public ?string $checksum = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    #[Groups(['artifact:read', 'dataset:read'])]
    public \DateTimeImmutable $discoveredAt;

    /** @var array<string, int> short DTO class name → row count */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public ?array $dtoCounts = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSONB)]
    #[Groups(['artifact:read', 'dataset:read'])]
    public array $metadata = [];

    public function __construct(DatasetInfo $dataset, string $type, string $code = self::CODE_DEFAULT)
    {
        $this->dataset = $dataset;
        $this->type = $type;
        $this->code = $code;
        $this->discoveredAt = new \DateTimeImmutable();
    }
}

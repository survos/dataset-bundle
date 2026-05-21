<?php
declare(strict_types=1);

namespace Survos\DataBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Doctrine\Orm\Filter\SortFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\QueryParameter;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Survos\DataBundle\Repository\CandidateRepository;
use Symfony\Component\Serializer\Attribute\Groups;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/candidates',
            normalizationContext: ['groups' => ['candidate:read']],
            parameters: [
                'candidateKey' => new QueryParameter(filter: new PartialSearchFilter(), property: 'candidateKey'),
                'providerCode' => new QueryParameter(filter: new ExactFilter(), property: 'providerCode'),
                'sourceId' => new QueryParameter(filter: new PartialSearchFilter(), property: 'sourceId'),
                'kind' => new QueryParameter(filter: new ExactFilter(), property: 'kind'),
                'label' => new QueryParameter(filter: new PartialSearchFilter(), property: 'label'),
                'status' => new QueryParameter(filter: new ExactFilter(), property: 'status'),
                'datasetKey' => new QueryParameter(filter: new PartialSearchFilter(), property: 'datasetKey'),
                'order[candidateKey]' => new QueryParameter(filter: new SortFilter(), property: 'candidateKey'),
                'order[providerCode]' => new QueryParameter(filter: new SortFilter(), property: 'providerCode'),
                'order[sourceId]' => new QueryParameter(filter: new SortFilter(), property: 'sourceId'),
                'order[kind]' => new QueryParameter(filter: new SortFilter(), property: 'kind'),
                'order[label]' => new QueryParameter(filter: new SortFilter(), property: 'label'),
                'order[status]' => new QueryParameter(filter: new SortFilter(), property: 'status'),
                'order[datasetKey]' => new QueryParameter(filter: new SortFilter(), property: 'datasetKey'),
                'order[discoveredAt]' => new QueryParameter(filter: new SortFilter(), property: 'discoveredAt'),
                'order[updatedAt]' => new QueryParameter(filter: new SortFilter(), property: 'updatedAt'),
            ],
        ),
        new Get(
            uriTemplate: '/candidates/{candidateKey}',
            requirements: ['candidateKey' => '.+'],
            uriVariables: [
                'candidateKey' => new Link(fromClass: self::class, identifiers: ['candidateKey']),
            ],
            normalizationContext: ['groups' => ['candidate:read']],
        ),
    ],
    normalizationContext: ['groups' => ['candidate:read']],
)]
#[ORM\Entity(repositoryClass: CandidateRepository::class)]
#[ORM\Table(name: 'candidate')]
#[ORM\Index(columns: ['provider_code'])]
#[ORM\Index(columns: ['status'])]
final class Candidate
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 160)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public readonly string $candidateKey;

    #[ORM\Column(name: 'provider_code', type: Types::STRING, length: 32)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public string $providerCode;

    #[ORM\ManyToOne(targetEntity: Provider::class)]
    #[ORM\JoinColumn(name: 'provider_entity_code', referencedColumnName: 'code', nullable: true, onDelete: 'SET NULL')]
    public ?Provider $providerEntity = null;

    #[ORM\Column(type: Types::STRING, length: 160, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $sourceId = null;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $kind = null;

    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $label = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $description = null;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $sourceUrl = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $locale = null;

    #[ORM\Column(type: Types::STRING, length: 8, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $country = null;

    #[ORM\Column(type: Types::STRING, length: 160, nullable: true)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public ?string $datasetKey = null;

    #[ORM\Column(type: Types::STRING, length: 32)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public string $status = 'discovered';

    /** @var array<string,mixed> */
    #[ORM\Column(type: Types::JSONB)]
    #[Groups(['candidate:read', 'candidate:write'])]
    public array $meta = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['candidate:read'])]
    public ?\DateTimeImmutable $discoveredAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['candidate:read'])]
    public ?\DateTimeImmutable $hydratedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['candidate:read'])]
    public ?\DateTimeImmutable $promotedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['candidate:read'])]
    public ?\DateTimeImmutable $updatedAt = null;

    public function __construct(string $candidateKey)
    {
        $this->candidateKey = $candidateKey;
        $this->providerCode = explode('/', $candidateKey, 2)[0] ?? $candidateKey;
    }

    public function provider(): string
    {
        return explode('/', $this->candidateKey, 2)[0] ?? $this->candidateKey;
    }

    public function code(): string
    {
        return explode('/', $this->candidateKey, 2)[1] ?? $this->candidateKey;
    }
}

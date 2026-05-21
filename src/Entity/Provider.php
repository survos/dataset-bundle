<?php
declare(strict_types=1);

namespace Survos\DataBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Provider entity - represents a data provider like Smithsonian, NARA, DC, etc.
 * 
 * In data-bundle:
 *   data/{provider}/provider.json    ← persisted from #[Aggregator] attribute
 *   data/{provider}/{code}/         ← datasets
 */
#[ORM\Entity(repositoryClass: \Survos\DataBundle\Repository\ProviderRepository::class)]
#[ORM\Table(name: 'provider')]
class Provider
{
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

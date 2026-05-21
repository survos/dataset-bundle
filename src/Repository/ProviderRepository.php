<?php
declare(strict_types=1);

namespace Survos\DataBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\DataBundle\Entity\Provider;

/**
 * @extends ServiceEntityRepository<Provider>
 */
class ProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Provider::class);
    }

    public function findOneByCode(string $code): ?Provider
    {
        return $this->find($code);
    }

    public function findAllOrdered(): array
    {
        return $this->findBy([], ['label' => 'ASC']);
    }
}

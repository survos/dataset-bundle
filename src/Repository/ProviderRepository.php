<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\DatasetBundle\Entity\Provider;

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

    /**
     * @param list<string> $providerCodes
     * @return list<Provider>
     */
    public function findConfiguredOrdered(array $providerCodes): array
    {
        $providerCodes = array_values(array_filter(array_unique(array_map(
            static fn(mixed $code): string => strtolower(trim((string) $code)),
            $providerCodes
        ))));

        if ($providerCodes === []) {
            return $this->findAllOrdered();
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.code IN (:providerCodes)')
            ->setParameter('providerCodes', $providerCodes)
            ->orderBy('p.label', 'ASC')
            ->addOrderBy('p.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}

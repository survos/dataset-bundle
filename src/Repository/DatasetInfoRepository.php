<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Repository\QueryBuilderHelperInterface;
use Survos\FieldBundle\Repository\QueryBuilderHelperTrait;
use Survos\DatasetBundle\Entity\DatasetInfo;

/**
 * @extends ServiceEntityRepository<DatasetInfo>
 */
final class DatasetInfoRepository extends ServiceEntityRepository implements QueryBuilderHelperInterface
{
    use QueryBuilderHelperTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatasetInfo::class);
    }

    /**
     * Dataset counts per provider, broken down by status — one query for the whole registry,
     * so the provider listing can show where a provider's datasets are stuck without loading
     * (or counting) every dataset row per card.
     *
     * @return array<string, array<string, int>> provider code => [status => count]
     */
    public function countByProviderAndStatus(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.aggregator AS providerCode, d.status AS status, COUNT(d.datasetKey) AS total')
            ->groupBy('d.aggregator')
            ->addGroupBy('d.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $code = (string) $row['providerCode'];
            $counts[$code][(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Dataset counts per workflow marking, then per provider. Keyed marking-first because the
     * common question is "how many are still cataloged", asked once for the whole registry rather
     * than per provider.
     *
     * @return array<string, array<string, int>> marking => [provider code => count]
     */
    public function countByProviderAndMarking(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('d.aggregator AS providerCode, d.marking AS marking, COUNT(d.datasetKey) AS total')
            ->groupBy('d.aggregator')
            ->addGroupBy('d.marking')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['marking']][(string) $row['providerCode']] = (int) $row['total'];
        }

        return $counts;
    }
}

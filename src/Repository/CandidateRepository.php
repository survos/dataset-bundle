<?php
declare(strict_types=1);

namespace Survos\DataBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\DataBundle\Entity\Candidate;

/**
 * @extends ServiceEntityRepository<Candidate>
 */
final class CandidateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Candidate::class);
    }

    /**
     * @return array<string,int>
     */
    public function countByProviderCode(): array
    {
        $rows = $this->createQueryBuilder('candidate')
            ->select('candidate.providerCode AS providerCode, COUNT(candidate.candidateKey) AS candidateCount')
            ->groupBy('candidate.providerCode')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['providerCode']] = (int) $row['candidateCount'];
        }

        return $counts;
    }
}

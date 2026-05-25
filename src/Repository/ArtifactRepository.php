<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Survos\FieldBundle\Repository\QueryBuilderHelperInterface;
use Survos\FieldBundle\Repository\QueryBuilderHelperTrait;
use Survos\DatasetBundle\Entity\Artifact;

/**
 * @extends ServiceEntityRepository<Artifact>
 */
final class ArtifactRepository extends ServiceEntityRepository implements QueryBuilderHelperInterface
{
    use QueryBuilderHelperTrait;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artifact::class);
    }
}

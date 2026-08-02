<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Survos\DatasetBundle\Entity\Artifact;

/**
 * Scopes the `/folio-archives` collection (Artifact's `folio_archives`-named operation) to
 * `type = TYPE_FOLIO_ARCHIVE` at the query level -- not just documentation/convention. The
 * generic `/artifacts` collection has no default type restriction, so an unfiltered request
 * there returns every artifact type, including `jsonl`/`report` rows whose `uri` is a raw local
 * filesystem path. `/folio-archives` exists specifically so a folio-browsing client can never
 * accidentally see those by forgetting a `?type=folio_archive` query param.
 *
 * Applies on top of, not instead of, the existing SearchFilter/OrderFilter/RangeFilter/DateFilter
 * on Artifact -- this only adds one fixed WHERE clause, everything else still works normally.
 */
final class FolioArchiveTypeExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Artifact::class !== $resourceClass || 'folio_archives' !== $operation?->getName()) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(sprintf('%s.type = :folioArchiveType', $alias))
            ->setParameter('folioArchiveType', Artifact::TYPE_FOLIO_ARCHIVE);
    }
}

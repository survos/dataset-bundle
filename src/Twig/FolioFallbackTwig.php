<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * dataset/show.html.twig links a dataset to its folio with folio-bundle's folio_browse_url() and
 * folio_reader_url(). This
 * bundle only suggests folio-bundle, and an unknown Twig function is a compile error, so without it
 * the page 500ed. Registered only when folio-bundle is absent; the links then simply aren't shown.
 */
final class FolioFallbackTwig extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('folio_browse_url', static fn (string $folioCode): ?string => null),
            new TwigFunction('folio_reader_url', static fn (string $folioCode): ?string => null),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Survos\DataBundle\Menu;

use Survos\DataBundle\Entity\Candidate;
use Survos\DataBundle\Entity\Artifact;
use Survos\DataBundle\Entity\DatasetInfo;
use Survos\DataBundle\Entity\Provider;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class DataMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string { return 'Data'; }

    protected function getBrowseRoute(): ?string
    {
        return 'data_bundle_provider_index';
    }

    protected function getResourceClasses(): array
    {
        return [
            'Providers'   => Provider::class,
            'Datasets'    => DatasetInfo::class,
            'Artifacts'   => Artifact::class,
            'Candidates'  => Candidate::class,
        ];
    }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $this->buildAdminMenu($event);
    }
}

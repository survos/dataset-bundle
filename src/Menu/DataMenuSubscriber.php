<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Menu;

use Survos\DatasetBundle\Entity\Candidate;
use Survos\DatasetBundle\Entity\Artifact;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Entity\Provider;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class DataMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string { return 'Data'; }

    // Use the default survos_admin_browse (/admin/browse/{code}) so each resource
    // links to its OWN api-grid page. Overriding this to data_bundle_provider_index
    // (which has no {code}) collapsed every link onto the providers page.

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

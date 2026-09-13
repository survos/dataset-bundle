<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Twig\Components;

use Survos\DatasetBundle\Entity\Provider;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Repository\ProviderRepository;
use Survos\MeiliBundle\Repository\IndexInfoRepository;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\Component\Routing\RouterInterface;

#[AsTwigComponent('ProviderList')]
final class ProviderListComponent
{
    /** Order stages appear in, worst-maintained last so problems are not buried mid-list. */
    private const STAGE_ORDER = [
        Provider::STAGE_ACTIVE => 0,
        Provider::STAGE_IDLE => 1,
        Provider::STAGE_ORPHAN => 2,
    ];

    /**
     * 'all' lists every provider row grouped by stage; 'configured' restricts to the
     * `survos_dataset.providers` allowlist. 'all' is the default because the allowlist governs
     * what dataset:scan walks, and using it to filter the UI hid every provider that had an
     * adapter but had never been scanned.
     */
    public string $scope = 'all';

    /** Group the cards under stage headings, or render one flat grid. */
    public bool $grouped = true;

    public array $providers = [];
    /** @var array<string, list<array<string, mixed>>> stage => rows */
    public array $byStage = [];

    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly DatasetInfoRepository $datasetInfoRepository,
        private readonly RouterInterface $router,
        private readonly array $enabledProviders = [],
        private readonly ?IndexInfoRepository $indexInfoRepository = null,
    ) {
    }

    public function mount(): void
    {
        $providers = $this->scope === 'configured'
            ? $this->providerRepository->findConfiguredOrdered($this->enabledProviders)
            : $this->providerRepository->findAllForAdmin();

        $statusCounts = $this->datasetInfoRepository->countByProviderAndStatus();
        $markingCounts = $this->datasetInfoRepository->countByProviderAndMarking();
        $candidateCounts = $markingCounts['new'] ?? [];
        $configured = $this->configuredCodes();

        // One fetch for every provider — the old per-provider findAll() inside the loop read the
        // whole index table once per card.
        $allIndexes = $this->indexInfoRepository?->findAll() ?? [];

        $rows = [];
        foreach ($providers as $provider) {
            $code = (string) $provider->getCode();

            $indexInfos = array_filter($allIndexes, static fn(object $idx): bool =>
                str_starts_with($idx->indexName, 'md_' . $code) ||
                $idx->indexName === 'md_' . $code
            );

            $rows[] = [
                'code' => $code,
                'label' => $provider->getLabel(),
                'description' => $provider->getDescription(),
                'homepage' => $provider->getHomepage(),
                'termsUrl' => $provider->getTermsUrl(),
                'logo' => $provider->getLogo(),
                'dataReuse' => $provider->getDataReuse(),
                'defaultLocale' => $provider->getDefaultLocale(),
                'datasetCount' => $provider->getDatasetCount() ?? 0,
                'candidateCount' => $candidateCounts[$code] ?? $provider->getCandidateCount() ?? 0,
                'markingCounts' => $this->markingsFor($code, $markingCounts),
                'statusCounts' => $statusCounts[$code] ?? $provider->getDatasetStatusCounts(),
                'indexInfos' => array_values($indexInfos),
                'apiUrl' => $this->router->generate('_api_/dataset_infos_get_collection', ['aggregator' => $code]),
                'showUrl' => $this->router->generate('data_bundle_provider_show', ['provider' => $code]),
                'datasets' => $provider->getDatasets(),

                // Administration: who implements this provider, how its raw is acquired, and
                // whether the pipeline is configured to look at it at all.
                'stage' => $provider->getStage(),
                'configured' => $configured === [] || in_array($code, $configured, true),
                'adapterClass' => $provider->getAdapterClass(),
                'adapterShortName' => $provider->getAdapterShortName(),
                'entityProviderShortName' => $provider->getEntityProviderShortName(),
                'primaryItem' => $provider->getPrimaryItem(),
                'authorities' => $provider->getAuthorities(),
                'durableRaw' => $provider->isDurableRaw(),
                'compressedRaw' => $provider->isCompressedRaw(),
                'capturePath' => $provider->getCapturePath(),
                'rawAcquisition' => $provider->getRawAcquisition(),
                'metaCreation' => $provider->getMetaCreation(),
                'vaultCommand' => $provider->getVaultCommand(),
                'vaultNotes' => $provider->getVaultNotes(),
                'providerCommands' => $provider->getProviderCommands(),
                'approxObjCount' => $provider->getApproxObjCount(),
                'syncedAt' => $provider->getSyncedAt(),
                'lastScanAt' => $provider->getLastScanAt(),
            ];
        }

        usort($rows, static fn(array $a, array $b): int =>
            [self::STAGE_ORDER[$a['stage']] ?? 9, strtolower((string) ($a['label'] ?: $a['code']))]
            <=> [self::STAGE_ORDER[$b['stage']] ?? 9, strtolower((string) ($b['label'] ?: $b['code']))]
        );

        $byStage = [];
        foreach ($rows as $row) {
            $byStage[$row['stage']][] = $row;
        }

        $this->providers = $rows;
        $this->byStage = $byStage;
    }

    /**
     * @param array<string, array<string, int>> $markingCounts
     * @return array<string, int>
     */
    private function markingsFor(string $code, array $markingCounts): array
    {
        $counts = [];
        foreach ($markingCounts as $marking => $byProvider) {
            if (isset($byProvider[$code])) {
                $counts[$marking] = $byProvider[$code];
            }
        }

        return $counts;
    }

    /** @return list<string> */
    private function configuredCodes(): array
    {
        return array_values(array_filter(array_unique(array_map(
            static fn(mixed $code): string => strtolower(trim((string) $code)),
            $this->enabledProviders
        ))));
    }
}

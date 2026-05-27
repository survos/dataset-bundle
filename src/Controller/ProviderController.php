<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Controller;

use Survos\DatasetBundle\Entity\Candidate;
use Survos\DatasetBundle\Entity\DatasetInfo;
use Survos\DatasetBundle\Repository\DatasetInfoRepository;
use Survos\DatasetBundle\Repository\ProviderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProviderController extends AbstractController
{
    public function __construct(
        private readonly ProviderRepository $providerRepository,
        private readonly DatasetInfoRepository $datasetInfoRepository,
        private readonly array $enabledProviders = [],
    ) {
    }

    #[Route('/data/providers', name: 'data_bundle_provider_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SurvosDatasetBundle/provider/index.html.twig');
    }

    #[Route('/data/providers/{provider}', name: 'data_bundle_provider_show', methods: ['GET'])]
    public function show(string $provider): Response
    {
        $providerCode = trim($provider);
        if ($providerCode === '') {
            throw $this->createNotFoundException('Missing provider code.');
        }

        if (!$this->isConfiguredProvider($providerCode)) {
            throw $this->createNotFoundException(sprintf('Provider is not enabled in this application: %s', $providerCode));
        }

        $providerEntity = $this->providerRepository->find($providerCode);
        if (!$providerEntity) {
            throw $this->createNotFoundException(sprintf('Provider not found: %s', $providerCode));
        }

        return $this->render('@SurvosDatasetBundle/provider/show.html.twig', [
            'provider' => $providerEntity,
            'candidateApiUrl' => '/api/candidates',
            'datasetApiUrl' => '/api/dataset_infos?aggregator=' . rawurlencode($providerCode),
            'candidateClass' => Candidate::class,
            'datasetClass' => DatasetInfo::class,
        ]);
    }

    #[Route('/data/datasets/{provider}/{code}', name: 'data_bundle_dataset_show', requirements: ['code' => '.+'], methods: ['GET'])]
    public function dataset(string $provider, string $code): Response
    {
        $providerCode = strtolower(trim($provider));
        if (!$this->isConfiguredProvider($providerCode)) {
            throw $this->createNotFoundException(sprintf('Provider is not enabled in this application: %s', $providerCode));
        }

        $datasetKey = $providerCode . '/' . trim($code);
        $dataset = $this->datasetInfoRepository->find($datasetKey);
        if (!$dataset) {
            throw $this->createNotFoundException(sprintf('Dataset not found: %s', $datasetKey));
        }

        return $this->render('@SurvosDatasetBundle/dataset/show.html.twig', [
            'dataset' => $dataset,
            'provider' => $dataset->providerEntity,
        ]);
    }

    private function isConfiguredProvider(string $providerCode): bool
    {
        $enabledProviders = array_values(array_filter(array_unique(array_map(
            static fn(mixed $code): string => strtolower(trim((string) $code)),
            $this->enabledProviders
        ))));

        return $enabledProviders === [] || in_array(strtolower($providerCode), $enabledProviders, true);
    }
}

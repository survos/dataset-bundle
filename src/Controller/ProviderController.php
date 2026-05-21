<?php
declare(strict_types=1);

namespace Survos\DataBundle\Controller;

use Survos\DataBundle\Entity\Candidate;
use Survos\DataBundle\Entity\DatasetInfo;
use Survos\DataBundle\Repository\ProviderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProviderController extends AbstractController
{
    public function __construct(
        private readonly ProviderRepository $providerRepository,
    ) {
    }

    #[Route('/data/providers', name: 'data_bundle_provider_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@SurvosDataBundle/provider/index.html.twig');
    }

    #[Route('/data/providers/{provider}', name: 'data_bundle_provider_show', methods: ['GET'])]
    public function show(string $provider): Response
    {
        $providerCode = trim($provider);
        if ($providerCode === '') {
            throw $this->createNotFoundException('Missing provider code.');
        }

        $providerEntity = $this->providerRepository->find($providerCode);
        if (!$providerEntity) {
            throw $this->createNotFoundException(sprintf('Provider not found: %s', $providerCode));
        }

        return $this->render('@SurvosDataBundle/provider/show.html.twig', [
            'provider' => $providerEntity,
            'candidateApiUrl' => '/api/candidates',
            'datasetApiUrl' => '/api/dataset_infos?aggregator=' . rawurlencode($providerCode),
            'candidateClass' => Candidate::class,
            'datasetClass' => DatasetInfo::class,
        ]);
    }
}

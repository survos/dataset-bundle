<?php
declare(strict_types=1);

namespace Survos\DataBundle\Service;

use Survos\DataBundle\Dto\ProviderSnapshot;
use Survos\DataBundle\Entity\Provider;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

final class ProviderSnapshotCodec
{
    private const GROUP = 'provider:snapshot';

    public function __construct(
        private readonly SerializerInterface $serializer,
    ) {}

    public function toSnapshot(Provider $provider): ProviderSnapshot
    {
        $payload = $this->serializer->normalize($provider, null, [
            AbstractNormalizer::ATTRIBUTES => [
                'code',
                'label',
                'description',
                'homepage',
                'logo',
                'approxInstCount',
                'approxObjCount',
                'defaultLocale',
                'dataReuse',
                'termsUrl',
            ],
        ]);

        /** @var ProviderSnapshot $snapshot */
        $snapshot = $this->serializer->denormalize($payload, ProviderSnapshot::class, null, [
            'groups' => [self::GROUP],
        ]);

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(ProviderSnapshot $snapshot): array
    {
        return [
            'code' => $snapshot->code,
            'label' => $snapshot->label,
            'description' => $snapshot->description,
            'homepage' => $snapshot->homepage,
            'logo' => $snapshot->logo,
            'approxInstCount' => $snapshot->approxInstCount,
            'approxObjCount' => $snapshot->approxObjCount,
            'defaultLocale' => $snapshot->defaultLocale,
            'dataReuse' => $snapshot->dataReuse,
            'termsUrl' => $snapshot->termsUrl,
            'entityProviderClass' => $snapshot->entityProviderClass,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function fromArray(array $payload, ?string $fallbackCode = null): ProviderSnapshot
    {
        if (isset($payload['provider']) && is_array($payload['provider'])) {
            $payload = $payload['provider'];
        }

        /** @var ProviderSnapshot $snapshot */
        $snapshot = $this->serializer->denormalize($payload, ProviderSnapshot::class, null, [
            'groups' => [self::GROUP],
        ]);

        if (!$snapshot->code) {
            $snapshot->code = $fallbackCode;
        }

        return $snapshot;
    }

    public function fromJson(string $json, ?string $fallbackCode = null): ProviderSnapshot
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('provider.json must decode to an object');
        }

        return $this->fromArray($decoded, $fallbackCode);
    }

    public function fromFile(string $filename, ?string $fallbackCode = null): ProviderSnapshot
    {
        if (!is_file($filename)) {
            throw new \RuntimeException(sprintf('Provider snapshot file not found: %s', $filename));
        }

        $json = file_get_contents($filename);
        if ($json === false) {
            throw new \RuntimeException(sprintf('Unable to read provider snapshot file: %s', $filename));
        }

        return $this->fromJson($json, $fallbackCode);
    }

    public function applyToProvider(ProviderSnapshot $snapshot, Provider $provider): Provider
    {
        $payload = $this->serializer->normalize($snapshot, null, [
            'groups' => [self::GROUP],
        ]);

        $this->serializer->denormalize($payload, Provider::class, null, [
            AbstractNormalizer::OBJECT_TO_POPULATE => $provider,
            AbstractNormalizer::IGNORED_ATTRIBUTES => ['code', 'datasets', 'syncedAt', 'datasetCount'],
        ]);

        return $provider;
    }

    public function writeFile(string $filename, Provider|ProviderSnapshot $source): void
    {
        $snapshot = $source instanceof Provider ? $this->toSnapshot($source) : $source;

        $json = json_encode(
            $this->toArray($snapshot),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        file_put_contents($filename, $json . PHP_EOL);
    }
}

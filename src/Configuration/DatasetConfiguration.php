<?php
declare(strict_types=1);

namespace Survos\DataBundle\Configuration;

use Symfony\Component\Serializer\Annotation as Serializer;

final class DatasetConfiguration
{
    public function __construct(
        // Identity
        public readonly string $datasetKey,
        public readonly string $aggregator,
        public readonly ?string $sourceId = null,

        // Display
        public readonly ?string $label = null,
        public readonly ?string $description = null,

        // Provider / Institution
        public readonly ?ProviderConfiguration $provider = null,

        // Geography
        public readonly ?CountryConfiguration $country = null,

        // Contact
        public readonly ?ContactConfiguration $contact = null,

        // Rights
        public readonly ?RightsConfiguration $rights = null,

        // Locale (babel)
        public readonly ?LocaleConfiguration $locale = null,

        // Babel (translation/pipeline config)
        public readonly ?BabelConfiguration $babel = null,

        // Source (more detailed than provider)
        public readonly ?SourceConfiguration $source = null,

        // Upstream
        public readonly ?UpstreamConfiguration $upstream = null,

        // Runtime
        public readonly ?array $tables = null,
        public readonly ?array $files = null,
        public readonly ?array $templates = null,
        public readonly ?string $type = null,
        public readonly ?string $visibility = null,

        // Extra data
        public readonly ?array $extras = null,
    ) {}

    public static function create(
        string $datasetKey,
        string $aggregator,
    ): self {
        return new self(
            datasetKey: $datasetKey,
            aggregator: $aggregator,
        );
    }

    public function withSourceId(string $sourceId): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withLabel(?string $label): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withDescription(?string $description): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withProvider(?ProviderConfiguration $provider): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withCountry(?CountryConfiguration $country): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withContact(?ContactConfiguration $contact): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withRights(?RightsConfiguration $rights): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withLocale(?LocaleConfiguration $locale): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withBabel(?BabelConfiguration $babel): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withSource(?SourceConfiguration $source): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withUpstream(?UpstreamConfiguration $upstream): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $this->extras,
        );
    }

    public function withRuntime(
        ?array $tables = null,
        ?array $files = null,
        ?array $templates = null,
        ?string $type = null,
        ?string $visibility = null,
    ): self {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $tables ?? $this->tables,
            files: $files ?? $this->files,
            templates: $templates ?? $this->templates,
            type: $type ?? $this->type,
            visibility: $visibility ?? $this->visibility,
            extras: $this->extras,
        );
    }

    public function withExtras(?array $extras): self
    {
        return new self(
            datasetKey: $this->datasetKey,
            aggregator: $this->aggregator,
            sourceId: $this->sourceId,
            label: $this->label,
            description: $this->description,
            provider: $this->provider,
            country: $this->country,
            contact: $this->contact,
            rights: $this->rights,
            locale: $this->locale,
            upstream: $this->upstream,
            babel: $this->babel,
            source: $this->source,
            tables: $this->tables,
            files: $this->files,
            templates: $this->templates,
            type: $this->type,
            visibility: $this->visibility,
            extras: $extras,
        );
    }

    public function withExtra(string $key, mixed $value): self
    {
        $extras = $this->extras ?? [];
        $extras[$key] = $value;

        return $this->withExtras($extras);
    }

    public function withExtraIfNotNull(string $key, mixed $value): self
    {
        if ($value !== null) {
            return $this->withExtra($key, $value);
        }

        return $this;
    }

    public function withExtraIfNotEmpty(string $key, mixed $value): self
    {
        if (!empty($value)) {
            return $this->withExtra($key, $value);
        }

        return $this;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function toArray(): array
    {
        return [
            'datasetKey' => $this->datasetKey,
            'aggregator' => $this->aggregator,
            'sourceId' => $this->sourceId,
            'label' => $this->label,
            'description' => $this->description,
            'provider' => $this->provider?->toArray(),
            'country' => $this->country?->toArray(),
            'contact' => $this->contact?->toArray(),
            'rights' => $this->rights?->toArray(),
            'locale' => $this->locale?->toArray(),
            'babel' => $this->babel?->toArray(),
            'source' => $this->source?->toArray(),
            'upstream' => $this->upstream?->toArray(),
            'tables' => $this->tables,
            'files' => $this->files,
            'templates' => $this->templates,
            'type' => $this->type,
            'visibility' => $this->visibility,
            'extras' => $this->extras,
        ];
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Invalid JSON for DatasetConfiguration');
        }

        return self::fromArray($data);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            datasetKey: $data['datasetKey'] ?? throw new \InvalidArgumentException('datasetKey required'),
            aggregator: $data['aggregator'] ?? throw new \InvalidArgumentException('aggregator required'),
            sourceId: $data['sourceId'] ?? null,
            label: $data['label'] ?? null,
            description: $data['description'] ?? null,
            provider: isset($data['provider']) ? ProviderConfiguration::fromArray($data['provider']) : null,
            country: isset($data['country']) ? CountryConfiguration::fromArray($data['country']) : null,
            contact: isset($data['contact']) ? ContactConfiguration::fromArray($data['contact']) : null,
            rights: isset($data['rights']) ? RightsConfiguration::fromArray($data['rights']) : null,
            locale: isset($data['locale']) ? LocaleConfiguration::fromArray($data['locale']) : null,
            babel: isset($data['babel']) ? BabelConfiguration::fromArray($data['babel']) : null,
            source: isset($data['source']) ? SourceConfiguration::fromArray($data['source']) : null,
            upstream: isset($data['upstream']) ? UpstreamConfiguration::fromArray($data['upstream']) : null,
            tables: $data['tables'] ?? null,
            files: $data['files'] ?? null,
            templates: $data['templates'] ?? null,
            type: $data['type'] ?? null,
            visibility: $data['visibility'] ?? null,
            extras: $data['extras'] ?? null,
        );
    }
}

final class ProviderConfiguration
{
    public function __construct(
        public readonly ?string $uri = null,
        public readonly ?array $labels = null, // ['en' => '...']
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public function withUri(string $uri): self
    {
        return new self(uri: $uri, labels: $this->labels);
    }

    public function withLabels(array $labels): self
    {
        return new self(uri: $this->uri, labels: $labels);
    }

    public function addLabel(string $lang, string $label): self
    {
        $labels = $this->labels ?? [];
        $labels[$lang] = $label;

        return new self(uri: $this->uri, labels: $labels);
    }

    public function toArray(): array
    {
        return array_filter([
            'uri' => $this->uri,
            'labels' => $this->labels,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            uri: $data['uri'] ?? null,
            labels: $data['labels'] ?? null,
        );
    }
}

final class CountryConfiguration
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $iso2 = null,
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public function withName(string $name): self
    {
        return new self(name: $name, iso2: $this->iso2);
    }

    public function withIso2(string $iso2): self
    {
        return new self(name: $this->name, iso2: $iso2);
    }

    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'iso2' => $this->iso2,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? null,
            iso2: $data['iso2'] ?? null,
        );
    }
}

final class ContactConfiguration
{
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public function withUrl(string $url): self
    {
        return new self(url: $url, phone: $this->phone, email: $this->email);
    }

    public function withPhone(?string $phone): self
    {
        return new self(url: $this->url, phone: $phone, email: $this->email);
    }

    public function withEmail(?string $email): self
    {
        return new self(url: $this->url, phone: $this->phone, email: $email);
    }

    public function toArray(): array
    {
        return array_filter([
            'url' => $this->url,
            'phone' => $this->phone,
            'email' => $this->email,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            url: $data['url'] ?? null,
            phone: $data['phone'] ?? null,
            email: $data['email'] ?? null,
        );
    }
}

final class RightsConfiguration
{
    public function __construct(
        public readonly ?string $defaultUri = null,
        public readonly ?string $statement = null,
        public readonly ?string $appliesTo = null, // 'media' | 'metadata' | 'both'
        public readonly ?bool $inferred = false,
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public function withDefaultUri(string $uri): self
    {
        return new self(
            defaultUri: $uri,
            statement: $this->statement,
            appliesTo: $this->appliesTo,
            inferred: $this->inferred,
        );
    }

    public function withStatement(?string $statement): self
    {
        return new self(
            defaultUri: $this->defaultUri,
            statement: $statement,
            appliesTo: $this->appliesTo,
            inferred: $this->inferred,
        );
    }

    public function withAppliesTo(string $appliesTo): self
    {
        return new self(
            defaultUri: $this->defaultUri,
            statement: $this->statement,
            appliesTo: $appliesTo,
            inferred: $this->inferred,
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'defaultUri' => $this->defaultUri,
            'statement' => $this->statement,
            'appliesTo' => $this->appliesTo,
            'inferred' => $this->inferred,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            defaultUri: $data['defaultUri'] ?? null,
            statement: $data['statement'] ?? null,
            appliesTo: $data['appliesTo'] ?? null,
            inferred: $data['inferred'] ?? false,
        );
    }
}

final class LocaleConfiguration
{
    public function __construct(
        public readonly string $default = 'en',
        public readonly array $targets = [],
    ) {}

    public static function create(string $default = 'en'): self
    {
        return new self(default: $default);
    }

    public function withDefault(string $default): self
    {
        return new self(default: $default, targets: $this->targets);
    }

    public function withTargets(array $targets): self
    {
        return new self(default: $this->default, targets: $targets);
    }

    public function addTarget(string $locale): self
    {
        if (in_array($locale, $this->targets, true)) {
            return $this;
        }

        return new self(default: $this->default, targets: [...$this->targets, $locale]);
    }

    public function toArray(): array
    {
        return [
            'default' => $this->default,
            'targets' => $this->targets,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            default: $data['default'] ?? 'en',
            targets: $data['targets'] ?? [],
        );
    }
}

final class UpstreamConfiguration
{
    public function __construct(
        public readonly ?string $datasetName = null,
        public readonly ?string $organizationUri = null,
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public function withDatasetName(string $name): self
    {
        return new self(datasetName: $name, organizationUri: $this->organizationUri);
    }

    public function withOrganizationUri(string $uri): self
    {
        return new self(datasetName: $this->datasetName, organizationUri: $uri);
    }

    public function toArray(): array
    {
        return array_filter([
            'datasetName' => $this->datasetName,
            'organizationUri' => $this->organizationUri,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            datasetName: $data['datasetName'] ?? null,
            organizationUri: $data['organizationUri'] ?? null,
        );
    }
}

final class BabelConfiguration
{
    public function __construct(
        public readonly ?string $source = null,
        public readonly ?array $targets = null, // list<string>
        public readonly ?string $engine = null,
    ) {}

    public static function create(?string $source = 'en'): self
    {
        return new self(source: $source);
    }

    public function withSource(string $source): self
    {
        return new self(source: $source, targets: $this->targets, engine: $this->engine);
    }

    public function withTargets(array $targets): self
    {
        return new self(source: $this->source, targets: $targets, engine: $this->engine);
    }

    public function addTarget(string $locale): self
    {
        $targets = $this->targets ?? [];
        if (!in_array($locale, $targets, true)) {
            $targets[] = $locale;
        }

        return new self(source: $this->source, targets: $targets, engine: $this->engine);
    }

    public function withEngine(string $engine): self
    {
        return new self(source: $this->source, targets: $this->targets, engine: $engine);
    }

    public function toArray(): array
    {
        return array_filter([
            'source' => $this->source,
            'targets' => $this->targets,
            'engine' => $this->engine,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            source: $data['source'] ?? null,
            targets: $data['targets'] ?? null,
            engine: $data['engine'] ?? null,
        );
    }
}

final class SourceConfiguration
{
    public function __construct(
        public readonly ?string $dir = null,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        public readonly ?string $units = null,
        public readonly ?string $country = null,
        public readonly ?string $origin = null,
        public readonly ?string $license = null,
        public readonly ?string $moderation = null,
        public readonly ?string $instructions = null,
        public readonly ?array $ignore = null,
        public readonly ?array $include = null,
        public readonly ?string $propertyCodeRule = null,
        public readonly ?int $total = null,
        public readonly ?array $links = null,
        public readonly ?int $approxImageCount = null,
    ) {}

    public static function create(): self
    {
        return new self();
    }

    public function withDir(string $dir): self
    {
        return new self(
            dir: $dir, label: $this->label, description: $this->description,
            units: $this->units, country: $this->country, origin: $this->origin,
            license: $this->license, moderation: $this->moderation,
            instructions: $this->instructions, ignore: $this->ignore,
            include: $this->include, propertyCodeRule: $this->propertyCodeRule,
            total: $this->total, links: $this->links, approxImageCount: $this->approxImageCount
        );
    }

    public function withLabel(string $label): self
    {
        return new self(
            dir: $this->dir, label: $label, description: $this->description,
            units: $this->units, country: $this->country, origin: $this->origin,
            license: $this->license, moderation: $this->moderation,
            instructions: $this->instructions, ignore: $this->ignore,
            include: $this->include, propertyCodeRule: $this->propertyCodeRule,
            total: $this->total, links: $this->links, approxImageCount: $this->approxImageCount
        );
    }

    public function withCountry(string $country): self
    {
        return new self(
            dir: $this->dir, label: $this->label, description: $this->description,
            units: $this->units, country: $country, origin: $this->origin,
            license: $this->license, moderation: $this->moderation,
            instructions: $this->instructions, ignore: $this->ignore,
            include: $this->include, propertyCodeRule: $this->propertyCodeRule,
            total: $this->total, links: $this->links, approxImageCount: $this->approxImageCount
        );
    }

    public function withIgnore(array $ignore): self
    {
        return new self(
            dir: $this->dir, label: $this->label, description: $this->description,
            units: $this->units, country: $this->country, origin: $this->origin,
            license: $this->license, moderation: $this->moderation,
            instructions: $this->instructions, ignore: $ignore,
            include: $this->include, propertyCodeRule: $this->propertyCodeRule,
            total: $this->total, links: $this->links, approxImageCount: $this->approxImageCount
        );
    }

    public function toArray(): array
    {
        return array_filter([
            'dir' => $this->dir,
            'label' => $this->label,
            'description' => $this->description,
            'units' => $this->units,
            'country' => $this->country,
            'origin' => $this->origin,
            'license' => $this->license,
            'moderation' => $this->moderation,
            'instructions' => $this->instructions,
            'ignore' => $this->ignore,
            'include' => $this->include,
            'propertyCodeRule' => $this->propertyCodeRule,
            'total' => $this->total,
            'links' => $this->links,
            'approxImageCount' => $this->approxImageCount,
        ], static fn(mixed $v): bool => $v !== null);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            dir: $data['dir'] ?? null,
            label: $data['label'] ?? null,
            description: $data['description'] ?? null,
            units: $data['units'] ?? null,
            country: $data['country'] ?? null,
            origin: $data['origin'] ?? null,
            license: $data['license'] ?? null,
            moderation: $data['moderation'] ?? null,
            instructions: $data['instructions'] ?? null,
            ignore: $data['ignore'] ?? null,
            include: $data['include'] ?? null,
            propertyCodeRule: $data['propertyCodeRule'] ?? null,
            total: $data['total'] ?? null,
            links: $data['links'] ?? null,
            approxImageCount: $data['approxImageCount'] ?? null,
        );
    }
}

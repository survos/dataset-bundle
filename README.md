# Survos Data Bundle

`survos/data-bundle` centralizes dataset filesystem conventions for
dataset-driven Symfony applications.

Despite the historical name, this bundle is not the owner of shared semantic
metadata contracts. It manages where dataset files, provider metadata, Pixie
databases, run artifacts, cache files, and related JSONL outputs live.

For shared vocabulary and typed metadata contracts, use
`survos/data-contracts`.

## Scope

This bundle provides:

- `DataPaths`: root-level path resolution under `APP_DATA_DIR`
- `DatasetPaths`: dataset-scoped path helpers
- dataset metadata loading and ensuring
- `DatasetInfo` / `Provider` registry entities
- provider snapshot encoding
- dataset context helpers for console/import workflows
- commands for browsing, diagnosing, and resolving dataset paths

This bundle does not provide:

- Dublin Core vocabulary constants
- collection-object DTO contracts
- metadata claim storage
- AI workflow execution
- media upload, IIIF, or mediary publishing
- import/normalize/profile logic

## Relationship to Other Packages

- `survos/data-contracts`: shared metadata vocabulary and DTO contracts.
- `survos/data-bundle`: dataset paths, provider storage, and dataset registry.
- `survos/import-bundle`: import/convert workflows that may ask this bundle for
  dataset paths.
- `survos/ai-workflow-bundle`: task execution in apps that own subject context.
- claims bundle: tracked metadata assertions with provenance and confidence.
- `survos/media-bundle`: media identity and mediary publishing.

The dependency direction should stay honest: packages should require
`survos/data-contracts` directly when they only need `DcTerms`, `ContentType`,
or metadata DTOs. Do not require this bundle just to get vocabulary classes.

## Core Idea

All dataset work lives under a single root directory:

```bash
APP_DATA_DIR=/absolute/path/to/data/root
```

The bundle avoids repository-relative paths and gives services and commands one
place to ask for canonical locations.

Example layout:

```text
$APP_DATA_DIR/
  work/
    <datasetKey>/
      00_meta/
        dataset.json
      10_extract/
        obj.jsonl
      20_normalize/
        obj.jsonl
      21_profile/
        obj.profile.json
      30_terms/
        *.jsonl
  pixie/
    tenants/
      <tenant>.db
    template/
    exports/
  runs/
  cache/
```

## Installation

```bash
composer require survos/data-bundle
```

Set the root directory:

```bash
export APP_DATA_DIR=/absolute/path/to/data/root
```

## Usage

Inject `DataPaths` for root and dataset path resolution:

```php
use Survos\DataBundle\Service\DataPaths;

final class SomeService
{
    public function __construct(
        private readonly DataPaths $paths,
    ) {
    }
}
```

Common dataset paths:

```php
$paths->datasetDir('dc/tb09jw350');
$paths->extractDir('dc/tb09jw350');
$paths->extractFile('dc/tb09jw350');
$paths->normalizeDir('dc/tb09jw350');
$paths->normalizeFile('dc/tb09jw350');
$paths->profileDir('dc/tb09jw350');
$paths->profileFile('dc/tb09jw350');
$paths->termsDir('dc/tb09jw350');
```

Pixie paths:

```php
$paths->pixieTenantDb('larco');
```

Operational directories:

```php
$paths->runsDir;
$paths->cacheDir;
```

## Commands

Current command names retain the historical `data:*` prefix:

```bash
bin/console data:path dc/tb09jw350 20_normalize
bin/console data:head dc/tb09jw350 20_normalize --limit=5
bin/console data:diag dc/tb09jw350
bin/console data:browse
bin/console data:scan-datasets
```

These may eventually move to `dataset:*` aliases when the bundle is renamed.

## Directory Creation

Ensure global roots exist:

```php
$paths->ensureRootDirs();
```

Ensure standard dataset stage directories exist:

```php
$paths->ensureDatasetDirs('dc/tb09jw350');
```

## Atomic File Writes

For small metadata files:

```php
$paths->atomicWrite($path, $contents);
```

The write uses a temporary file in the same directory followed by an atomic
rename.

## Design Principles

- Dataset path conventions are centralized.
- Paths are semantic, not stringly typed.
- Dataset/provider storage concerns stay separate from semantic metadata
  contracts.
- Import, AI workflow, claims, and media publishing remain in their own
  packages.
- The bundle should stay boring and infrastructure-focused.

## Future Rename

The better long-term name is `survos/dataset-bundle`. See
[`docs/rename-to-dataset-bundle.md`](docs/rename-to-dataset-bundle.md).

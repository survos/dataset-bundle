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

### Tiers (v2)

Placement is decided by one rule: *can I regenerate it from another tier + code?*

| Tier | Holds | Backed up / shipped? |
|------|-------|----------------------|
| `vault/` | acquired source + AI claims + `_vocab/` reference | yes — durable, mirror of HF/S3 |
| `cache/` | bulky re-fetchable materializations (clones, firehose, unzipped) | no |
| `work/` | pipeline output — **disposable** (`rm -rf work/<p>/<c>` is always safe) | no |
| `folio/` | built `.folio` databases | no — rebuilt from `work` |

```text
$APP_DATA_DIR/
  vault/
    <provider>/<code>/        # acquired source files
      ai/claims.jsonl         # AI claims (expensive → durable, never in work)
    _vocab/                   # global reference vocab (non-provider → _)
  cache/<provider>/...        # disposable, re-fetchable
  work/<provider>/<code>/
    _meta/dataset.json        # config (portal from code)
    _raw/                     # source view (portal → vault; often a symlink)
    norm/                     # normalized cores + term/termSet + link/linkType
    voc/                      # extracted vocab (feeds AI content_type mapping)
    trans/                    # translations
    _folio/                   # assembled folio-input (portal → folio tier)
  folio/<provider>/<code>.folio
```

Work-tree stage directory names have **no numeric prefixes** and sort in pipeline
order; `_`-prefixed dirs are **tier portals** (config / vault / folio), often symlinks,
not computed stages.

### `Stage` enum — the single source of truth

`Survos\DatasetBundle\Enum\Stage` owns stage identity and dir names. The backed value
is the stable semantic key (events, `import:convert --stage`); `Stage::dir()` is the only
place directory names live; `Stage::fromKey()` is the fail-loud string boundary (unknown →
throws). Reference `Stage` cases in code — do not pass raw stage strings.

```php
$paths->stageDir('dc/tb09jw350', Stage::Normalize); // .../norm
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

# Rename Plan: data-bundle to dataset-bundle

`survos/data-bundle` is poorly named for what it now does. The bundle manages
dataset roots, dataset-scoped paths, provider registry storage, and dataset
metadata files. It does not own shared semantic data contracts.

The intended future name is:

```text
survos/dataset-bundle
Survos\DatasetBundle
SurvosDatasetBundle
```

We are deferring the rename until the Symfony 8.1 work window so this cleanup
can close without a broad package and namespace migration.

## Current Responsibility

The bundle should remain focused on:

- resolving paths under `APP_DATA_DIR`
- dataset-stage paths such as `00_meta`, `10_extract`, `20_normalize`,
  `21_profile`, and `30_terms`
- Pixie database paths
- run/cache/archive directories
- `DatasetInfo` and `Provider` registry storage
- provider snapshots
- dataset context for console/import workflows

It should not own:

- Dublin Core vocabulary
- collection object DTO contracts
- metadata claims
- AI task execution
- mediary publication
- import conversion logic

## Phase 1: Boundary Cleanup

Completed as part of the pre-rename cleanup:

- shared DTO/vocabulary classes moved to `survos/data-contracts`
- old `Survos\DataBundle\Dto\Item`, `Metadata`, and `Vocabulary` classes removed
- `data-bundle` no longer requires `survos/data-contracts`
- README updated to define the bundle as dataset path/provider infrastructure

## Future Rename Scope

When the rename is scheduled:

1. Rename package and split entry:
   - `bu/data-bundle` to `bu/dataset-bundle`
   - `survos/data-bundle` to `survos/dataset-bundle`
   - split repository `data-bundle` to `dataset-bundle`

2. Rename namespace and bundle class:
   - `Survos\DataBundle` to `Survos\DatasetBundle`
   - `SurvosDataBundle` to `SurvosDatasetBundle`

3. Rename Symfony aliases:
   - config root `survos_data` to `survos_dataset`
   - service ids such as `survos_data.api_resource.dataset_info`
   - Twig namespace `@SurvosDataBundle` to `@SurvosDatasetBundle`
   - Doctrine mapping alias `SurvosDataBundle` to `SurvosDatasetBundle`

4. Rename command prefixes with compatibility:
   - `data:path` to `dataset:path`
   - `data:head` to `dataset:head`
   - `data:diag` to `dataset:diag`
   - `data:browse` to `dataset:browse`
   - `data:scan-datasets` to `dataset:scan`

   Keep the old `data:*` commands as aliases or deprecated wrappers for one
   release.

5. Rename awkward class names where useful:
   - `DataPaths` to `DatasetRootPaths` or `DatasetPathResolver`
   - `DataCommand` to `DatasetCommand`
   - `DataPathCommand` to `DatasetPathCommand`

   `DatasetPaths`, `DatasetContext`, `DatasetResolver`, and `DatasetInfo`
   already align with the target name.

## Compatibility Strategy

Prefer a staged migration:

- first introduce new names and aliases;
- update apps and bundles;
- keep old commands and selected class aliases for one minor release if needed;
- then remove compatibility in the next major cleanup.

Avoid mixing this rename with semantic metadata work. Shared vocabulary and DTOs
belong in `survos/data-contracts`; tracked metadata assertions belong in the
claims bundle.

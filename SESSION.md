# Session Summary — data-bundle

## Key Changes

### New: `ContentType` class
- `src/Metadata/ContentType.php`
- Constants for all museum content types (photograph, postcard, map, manuscript, etc.)
- LOC URI map (`URIS[]`) for RDF export / zm ResourceClass
- `OCR_TYPES[]` and `VISUAL_AI_TYPES[]` — drive pipeline decisions
- `GENRE_SPECIFIC_MAP`, `GENRE_BASIC_MAP`, `TYPE_OF_RESOURCE_MAP` for DC field derivation
- `fromDcAttrs(array $attrs): string` — static derivation from DC normalized record
- `needsOcr(string $contentType): bool`
- `needsVisualAi(string $contentType): bool`

### New: `DcTerms` enum
- `src/Vocabulary/DcTerms.php`
- All 55 dcterms: values as enum cases
- `localName()`, `uri()`, `label()`, `fromTerm()`, `allTerms()`, `dcElements()`, `neverExtract()`
- Used by `BaseItemDto::toSourceMeta()` and `MediaBatchDispatcher::dispatchEnrichments()`

### New: `BaseItemDto` + typed subclasses
- `src/Dto/Item/BaseItemDto.php` — abstract base with all core DC fields
- `fromNormalized(array $row): static` — populate from 20_normalize/obj.jsonl
- `fromSourceMeta(array $meta): static` — **NEW**: auto-convert JSON blob via DcTerms enum
- `toSourceMeta(): array` — serialize back to dcterms: keyed blob
- `toMeili(): array`, `toValueMap(): array`
- `$typeRegistry` — register content_type slug → DTO class mappings
- Subclasses: `PhotographDto`, `PostcardDto`, `NewspaperDto`, `MapDto`

### New: `DataCommand` abstract base
- `src/Command/DataCommand.php`
- `#[Required] setDataPaths()` — injects `DataPaths` into any extending command

### New: `DatasetInfo` entity
- `src/Entity/DatasetInfo.php`
- Registry of all known datasets from 00_meta/dataset.yaml scanning
- Fields: datasetKey, label, description, aggregator, locale, country, status
- Pipeline status: discovered → raw → normalized → profiled → pixie → indexed
- `cores[]`, `fields[]`, `meiliSettings[]`, `meta[]` compiled from profile
- `pixieDbPath`, `pixieDbSize` — tracks pixie SQLite DB
- `hasRaw()`, `hasNormalized()`, `hasProfile()`, `isReadyForPixie()` helpers

### New: `ScanDatasetsCommand`
- `src/Command/ScanDatasetsCommand.php`
- Scans APP_DATA_DIR for 00_meta/dataset.yaml + pixie DB directory
- Populates `DatasetInfo` registry — one-time scan, then use DB for all lookups
- `--provider`, `--force`, `--status-only`, `--pixie-dir` options

### Updated: `SurvosDataBundle`
- Doctrine mapping auto-registered for `DatasetInfo` entity via `prependExtension`
- `ScanDatasetsCommand` registered as a service

## TODO
- `DatasetInfo::pixieRowCount` not yet populated (needs pixie:ingest to update it)
- `DatasetInfo::meiliDocCount` not yet populated
- More typed DTO subclasses needed: `ObjectDto`, `ManuscriptDto`, `CorrespondenceDto`, `NegativeDto`
- `FieldMapService` (in config/field_map.yaml) needs to read from `BaseItemDto` properties

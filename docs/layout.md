# Data Directory Plan for `data-bundle`

## Goal

Separate **portable archival material** from **fast working data** and from **rebuildable downstream outputs**, so the system is easier to reason about, faster on Linux-native filesystems, and avoids directory sprawl.

This plan assumes:

- archive data may be moved between machines and external drives
- work data lives on fast local Linux storage (ext4)
- manifests are **JSON**, not YAML
- datasets must be reproducible from archive + code

---

## Core Principles

1. **Archive vs Work separation**
   - Archive = slow/expensive to reacquire, portable
   - Work = fast, mutable, optimized for processing

2. **Provenance over file format**
   - Use `archive/`, not `zips/`
   - Not all source data is zipped

3. **JSON manifests everywhere**
   - `dataset.json`, not YAML
   - Faster parsing at scale

4. **Archive only what matters**
   - Source data (downloaded, scraped, received)
   - Expensive AI outputs
   - Canonical snapshots

5. **Work is disposable**
   - Must be rebuildable from archive + code

---

## Top-Level Layout

```text
<dataRoot>/
├── archive/
├── work/
├── build/
└── registry/
```

---

## archive/

Portable, durable, rebuildable inputs and enrichments.

```text
archive/<provider>/<tenant>/
├── manifests/
├── acquisition/
├── source/
├── ai/
└── packages/
```

### manifests/

```text
manifests/
├── dataset.json
├── acquisition.json
├── checksums.json
└── rebuild.json
```

### acquisition/

Raw acquisition outputs (API, scraping, etc.)

```text
archive/dc/global/acquisition/api-pages/
├── page-0001.jsonl.gz
├── page-0002.jsonl.gz
```

### source/

Canonical source material (received or normalized)

#### Direct download (Europeana)
```text
archive/europeana/9200509/source/download/
├── 9200509.zip
```

#### Physical / client-provided data (Nabolom)
```text
archive/nabolom/main/source/drop-2026-04-03/
├── images/
├── spreadsheets/
```

### ai/

Preserved AI outputs (only expensive / valuable ones)

```text
archive/<provider>/<tenant>/ai/
├── runs/
│   ├── 2026-04-03-openai-keywords/
│   └── 2026-04-03-ocr/
├── manifests/
└── packages/
```

### packages/

Portable bundled snapshots

```text
packages/
├── raw-snapshot.tar.zst
├── ai-snapshot.tar.zst
└── full-rebuild.tar.zst
```

---

## work/

Fast, local, Linux-native processing tree (ext4)

```text
work/<provider>/<tenant>/
├── dataset.json
├── 00_meta/
├── 05_raw/
├── 10_extract/
├── 20_normalize/
├── 21_profile/
├── 30_enrich/
├── 35_ai/
├── 40_index/
└── 90_tmp/
```

### Notes

- `05_raw` = canonical raw working files
- `20_normalize` = normalized JSONL
- `35_ai` = active AI results used in pipeline
- `90_tmp` = scratch only

Work data:
- must be fast
- can be deleted
- must be reproducible

---

## build/

Recreatable downstream outputs

```text
build/
├── pixie/
├── zm/
├── meili/
└── exports/
```

Do NOT treat this as archive.

---

## registry/

Optional global metadata

```text
registry/
├── providers.json
├── tenants.json
└── datasets/
```

---

## Source Types (Important)

Replace “zip vs not zip” with **source class**:

### A. Downloaded source
```text
source/download/*.zip
```

### B. API acquisition
```text
acquisition/api-pages/*.jsonl.gz
```

### C. Physical drop / shared drive
```text
source/drop-YYYY-MM-DD/
```

### D. Canonical repackaged source
```text
packages/raw-snapshot.tar.zst
```

---

## AI Storage Policy

### Always archive:
- batch inputs/outputs
- OCR / transcription
- prompts/templates
- run metadata

### Sometimes archive:
- per-record enrichments if costly

### Do NOT archive:
- temporary intermediates
- debug artifacts

---

## Manifest Example (`dataset.json`)

```json
{
  "provider": "dc",
  "tenant": "global",
  "dataset_id": "dc/global",
  "source_type": "api_capture",
  "created_at": "2026-04-03T12:00:00Z",
  "archive": {
    "acquisition": [
      {
        "kind": "api_pages",
        "path": "acquisition/api-pages/",
        "format": "jsonl.gz"
      }
    ],
    "source": [
      {
        "kind": "canonical_snapshot",
        "path": "packages/raw-snapshot.tar.zst"
      }
    ],
    "ai": [
      {
        "run_id": "2026-04-03-openai-keywords",
        "model": "gpt-5-mini",
        "path": "ai/packages/ai-snapshot.tar.zst"
      }
    ]
  },
  "rebuild": {
    "raw_from": "acquisition/api-pages/",
    "normalize_from": "work/20_normalize/",
    "index_from": "work/40_index/"
  }
}
```

---

## Naming Rules

- `<provider>/<tenant>` is the primary key
- Optional third level only if needed:
  ```text
  <provider>/<tenant>/<dataset_variant>
  ```

---

## Packaging Recommendations

- Use `tar.zst` for internal bundles
- Keep vendor ZIPs as-is
- Use `.jsonl.gz` for streaming datasets

---

## Anti-Sprawl Rule

Each dataset may ONLY contain:

```text
acquisition/
source/
ai/
packages/
manifests/
```

No extra top-level folders allowed.

---

## Final Mental Model

- archive = durable truth
- work = fast computation
- build = reproducible outputs

Everything else should map cleanly to one of those.

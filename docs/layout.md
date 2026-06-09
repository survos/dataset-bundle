# Data Layout

> **Superseded.** This file used to hold an early `archive/ work/ build/ registry/` plan with
> numeric stage prefixes (`05_raw`, `20_normalize`, …). That model is **not** what the code does.
>
> The canonical, current spec is **`md/docs/data-layout.md`** (Data Layout v2):
> the `vault / cache / work / folio` tiers, the disposable-`work` rule, raw + AI claims canonical
> in `vault`, `_raw` as a symlink portal to the vault, and the `Stage` enum as the single source of
> truth for stage directory names.

## What this bundle owns

`Survos\DatasetBundle\Service\DataPaths` is the implementation of that spec — the only place that
turns a dataset key + tier/stage into a path:

- `providerRawFile($key)` → `vault/<provider>/<code>/obj.jsonl[.gz]` (canonical raw)
- `aiDir($key)` / `claimsFile($key)` → `vault/<provider>/<code>/ai/…` (durable AI claims)
- `stageDir($key, Stage)` → `work/<provider>/<code>/<dir>` (`Stage::dir()` owns the dir name)
- `stageFileCandidates($key, stage, file)` → preferred read order (work `_raw` → vault → legacy)
- `folioFile($key)` → `folio/<provider>/<code>.folio`

`Survos\DatasetBundle\Enum\Stage` owns stage identity: backed value = semantic key, `dir()` is the
only place dir-name strings live, `fromKey()` is the fail-loud string boundary.

Change the layout? Edit `md/docs/data-layout.md` and `DataPaths`/`Stage` together — not this note.

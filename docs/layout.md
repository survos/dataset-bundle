# Data Layout

> **Superseded.** This file used to hold an early `archive/ work/ build/ registry/` plan with
> numeric stage prefixes (`05_raw`, `20_normalize`, …). That model is **not** what the code does.
>
> The canonical, current spec is **`md/docs/data-layout.md`** (Data Layout v2, in the `harvest`
> repo — `md` was renamed): the `vault / cache / work / folio` tiers, the disposable-`work` rule,
> raw + AI claims canonical in `vault`, and the `Stage` enum as the single source of truth for
> stage directory names.
>
> **`_raw` is no longer a symlink portal.** Symlinking proved too messy; the code resolves where
> raw lives instead (`ensureRawPortal()` hands back the vault path directly, and removes a legacy
> symlink if it finds one). Do not reintroduce symlinks for storage placement — they cannot
> express "directories the workflow has not created yet."

## What this bundle owns

`Survos\DatasetBundle\Service\DataPaths` is the implementation of that spec — the only place that
turns a dataset key + tier/stage into a path:

- `providerRawFile($key)` → `vault/<provider>/<code>/obj.jsonl[.gz]` (canonical raw)
- `aiDir($key)` / `claimsFile($key)` → `vault/<provider>/<code>/ai/…` (durable AI claims)
- `stageDir($key, Stage)` → `work/<provider>/<code>/<dir>` (`Stage::dir()` owns the dir name)
- `stageFileCandidates($key, stage, file)` → preferred read order (work `_raw` → vault → legacy)
- `folioFile($key)` → `folio/<provider>/<code>.folio`
- `captureDir($provider)` / `captureFile($provider, $rel)` → `<capture_root>/<provider>/_capture/…`

### `capture_root` — capture may live on other storage than the vault

`_capture` and `_raw` sit side by side under `vault/<provider>/` but have opposite access
patterns, so they may want opposite storage:

| | pattern | size seen in the wild |
|---|---|---|
| `_capture/*.zip` | write once, read once, sequential | 273 GB of euro zips; nara ships **one 174 GB** export |
| `<code>/_raw/*` | re-read in full on every normalize | 1.05 GB at the largest, 0.16 GB typical |

`capture_root` (null/empty = **same as `zips_root`**, so apps that don't set it are unaffected)
resolves exactly like `zips_root`: absolute paths as-is, relative under `data_dir`.

The motivating case: a read-caching S3 mount is a clear win for raw and actively wrong for
capture, because reading that one 174 GB nara zip through the cache evicts every cached raw file
behind it. Two roots → two mounts → one config value, with no symlinks.

`Survos\DatasetBundle\Enum\Stage` owns stage identity: backed value = semantic key, `dir()` is the
only place dir-name strings live, `fromKey()` is the fail-loud string boundary.

Change the layout? Edit `md/docs/data-layout.md` and `DataPaths`/`Stage` together — not this note.

<?php
declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Survos\DatasetBundle\Enum\Stage;
use Symfony\Component\Filesystem\Filesystem;

use function preg_replace;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * Canonical layout under APP_DATA_DIR:
 *
 *   $APP_DATA_DIR/
 *     data/<datasetKey>/
 *       00_meta/
 *       _raw/
 *       extract/
 *       norm/
 *       profile/
 *       voc/
 *     artifacts/...
 *     runs/...
 *     cache/...
 */
final class DataPaths
{
    private ?Filesystem $fs = null;

    public function sanitizeDatasetKey(string $code): string
    {
        return $this->datasetKeyFromRef($code);
    }

    /**
     * Parse a dataset reference.
     *
     * Accepts:
     *  - provider/code (preferred)
     *  - provider-code (legacy)
     *  - code (treated as provider/code for provider-scoped dirs)
     *
     * @return array{provider:string,code:string,key:string}
     */
    public function parseDatasetRef(string $ref): array
    {
        $ref = trim($ref);
        if ($ref === '') {
            throw new \InvalidArgumentException('Dataset cannot be empty.');
        }

        $provider = '';
        $code = '';

        if (str_contains($ref, '/')) {
            [$provider, $code] = explode('/', $ref, 2);
        } elseif (str_contains($ref, '-')) {
            [$provider, $code] = explode('-', $ref, 2);
        } else {
            $provider = $ref;
            $code = $ref;
        }

        $provider = $this->sanitizeToken($provider);
        $code = $this->sanitizeToken($code);

        $key = $provider . '-' . $code;
        return ['provider' => $provider, 'code' => $code, 'key' => $key];
    }

    public function datasetKeyFromRef(string $ref): string
    {
        return $this->parseDatasetRef($ref)['key'];
    }

    private function sanitizeToken(string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('Dataset token cannot be empty.');
        }

        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $token) ?? $token;
        $safe = trim($safe, '_');

        if (
            $safe === ''
            || str_contains($safe, '..')
            || str_starts_with($safe, '/')
            || str_contains($safe, "\0")
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid token "%s".', $token));
        }

        return strtolower($safe);
    }

    public function __construct(
        public private(set) string $dataDir,
        public private(set) string $worksRoot = 'work',
        public private(set) string $datasetRoot = 'work', // renamed, 4/3
        public private(set) string $artifactRoot = 'artifacts',
        public private(set) string $runsRoot = 'runs',
        public private(set) string $cacheRoot = 'cache',
        public private(set) string $zipsRoot = 'vault',
        public private(set) string $defaultObjectFilename = 'obj.jsonl',
    ) {
    }

    public string $root { get => rtrim($this->dataDir, '/'); }

    public string $datasetsRoot { get => "{$this->root}/{$this->datasetRoot}"; }
    public string $workRoot { get => "{$this->root}/{$this->worksRoot}"; }
    public string $artifactRootDir { get => "{$this->root}/{$this->artifactRoot}"; }
    public string $runsRootDir { get => "{$this->root}/{$this->runsRoot}"; }
    public string $cacheRootDir { get => "{$this->root}/{$this->cacheRoot}"; }
    public string $folioRootDir { get => "{$this->root}/folio"; }
    public string $folioArchiveRootDir { get => "{$this->root}/folio-archive"; }
    public string $zipsRootDir {
        get => str_starts_with($this->zipsRoot, '/')
            ? rtrim($this->zipsRoot, '/')
            : "{$this->root}/{$this->zipsRoot}";
    }

    public function filesystem(): Filesystem
    {
        return $this->fs ??= new Filesystem();
    }

    public function datasetDir(string $datasetKey): string
    {
        $parsed = $this->parseDatasetRef($datasetKey);
        return "{$this->workRoot}/{$parsed['provider']}/{$parsed['code']}";
    }

    public function providerRoot(string $providerId): string
    {
        $provider = $this->sanitizeToken($providerId);

        return "{$this->workRoot}/{$provider}";
    }

    public function candidateJsonlFilename(string $providerId): string
    {
        return $this->providerRoot($providerId) . '/candidates.jsonl';
    }

    public function providerArchiveRoot(string $providerId): string
    {
        $provider = $this->sanitizeToken($providerId);

        return "{$this->zipsRootDir}/{$provider}";
    }

    public function providerArchiveFile(string $providerId, string $relativePath): string
    {
        $relativePath = ltrim(trim($relativePath), '/');
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '..')) {
            throw new \InvalidArgumentException('Invalid archive relative path.');
        }

        return $this->providerArchiveRoot($providerId) . '/' . $relativePath;
    }

    /**
     * Provider-level acquired source pages — listing pages, API responses, HTML
     * captures — durable in the vault: vault/<provider>/_pages. This is the
     * "acquire" (provider:*:vault) output; raw cores are derived from it.
     *
     * Created lazily (only pass $create=true when actually about to write).
     */
    public function pagesDir(string $providerId, bool $create = false): string
    {
        $dir = $this->providerArchiveRoot($providerId) . '/_pages';
        if ($create) {
            $this->filesystem()->mkdir($dir);
        }

        return $dir;
    }

    /** Canonical durable raw JSONL artifact: vault/<provider>/<code>/<core>.jsonl[.gz]. */
    public function providerRawFile(string $datasetKey, string $file = 'obj.jsonl.gz'): string
    {
        $parsed = $this->parseDatasetRef($datasetKey);

        return $this->providerArchiveFile($parsed['provider'], $parsed['code'] . '/' . ltrim($file, '/'));
    }

    /** The dataset's durable vault directory: vault/<provider>/<code>. */
    public function vaultDatasetDir(string $datasetKey): string
    {
        $parsed = $this->parseDatasetRef($datasetKey);

        return $this->providerArchiveRoot($parsed['provider']) . '/' . $parsed['code'];
    }

    /**
     * Materialize the `_raw` stage as a **tier portal**: a symlink from
     * work/<p>/<c>/_raw → vault/<p>/<c>. Raw written through the `_raw` path then lands in the
     * vault (the canonical, durable home), and `rm -rf work/<p>/<c>` only drops the link, never the
     * raw bytes. See md/docs/data-layout.md.
     *
     * Safe & idempotent: creates the vault dir, points (or re-points) the symlink. A pre-existing
     * *non-empty real* `_raw` directory is left untouched — converting that is an explicit migration,
     * not a silent side effect of asking for the path.
     */
    public function ensureRawPortal(string $datasetKey): string
    {
        $rawDir   = $this->datasetDir($datasetKey) . '/' . Stage::Raw->dir();
        $vaultDir = $this->vaultDatasetDir($datasetKey);

        $fs = $this->filesystem();
        $fs->mkdir($vaultDir);
        $fs->mkdir(dirname($rawDir)); // work/<p>/<c>

        if (is_link($rawDir)) {
            if (realpath($rawDir) === realpath($vaultDir)) {
                return $rawDir; // already the correct portal
            }
            $fs->remove($rawDir); // wrong target → re-point
        } elseif (is_dir($rawDir)) {
            if (!$this->isDirEmpty($rawDir)) {
                return $rawDir; // legacy real raw dir — leave for explicit migration
            }
            $fs->remove($rawDir);
        }

        $fs->symlink($vaultDir, $rawDir);

        return $rawDir;
    }

    private function isDirEmpty(string $dir): bool
    {
        foreach (new \FilesystemIterator($dir) as $_) {
            return false;
        }

        return true;
    }

    public function folioFile(string $datasetKey, string $extension = 'folio', bool $create = false): string
    {
        $parsed = $this->parseDatasetRef($datasetKey);
        $path = sprintf('%s/%s/%s.%s', $this->folioRootDir, $parsed['provider'], $parsed['code'], ltrim($extension, '.'));

        if ($create) {
            $this->filesystem()->mkdir(dirname($path));
        }

        return $path;
    }

    /**
     * Path to the index-free distributable folio archive, e.g.
     * <root>/folio-archive/<provider>/<code>.folio.gz. Sibling of folioFile() so durable,
     * re-uploadable archives are not co-mingled with the rebuildable working folio cache.
     */
    public function folioArchiveFile(string $datasetKey, string $extension = 'folio.gz', bool $create = false): string
    {
        $parsed = $this->parseDatasetRef($datasetKey);
        $path = sprintf('%s/%s/%s.%s', $this->folioArchiveRootDir, $parsed['provider'], $parsed['code'], ltrim($extension, '.'));

        if ($create) {
            $this->filesystem()->mkdir(dirname($path));
        }

        return $path;
    }

    public function folioBootstrapFile(string $extension = 'folio', bool $create = false): string
    {
        $path = sprintf('%s/_bootstrap.%s', $this->folioRootDir, ltrim($extension, '.'));

        if ($create) {
            $this->filesystem()->mkdir(dirname($path));
        }

        return $path;
    }

    /**
     * Resolve a stage directory for a dataset.
     *
     * Accepts either:
     *  - semantic keys: raw|normalize|profile|terms|...
     *  - canonical stage dirs: _raw|norm|...
     */
    public function stageDir(string $datasetKey, Stage|string $stage, bool $create = false): string
    {
        $resolved = $stage instanceof Stage ? $stage : Stage::fromKey($stage); // unknown → throws
        $path = $this->datasetDir($datasetKey) . '/' . $resolved->dir();

        if ($create) {
            // Raw is a tier portal: materialize _raw as a symlink to the vault, not a real dir.
            if ($resolved === Stage::Raw) {
                return $this->ensureRawPortal($datasetKey);
            }
            $this->filesystem()->mkdir($path);
        }

        return $path;
    }

    /**
     * Return candidate files for a dataset stage, in preferred read order.
     *
     * Raw stages may be backed by a staged file in work/<provider>/<code>/_raw,
     * by the canonical vault raw file at vault/<provider>/<code>/obj.jsonl(.gz),
     * or by a legacy flat provider archive at vault/<provider>/<code>.jsonl(.gz).
     *
     * @return list<string>
     */
    public function stageFileCandidates(string $datasetKey, ?string $stage = null, ?string $file = null): array
    {
        $stage ??= 'normalize';
        $stageDir = $this->stageDir($datasetKey, $stage);

        if ($file !== null && $file !== '') {
            return [rtrim($stageDir, '/') . '/' . ltrim($file, '/')];
        }

        if ($stage === 'meta' || $stage === '00_meta') {
            return [$stageDir];
        }

        $candidates = [rtrim($stageDir, '/') . '/' . $this->defaultObjectFilename];

        if ($stage === 'raw' || $stage === '05_raw') {
            $candidates[] = $candidates[0] . '.gz';
            $parsed = $this->parseDatasetRef($datasetKey);
            $candidates[] = $this->providerRawFile($datasetKey, $this->defaultObjectFilename . '.gz');
            $candidates[] = $this->providerRawFile($datasetKey, $this->defaultObjectFilename);
            $candidates[] = $this->providerArchiveFile($parsed['provider'], $parsed['code'] . '.jsonl.gz');
            $candidates[] = $this->providerArchiveFile($parsed['provider'], $parsed['code'] . '.jsonl');
        }

        return array_values(array_unique($candidates));
    }

    public function firstReadableStageFile(string $datasetKey, ?string $stage = null, ?string $file = null): ?string
    {
        foreach ($this->stageFileCandidates($datasetKey, $stage, $file) as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function normalizeRawFilename(string $filename): string
    {
        $name = trim($filename);
        if ($name === '') {
            throw new \InvalidArgumentException('Filename cannot be empty.');
        }

        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name) ?? $name;
        $name = trim($name, '_');
        if ($name === '') {
            throw new \InvalidArgumentException('Invalid filename.');
        }

        return $name;
    }

    public function rawFileFromUrl(string $datasetKey, string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $base = trim(basename($path));
        if ($base === '') {
            $base = md5($url) . '.bin';
        }

        return $this->stageDir($datasetKey, 'raw') . '/' . $this->normalizeRawFilename($base);
    }

    // ── AI output paths ───────────────────────────────────────────────────────
    //
    // Layout:
    //   $APP_DATA_DIR/
    //     data/
    //       dc/
    //         nv935r28t/
    //           40_ai/
    //             batch_abc123.jsonl   ← provider batch ID, not our DB id
    //             batch_def456.jsonl
    //
    // Keyed by the provider batch ID (e.g. OpenAI's "batch_abc123"), NOT our
    // local integer ID — so files survive DB resets and are portable.

    /**
     * AI claims/batch dir — expensive (LLM spend), so it lives in the durable VAULT,
     * never the disposable work tree: vault/<provider>/<code>/ai/.
     */
    public function aiDir(string $datasetKey): string
    {
        $parsed = $this->parseDatasetRef($datasetKey);

        return $this->providerArchiveRoot($parsed['provider']) . '/' . $parsed['code'] . '/ai';
    }

    /** Canonical path for a dataset's AI claims (vault/<p>/<c>/ai/). */
    public function claimsFile(string $datasetKey, string $filename = 'claims.jsonl'): string
    {
        return $this->ensureAiDir($datasetKey) . '/' . $filename;
    }

    /** Canonical path for the enriched (normalize + AI) JSONL and its profile. */
    public function enrichFile(string $datasetKey, string $core = 'obj'): string
    {
        return $this->stageDir($datasetKey, 'enrich') . '/' . $core . '.jsonl';
    }

    /**
     * Full path to a saved AI batch result JSONL for a dataset.
     *
     * @param string $datasetKey      e.g. "dc/nv935r28t" or "cheztac"
     * @param string $providerBatchId e.g. "batch_abc123" (from OpenAI/Anthropic)
     */
    public function aiBatchResultFile(string $datasetKey, string $providerBatchId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $providerBatchId) ?? $providerBatchId;
        return "{$this->aiDir($datasetKey)}/{$safe}.jsonl";
    }

    public function ensureAiDir(string $datasetKey): string
    {
        $dir = $this->aiDir($datasetKey);
        $this->filesystem()->mkdir($dir);
        return $dir;
    }

    // ── Shared vocab paths (language-level, NOT per-dataset) ─────────────────
    //
    // Layout:
    //   $APP_DATA_DIR/
    //     vocab/
    //       fra/
    //         dto_map.jsonl    ← keyword → ContentType classifications (shared)
    //         labels.jsonl     ← ContentType slug → display label (shared)
    //     translation/          ← stub for future translation memory

    public string $vocabDir { get => "{$this->zipsRootDir}/_vocab"; }

    public function vocabLangDir(string $lang, bool $create = false): string
    {
        $dir = "{$this->vocabDir}/{$lang}";
        if ($create) {
            $this->filesystem()->mkdir($dir);
        }
        return $dir;
    }

    /** Keyword → ContentType classification cache file for a language. */
    public function vocabMapFile(string $lang): string
    {
        return $this->vocabLangDir($lang) . '/dto_map.jsonl';
    }

    /** ContentType slug → display label cache file for a language. */
    public function vocabLabelsFile(string $lang): string
    {
        return $this->vocabLangDir($lang) . '/labels.jsonl';
    }

    // ── Translation paths (stub — translation memory, future use) ────────────
    //
    // Intended for cached AI translations of record field values (titles,
    // descriptions), distinct from vocab labels which are ContentType-scoped.

    public string $translationDir { get => "{$this->root}/translation"; }

    public function translationLangDir(string $lang, bool $create = false): string
    {
        $dir = "{$this->translationDir}/{$lang}";
        if ($create) {
            $this->filesystem()->mkdir($dir);
        }
        return $dir;
    }
}

<?php

declare(strict_types=1);

namespace Survos\DatasetBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal HuggingFace Hub client over plain HTTP — no CLI, no git-LFS binary.
 *
 * Pull (read, no token): list the dataset's file tree, then stream each file
 * from its resolve URL (which 302-redirects through the xet/LFS storage).
 *
 * Push (write, needs HF_TOKEN): preupload → git-LFS batch (presigned PUT) →
 * NDJSON commit. Files already present (by sha256) are skipped automatically.
 *
 * @see https://huggingface.co/docs/hub/api
 */
final class HfHubClient
{
    private const BASE = 'https://huggingface.co';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::HF_TOKEN)%')]
        private readonly ?string $token = null,
    ) {
    }

    /**
     * Create a new dataset repo. No-ops (returns false) if it already exists — HF's create
     * endpoint 409s in that case, which we treat as "fine, keep going" rather than an error,
     * so callers can unconditionally call this before uploadFiles() without a separate exists
     * check racing against it.
     *
     * @param string $repo "namespace/name", e.g. "fortepan/bva" — namespace is the HF org or
     *                     username (must already exist and the token must have write access to
     *                     it; the HF API cannot create an *organization*, only repos within one).
     * @return bool true if created, false if it already existed
     */
    public function createRepo(string $repo, bool $private = false): bool
    {
        if ($this->token === null || $this->token === '') {
            throw new \RuntimeException('HF_TOKEN is required to create a repo. Set it in .env.local.');
        }

        [$namespace, $name] = array_pad(explode('/', $repo, 2), 2, null);
        if ($namespace === null || $name === null) {
            throw new \RuntimeException('Repo must be "namespace/name", got: ' . $repo);
        }

        $response = $this->httpClient->request('POST', sprintf('%s/api/repos/create', self::BASE), $this->auth([
            'json' => [
                'type' => 'dataset',
                'organization' => $namespace,
                'name' => $name,
                'private' => $private,
            ],
        ]));

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return true;
        }
        if ($status === 409) {
            return false;
        }

        throw new \RuntimeException(sprintf('HF repo create failed (%d) for %s: %s', $status, $repo, $response->getContent(false)));
    }

    /**
     * List the files in a dataset repo at a revision.
     *
     * @return list<array{path: string, size: int}>
     */
    public function listFiles(string $repo, string $revision = 'main'): array
    {
        $url = sprintf('%s/api/datasets/%s/tree/%s?recursive=1', self::BASE, $repo, rawurlencode($revision));
        $rows = $this->httpClient->request('GET', $url, $this->auth())->toArray();

        $out = [];
        foreach ($rows as $row) {
            if (($row['type'] ?? null) === 'file') {
                $out[] = ['path' => (string) $row['path'], 'size' => (int) ($row['size'] ?? 0)];
            }
        }
        return $out;
    }

    public function resolveUrl(string $repo, string $path, string $revision = 'main'): string
    {
        return sprintf('%s/datasets/%s/resolve/%s/%s', self::BASE, $repo, rawurlencode($revision), $path);
    }

    /**
     * Stream one file to disk (atomic via a .part temp). Returns bytes written.
     */
    public function download(string $repo, string $path, string $dest, string $revision = 'main'): int
    {
        $response = $this->httpClient->request('GET', $this->resolveUrl($repo, $path, $revision), $this->auth([
            'max_redirects' => 5,
        ]));
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('HF download HTTP %d for %s/%s', $response->getStatusCode(), $repo, $path));
        }

        if (!is_dir(\dirname($dest))) {
            @mkdir(\dirname($dest), 0777, true);
        }
        $tmp = $dest . '.part';
        $fh = fopen($tmp, 'wb');
        if ($fh === false) {
            throw new \RuntimeException('Cannot open for write: ' . $tmp);
        }
        $bytes = 0;
        foreach ($this->httpClient->stream($response) as $chunk) {
            $bytes += (int) fwrite($fh, $chunk->getContent());
        }
        fclose($fh);
        rename($tmp, $dest);
        return $bytes;
    }

    /**
     * Upload local files to the dataset repo: LFS batch (presigned PUT) + commit.
     * Files whose sha256 already exists in the repo's LFS store are skipped.
     *
     * @param array<string, string> $files  map of repo-path => local absolute path
     * @return array{uploaded: list<string>, skipped: list<string>}
     */
    public function uploadFiles(string $repo, array $files, string $revision = 'main', string $summary = 'Update via hf:push'): array
    {
        if ($this->token === null || $this->token === '') {
            throw new \RuntimeException('HF_TOKEN is required to push. Set it in .env.local.');
        }

        // 1. Describe each file by sha256 + size.
        $objects = [];
        foreach ($files as $repoPath => $localPath) {
            if (!is_file($localPath)) {
                throw new \RuntimeException('Missing local file: ' . $localPath);
            }
            $objects[$repoPath] = [
                'oid'  => hash_file('sha256', $localPath),
                'size' => (int) filesize($localPath),
                'path' => $localPath,
            ];
        }

        // 2. git-LFS batch — get presigned upload actions (missing actions == already present).
        $batch = $this->httpClient->request('POST', sprintf('%s/datasets/%s.git/info/lfs/objects/batch', self::BASE, $repo), $this->auth([
            'headers' => [
                'Accept'       => 'application/vnd.git-lfs+json',
                'Content-Type' => 'application/vnd.git-lfs+json',
            ],
            'json' => [
                'operation' => 'upload',
                'transfers' => ['basic'],
                'hash_algo' => 'sha256',
                'objects'   => array_values(array_map(static fn ($o) => ['oid' => $o['oid'], 'size' => $o['size']], $objects)),
            ],
        ]))->toArray();

        $actionByOid = [];
        foreach ($batch['objects'] ?? [] as $o) {
            $actionByOid[$o['oid']] = $o['actions']['upload'] ?? null; // null when already stored
        }

        $uploaded = [];
        $skipped = [];
        foreach ($objects as $repoPath => $o) {
            $action = $actionByOid[$o['oid']] ?? null;
            if ($action === null) {
                $skipped[] = $repoPath;
                continue;
            }
            // 3. PUT the bytes to the presigned URL.
            $stream = fopen($o['path'], 'rb');
            $this->httpClient->request('PUT', $action['href'], [
                'headers' => $action['header'] ?? [],
                'body'    => $stream,
            ])->getStatusCode();
            if (is_resource($stream)) {
                fclose($stream);
            }
            $uploaded[] = $repoPath;
        }

        // 4. NDJSON commit (header + one lfsFile op per file).
        $lines = [json_encode(['key' => 'header', 'value' => ['summary' => $summary, 'description' => '']], JSON_THROW_ON_ERROR)];
        foreach ($objects as $repoPath => $o) {
            $lines[] = json_encode(['key' => 'lfsFile', 'value' => [
                'path' => $repoPath, 'algo' => 'sha256', 'oid' => $o['oid'], 'size' => $o['size'],
            ]], JSON_THROW_ON_ERROR);
        }
        $this->httpClient->request('POST', sprintf('%s/api/datasets/%s/commit/%s', self::BASE, $repo, rawurlencode($revision)), $this->auth([
            'headers' => ['Content-Type' => 'application/x-ndjson'],
            'body'    => implode("\n", $lines) . "\n",
        ]))->getStatusCode();

        return ['uploaded' => $uploaded, 'skipped' => $skipped];
    }

    /** @param array<string, mixed> $extra */
    private function auth(array $extra = []): array
    {
        if ($this->token !== null && $this->token !== '') {
            $extra['headers'] = array_merge($extra['headers'] ?? [], ['Authorization' => 'Bearer ' . $this->token]);
        }
        return $extra;
    }
}

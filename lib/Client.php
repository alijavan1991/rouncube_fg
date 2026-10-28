<?php

use GuzzleHttp\Exception\GuzzleException;

/**
 * A Zemzemeh failure. `kind` is one of: auth, not_found, too_large, changed, quota, unavailable.
 * Messages never contain token material or response bodies.
 */
final class zemzemeh_dlp_client_exception extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}

/**
 * Server-to-server access to Zemzemeh's /api/mail endpoints as the signed-in user.
 */
final class zemzemeh_dlp_client
{
    private const FILE_ID = '/^[0-9a-f]{32}$/';
    private const VERSION = '/^[0-9a-f]{64}$/';
    private const CHUNK = 65536;

    private string $base;
    private $http;

    public function __construct(string $base_url, private string $token, private bool $verify_tls, int $timeout)
    {
        $this->base = rtrim($base_url, '/');
        // Plugin options win over the operator's http_client defaults.
        $this->http = rcube::get_instance()->get_http_client([
            'verify' => $verify_tls, 'timeout' => $timeout, 'connect_timeout' => min(10, $timeout),
            'allow_redirects' => false, 'http_errors' => false,
        ]);
    }

    public function verifies_tls(): bool
    {
        return $this->verify_tls;
    }

    public function list(): array
    {
        $files = $this->json('/api/mail/files');
        if (!array_is_list($files)) {
            throw new zemzemeh_dlp_client_exception('unavailable', 'Unexpected Zemzemeh response');
        }

        return array_values(array_filter($files, [self::class, 'valid_meta']));
    }

    public function metadata(string $file_id): array
    {
        $meta = $this->json('/api/mail/files/' . self::file_id($file_id));
        if (!self::valid_meta($meta) || $meta['id'] !== $file_id) {
            throw new zemzemeh_dlp_client_exception('unavailable', 'Unexpected Zemzemeh response');
        }

        return $meta;
    }

    /**
     * Stream a file's plaintext into a new spool file, hashing as it arrives. Any mismatch
     * with the metadata removes the spool file.
     *
     * @return array{meta: array, spool_name: string, sha256: string, size: int}
     */
    public function download(string $file_id, zemzemeh_dlp_spool $spool, int $max_bytes): array
    {
        $meta = $this->metadata($file_id);
        if ($meta['size'] > $max_bytes) {
            throw new zemzemeh_dlp_client_exception('too_large', 'File exceeds the attachment size limit');
        }

        [$name, $handle] = $spool->create();
        try {
            $response = $this->request("/api/mail/files/{$file_id}/content", true);
            if ($response->getHeaderLine('X-Zemzemeh-Version') !== $meta['version']) {
                throw new zemzemeh_dlp_client_exception('changed', 'File changed while it was being attached');
            }
            $body = $response->getBody();
            $hash = hash_init('sha256');
            $size = 0;
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK);
                $size += strlen($chunk);
                if ($size > $meta['size']) {
                    throw new zemzemeh_dlp_client_exception('changed', 'File is larger than announced');
                }
                hash_update($hash, $chunk);
                if (fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not write spool file');
                }
            }
            if ($size !== $meta['size']) {
                throw new zemzemeh_dlp_client_exception('changed', 'File transfer was incomplete');
            }
            if (!fclose($handle)) {
                throw new RuntimeException('Could not write spool file');
            }
            $handle = null;

            return ['meta' => $meta, 'spool_name' => $name, 'sha256' => hash_final($hash), 'size' => $size];
        } catch (Throwable $e) {
            if ($handle) {
                fclose($handle);
            }
            $spool->delete($name);
            if ($e instanceof zemzemeh_dlp_client_exception) {
                throw $e;
            }
            throw new zemzemeh_dlp_client_exception('unavailable', 'Zemzemeh transfer failed');
        }
    }

    /**
     * Save a spooled file as a new file of the token's user (Save to Zemzemeh).
     *
     * @return array the new file's metadata
     */
    public function upload(string $path, string $name, string $content_type): array
    {
        if (!$handle = fopen($path, 'rb')) {
            throw new zemzemeh_dlp_client_exception('unavailable', 'Could not read spool file');
        }
        try {
            $response = $this->request('/api/mail/files', method: 'POST', options: ['multipart' => [[
                'name' => 'upload', 'contents' => $handle, 'headers' => ['Content-Type' => $content_type],
                // Guzzle quotes the filename without escaping and keeps only its basename.
                'filename' => strtr($name, ['"' => '_', '\\' => '_', '/' => '_']),
            ]]], expect: 201);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
        $meta = json_decode((string) $response->getBody(), true);
        if (!self::valid_meta($meta)) {
            throw new zemzemeh_dlp_client_exception('unavailable', 'Unexpected Zemzemeh response');
        }

        return $meta;
    }

    private static function file_id(string $file_id): string
    {
        // Ids are only ever path segments built here; anything else is refused before a request.
        if (!preg_match(self::FILE_ID, $file_id)) {
            throw new zemzemeh_dlp_client_exception('not_found', 'File not found');
        }

        return $file_id;
    }

    private static function valid_meta($meta): bool
    {
        return is_array($meta) && is_string($meta['id'] ?? null) && preg_match(self::FILE_ID, $meta['id'])
            && is_string($meta['name'] ?? null) && is_string($meta['content_type'] ?? null)
            && is_int($meta['size'] ?? null) && $meta['size'] >= 0
            && is_string($meta['version'] ?? null) && preg_match(self::VERSION, $meta['version'])
            && is_string($meta['owner_username'] ?? null);
    }

    private function json(string $path): mixed
    {
        $data = json_decode((string) $this->request($path)->getBody(), true);
        if ($data === null) {
            throw new zemzemeh_dlp_client_exception('unavailable', 'Unexpected Zemzemeh response');
        }

        return $data;
    }

    private function request(string $path, bool $stream = false, string $method = 'GET', array $options = [], int $expect = 200)
    {
        try {
            $response = $this->http->request($method, $this->base . $path, [
                'headers' => ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json, */*'],
                'stream' => $stream,
            ] + $options);
        } catch (GuzzleException) {
            throw new zemzemeh_dlp_client_exception('unavailable', 'Zemzemeh is unavailable');
        }

        return match ($response->getStatusCode()) {
            $expect => $response,
            401, 403 => throw new zemzemeh_dlp_client_exception('auth', 'Zemzemeh refused the session token'),
            404 => throw new zemzemeh_dlp_client_exception('not_found', 'File not found'),
            413 => throw new zemzemeh_dlp_client_exception('too_large', 'File exceeds the Zemzemeh upload limit'),
            507 => throw new zemzemeh_dlp_client_exception('quota', 'Zemzemeh storage quota exceeded'),
            default => throw new zemzemeh_dlp_client_exception('unavailable', 'Zemzemeh is unavailable'),
        };
    }
}

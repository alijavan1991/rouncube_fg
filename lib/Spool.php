<?php

/**
 * Plaintext attachment files in Roundcube's private temp_dir.
 *
 * Files live directly in temp_dir because filesystem_attachments only serves paths there.
 * Names carry Roundcube's temp prefix so its own temp_dir_ttl garbage collection is a
 * backstop; they are random and only names created here are ever resolved.
 */
final class zemzemeh_dlp_spool
{
    private const PREFIX = RCUBE_TEMP_FILE_PREFIX . 'zzdlp';
    private const NAME = '/^' . self::PREFIX . '[0-9a-f]{32}$/';

    private string $dir;

    public function __construct(string $dir)
    {
        $dir = rtrim($dir, '/');
        if (!str_starts_with($dir, '/') || !is_dir($dir)) {
            throw new InvalidArgumentException('Spool directory must be an existing absolute path');
        }
        $this->dir = $dir;
    }

    /**
     * @return array{0: string, 1: resource} spool name and a write handle
     */
    public function create(): array
    {
        $name = self::PREFIX . bin2hex(random_bytes(16));
        $path = $this->dir . '/' . $name;
        $previous = umask(0077);
        try {
            // 'x' fails if anything (including a planted symlink) already exists at the path.
            $handle = fopen($path, 'x');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            throw new RuntimeException('Could not create spool file');
        }
        chmod($path, 0600);

        return [$name, $handle];
    }

    public function path(string $name): string
    {
        if (!preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException('Not a spool name');
        }
        $path = $this->dir . '/' . $name;
        if (is_link($path) || !is_file($path)) {
            throw new InvalidArgumentException('Spool file is missing or not a regular file');
        }

        return $path;
    }

    /**
     * Remove a spool file; missing files count as removed. A symlink is removed, never its target.
     */
    public function delete(string $name): bool
    {
        if (!preg_match(self::NAME, $name)) {
            return false;
        }
        $path = $this->dir . '/' . $name;
        if (!file_exists($path) && !is_link($path)) {
            return true;
        }

        return unlink($path);
    }
}

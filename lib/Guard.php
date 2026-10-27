<?php

/**
 * Environment checks. A plugin with problems stays inactive (no Zemzemeh features)
 * but keeps blocking every local attachment path.
 */
final class zemzemeh_dlp_guard
{
    /** Plugins that download, render or add attachments outside this plugin's control. */
    public const DENIED_PLUGINS = ['zipdownload', 'vcard_attachments', 'enigma', 'database_attachments', 'redundant_attachments'];

    /**
     * @return list<string> problem codes; empty when the environment is safe
     */
    public static function problems(string $version, array $loaded_plugins, array $extra_denied, bool $oauth_enabled,
        ?string $password_claim, string $temp_dir, string $web_root): array
    {
        $problems = [];
        if ($version !== zemzemeh_dlp::SUPPORTED_VERSION) {
            $problems[] = 'unsupported_version';
        }
        foreach (array_intersect(array_merge(self::DENIED_PLUGINS, $extra_denied), $loaded_plugins) as $plugin) {
            $problems[] = "denied_plugin:{$plugin}";
        }
        if (!$oauth_enabled) {
            $problems[] = 'oauth_disabled';
        }
        if ($password_claim) {
            $problems[] = 'oauth_password_claim';
        }
        if ($problem = self::temp_dir_problem($temp_dir, $web_root)) {
            $problems[] = $problem;
        }

        return $problems;
    }

    // temp_dir is the plaintext attachment spool: it must be private to the PHP user.
    private static function temp_dir_problem(string $temp_dir, string $web_root): ?string
    {
        $dir = rtrim($temp_dir, '/');
        if (!str_starts_with($dir, '/')) {
            return 'temp_dir_not_absolute';
        }
        if (is_link($dir)) {
            return 'temp_dir_symlink';
        }
        if (!is_dir($dir)) {
            return 'temp_dir_missing';
        }
        clearstatcache(true, $dir);
        if ((fileperms($dir) & 0077) !== 0 || (function_exists('posix_geteuid') && fileowner($dir) !== posix_geteuid())) {
            return 'temp_dir_permissions';
        }
        $real = realpath($dir);
        $public = realpath($web_root);
        if ($public !== false && ($real === $public || str_starts_with($real, rtrim($public, '/') . '/'))) {
            return 'temp_dir_public';
        }

        return null;
    }
}

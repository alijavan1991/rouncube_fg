<?php

/**
 * Garbage collection for bin/gc.php (cron). Roundcube removes compose files only on send,
 * single-attachment removal and its temp_dir_ttl sweep (at least 6 hours); closed composes
 * and logouts leave plaintext behind. This removes, once older than the grace period, every
 * spool file without an unexpired approval whose session is still alive.
 */
final class zemzemeh_dlp_gc
{
    private const NAME = '/^' . RCUBE_TEMP_FILE_PREFIX . 'zzdlp[0-9a-f]{32}$/';

    /**
     * @param array<string, true>|null $live_sessions SHA-256 of live session ids; null when unknown
     *
     * @return array{rows: int, files: int, failed: int}
     */
    public static function run(rcube_db $db, string $temp_dir, int $grace, ?array $live_sessions): array
    {
        $table = $db->table_name('zemzemeh_dlp_provenance', true);
        $deleted = $db->query("DELETE FROM {$table} WHERE `expires_at` <= " . $db->now());
        $result = ['rows' => $deleted ? $db->affected_rows($deleted) : 0, 'files' => 0, 'failed' => 0];

        $active = [];
        $rows = $db->query("SELECT `spool_name`, `session_hash` FROM {$table}");
        while ($row = $db->fetch_assoc($rows)) {
            $active[$row['spool_name']][$row['session_hash']] = true;
        }

        $cutoff = time() - $grace;
        foreach (scandir($temp_dir) ?: [] as $name) {
            $path = rtrim($temp_dir, '/') . '/' . $name;
            $stat = @lstat($path);
            if (!preg_match(self::NAME, $name) || !$stat || $stat['mtime'] > $cutoff) {
                continue;
            }
            $sessions = $active[$name] ?? [];
            if ($sessions && ($live_sessions === null || array_intersect_key($sessions, $live_sessions))) {
                continue;
            }
            if (!is_dir($path) && @unlink($path)) {
                $result['files']++;
            } else {
                $result['failed']++;
                zemzemeh_dlp_audit::log('cleanup_failed', ['spool_name' => $name, 'source' => 'gc']);
            }
        }

        return $result;
    }

    /**
     * Hashes of unexpired sessions, or null when sessions are not stored in the database
     * (approval expiry is then the only bound).
     */
    public static function live_session_hashes(rcube_db $db, string $session_storage): ?array
    {
        if ($session_storage !== 'db') {
            return null;
        }
        $live = [];
        $result = $db->query('SELECT `sess_id` FROM ' . $db->table_name('session', true) . ' WHERE `expires_at` > ' . $db->now());
        while ($row = $db->fetch_assoc($result)) {
            $live[zemzemeh_dlp_provenance_store::session_hash($row['sess_id'])] = true;
        }

        return $live;
    }
}

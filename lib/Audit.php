<?php

/**
 * Security events as one JSON object per line in the `zemzemeh_dlp` log.
 * Callers pass identifiers and outcomes only: never tokens, cookies, filenames or content.
 */
final class zemzemeh_dlp_audit
{
    public static function log(string $event, array $fields = []): void
    {
        // rcube, not rcmail: bin/gc.php audits too.
        $rcube = rcube::get_instance();
        $record = ['event' => $event, 'user_id' => $rcube->user->ID ?? null] + $fields;
        rcube::write_log('zemzemeh_dlp', json_encode($record, \JSON_UNESCAPED_SLASHES));
    }
}

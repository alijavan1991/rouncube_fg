<?php

/**
 * Cron job, every few minutes, as the PHP user:
 *     php plugins/zemzemeh_dlp/bin/gc.php
 * Removes expired approvals and spool files of ended sessions, expired approvals or no
 * approval at all. Exits non-zero when a file could not be removed (audited as cleanup_failed).
 */

define('INSTALL_PATH', realpath(__DIR__ . '/../../..') . '/');

require INSTALL_PATH . 'program/include/clisetup.php';
require_once __DIR__ . '/../lib/Audit.php';
require_once __DIR__ . '/../lib/ProvenanceStore.php';
require_once __DIR__ . '/../lib/Gc.php';

$rcmail = rcmail::get_instance();
$db = $rcmail->get_dbh();
$result = zemzemeh_dlp_gc::run(
    $db,
    (string) $rcmail->config->get('temp_dir'),
    (int) $rcmail->config->get('zemzemeh_dlp_gc_grace', 900),
    zemzemeh_dlp_gc::live_session_hashes($db, (string) $rcmail->config->get('session_storage', 'db'))
);

echo "zemzemeh_dlp gc: rows={$result['rows']} files={$result['files']} failed={$result['failed']}\n";
exit($result['failed'] ? 1 : 0);

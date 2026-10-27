<?php

// Runs inside the pinned Roundcube image (tests/docker) against a disposable SQLite
// database and temp_dir. It never touches a real Roundcube installation or mail server.

$root = sys_get_temp_dir() . '/zemzemeh-dlp-test-' . bin2hex(random_bytes(8));
mkdir($root . '/temp', 0700, true);
mkdir($root . '/logs', 0700, true);
register_shutdown_function(static function () use ($root) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});

putenv('ZZDLP_TEST_DIR=' . $root);
putenv('ZZDLP_TEST_DES_KEY=' . bin2hex(random_bytes(12)));

define('ROUNDCUBE_TEST_SESSION', 'zemzemeh-dlp-test-session');
define('INSTALL_PATH', rtrim(getenv('ROUNDCUBE_PATH') ?: '/usr/src/roundcubemail', '/') . '/');
define('ZZDLP_PLUGIN_DIR', dirname(__DIR__) . '/');
putenv('RCUBE_CONFIG_PATH=' . INSTALL_PATH . 'config' . PATH_SEPARATOR . __DIR__ . '/config');

require INSTALL_PATH . 'program/include/iniset.php';
require __DIR__ . '/fixtures/FakeOauth.php';

// Roundcube's SQLite driver creates its own schema for a new database file on connect.
$db = rcube_db::factory('sqlite:///' . $root . '/roundcube.db?mode=0600');
$db->db_connect('w');
if (!$db->exec_script(file_get_contents(ZZDLP_PLUGIN_DIR . 'SQL/sqlite.initial.sql'))) {
    throw new RuntimeException('Could not apply the plugin schema: ' . $db->is_error());
}
$db->closeConnection();

rcmail::get_instance(0, 'test');

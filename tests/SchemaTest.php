<?php

use PHPUnit\Framework\TestCase;

final class SchemaTest extends TestCase
{
    private const COLUMNS = [
        'id', 'user_id', 'oidc_sub', 'session_hash', 'compose_id', 'upload_id', 'zz_file_id', 'zz_owner',
        'zz_version', 'sha256', 'filename', 'mimetype', 'size', 'spool_name', 'decision', 'selected_at',
        'expires_at', 'consumed_at',
    ];

    public function testSqliteSchemaIsAppliedAndVersioned(): void
    {
        $db = rcmail::get_instance()->get_dbh();

        $this->assertSame(self::COLUMNS, $db->list_cols('zemzemeh_dlp_provenance'));
        $version = $db->fetch_assoc($db->query(
            'SELECT `value` FROM ' . $db->table_name('system', true) . ' WHERE `name` = ?', 'zemzemeh_dlp-version'));
        $this->assertSame(zemzemeh_dlp::SCHEMA_VERSION, $version['value']);
    }

    public function testProvenanceRowsAreRemovedWithTheirRoundcubeUser(): void
    {
        $db = rcmail::get_instance()->get_dbh();
        $db->query('PRAGMA foreign_keys = ON');
        $db->query('INSERT INTO ' . $db->table_name('users', true) . " (`username`, `mail_host`, `created`) VALUES ('cascade', 'localhost', " . $db->now() . ')');
        $user = $db->insert_id('users');
        $db->query('INSERT INTO ' . $db->table_name('zemzemeh_dlp_provenance', true)
            . ' (`user_id`, `oidc_sub`, `session_hash`, `compose_id`, `upload_id`, `zz_file_id`, `zz_owner`, `zz_version`,'
            . ' `sha256`, `filename`, `mimetype`, `size`, `spool_name`, `decision`, `selected_at`, `expires_at`)'
            . " VALUES (?, 'sub', 'session', 'compose', '1', 'file', 'owner', 'version', 'hash', 'a.txt', 'text/plain', 1,"
            . " 'spool', 'approved', " . $db->now() . ', ' . $db->now(3600) . ')', $user);

        $db->query('DELETE FROM ' . $db->table_name('users', true) . ' WHERE `user_id` = ?', $user);

        $count = $db->fetch_array($db->query('SELECT COUNT(*) FROM ' . $db->table_name('zemzemeh_dlp_provenance', true)));
        $this->assertSame(0, (int) $count[0]);
    }

    public function testAllDatabaseSchemasDefineTheSameTable(): void
    {
        foreach (['mysql', 'postgres', 'sqlite'] as $engine) {
            $sql = file_get_contents(ZZDLP_PLUGIN_DIR . "SQL/{$engine}.initial.sql");
            preg_match('/CREATE TABLE `?zemzemeh_dlp_provenance`? \((.*?)\n\)/s', $sql, $table);
            preg_match_all('/^\s+`?([a-z0-9_]+)`? (?:int|integer|serial|varchar|bigint|datetime|timestamp)/m', $table[1], $columns);

            $this->assertSame(self::COLUMNS, $columns[1], $engine);
            $this->assertStringContainsString("'zemzemeh_dlp-version', '" . zemzemeh_dlp::SCHEMA_VERSION . "'", $sql, $engine);
            $this->assertSame(4, preg_match_all('/ix_zemzemeh_dlp_provenance_/', $sql), $engine);
            $this->assertMatchesRegularExpression('/REFERENCES `?users`? \(`?user_id`?\) ON DELETE CASCADE/', $sql, $engine);
        }
    }
}

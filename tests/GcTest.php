<?php

use PHPUnit\Framework\TestCase;

final class GcTest extends TestCase
{
    use ComposeFixture {
        tearDown as composeTearDown;
    }

    private function liveSession(bool $live): void
    {
        $db = $this->rcmail->get_dbh();
        $db->query('DELETE FROM ' . $db->table_name('session', true));
        if ($live) {
            $db->query('INSERT INTO ' . $db->table_name('session', true) . " (`sess_id`, `expires_at`, `ip`, `vars`) VALUES (?, "
                . $db->now(600) . ", '', '')", ROUNDCUBE_TEST_SESSION);
        }
    }

    private function gc(int $grace = 0): array
    {
        $db = $this->rcmail->get_dbh();

        return zemzemeh_dlp_gc::run($db, $this->rcmail->config->get('temp_dir'), $grace,
            zemzemeh_dlp_gc::live_session_hashes($db, 'db'));
    }

    private function age(string $path, int $seconds): void
    {
        touch($path, time() - $seconds);
    }

    protected function tearDown(): void
    {
        $this->liveSession(false);
        foreach ($this->spooled() as $file) {
            is_dir($file) ? rmdir($file) : unlink($file);
        }
        $db = $this->rcmail->get_dbh();
        $db->query('DELETE FROM ' . $db->table_name('zemzemeh_dlp_provenance', true));
        $this->composeTearDown();
    }

    public function testKeepsFilesOfLiveSessionsWithActiveApprovals(): void
    {
        $this->liveSession(true);
        $attachment = $this->plugin->attach('c1', self::FILE);
        $this->age($attachment['path'], 3600);

        $result = $this->gc();

        $this->assertFileExists($attachment['path']);
        $this->assertSame(0, $result['files']);
    }

    public function testRemovesFilesOfExpiredApprovalsThenOfEndedSessions(): void
    {
        $this->liveSession(true);
        $kept = $this->plugin->attach('c1', self::FILE);
        $expired = $this->plugin->attach('c2', self::FILE);
        $db = $this->rcmail->get_dbh();
        $db->query('UPDATE ' . $db->table_name('zemzemeh_dlp_provenance', true) . ' SET `expires_at` = ' . $db->now(-60)
            . ' WHERE `compose_id` = ?', 'c2');
        $this->age($kept['path'], 3600);
        $this->age($expired['path'], 3600);

        $result = $this->gc();

        $this->assertFileExists($kept['path']);
        $this->assertFileDoesNotExist($expired['path']);
        $this->assertSame(['files' => 1, 'rows' => 1], ['files' => $result['files'], 'rows' => $result['rows']]);
        $this->assertSame(['c1'], array_column($this->provenance(), 'compose_id'));

        $this->liveSession(false);  // the session ends; its approval stays for draft re-proving
        $result = $this->gc();

        $this->assertFileDoesNotExist($kept['path']);
        $this->assertSame(['files' => 1, 'rows' => 0], ['files' => $result['files'], 'rows' => $result['rows']]);
        $this->assertCount(1, $this->provenance());
    }

    public function testOrphansAreRemovedAfterTheGracePeriodOnly(): void
    {
        $dir = $this->rcmail->config->get('temp_dir');
        $old = $dir . '/RCMTEMPzzdlp' . str_repeat('1', 32);
        $new = $dir . '/RCMTEMPzzdlp' . str_repeat('2', 32);
        $foreign = $dir . '/RCMTEMPattmnt' . str_repeat('3', 8);
        foreach ([$old, $new, $foreign] as $file) {
            file_put_contents($file, 'plaintext');
        }
        $this->age($old, 3600);
        $this->age($foreign, 3600);

        $this->gc(900);

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
        $this->assertFileExists($foreign);  // Roundcube's own temp files are left to its own GC
        unlink($foreign);
    }

    public function testUnknownSessionStorageFallsBackToApprovalExpiry(): void
    {
        $attachment = $this->plugin->attach('c1', self::FILE);
        $this->age($attachment['path'], 3600);
        $db = $this->rcmail->get_dbh();

        zemzemeh_dlp_gc::run($db, $this->rcmail->config->get('temp_dir'), 0, zemzemeh_dlp_gc::live_session_hashes($db, 'redis'));

        $this->assertFileExists($attachment['path']);
    }

    public function testUndeletableFilesAreReportedAndRetried(): void
    {
        $stuck = $this->rcmail->config->get('temp_dir') . '/RCMTEMPzzdlp' . str_repeat('4', 32);
        mkdir($stuck);
        $this->age($stuck, 3600);

        $result = @$this->gc();

        $this->assertSame(1, $result['failed']);
        $this->assertContains('cleanup_failed', array_column($this->events(), 'event'));
        rmdir($stuck);
    }

    public function testCronScriptRuns(): void
    {
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ZZDLP_PLUGIN_DIR . 'bin/gc.php') . ' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertMatchesRegularExpression('/^zemzemeh_dlp gc: rows=\d+ files=\d+ failed=0$/', end($output));
    }
}

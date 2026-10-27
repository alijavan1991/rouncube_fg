<?php

use PHPUnit\Framework\TestCase;

final class AttachTest extends TestCase
{
    private const TOKEN = 'attach-bearer-token';
    private const FILE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private static StubServer $server;
    private rcmail $rcmail;
    private zemzemeh_dlp $plugin;
    private $original_oauth;
    private $original_user;
    private string $log;

    public static function setUpBeforeClass(): void
    {
        self::$server = new StubServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        self::$server->reset();
        $this->rcmail = rcmail::get_instance();
        $this->rcmail->task = 'mail';
        $this->plugin = $this->rcmail->plugins->get_plugin('zemzemeh_dlp');
        $this->original_oauth = $this->rcmail->oauth;
        $this->original_user = $this->rcmail->user;
        $this->rcmail->oauth = new FakeOauth();
        $this->rcmail->config->set('zemzemeh_dlp_api_url', self::$server->url);
        $this->rcmail->config->set('max_message_size', '10M');

        $db = $this->rcmail->get_dbh();
        $db->query('INSERT INTO ' . $db->table_name('users', true) . " (`username`, `mail_host`, `created`) VALUES (?, 'localhost', "
            . $db->now() . ')', 'attach' . bin2hex(random_bytes(4)));
        $this->rcmail->set_user(new rcube_user((int) $db->insert_id('users')));

        $_SESSION = [
            'password' => $this->rcmail->encrypt('Bearer ' . self::TOKEN),
            'oauth_token' => ['expires' => time() + 200, 'identity' => ['sub' => 'sub-attach']],
            'compose_data_c1' => ['id' => 'c1', 'param' => []],
        ];
        $_POST = $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->log = getenv('ZZDLP_TEST_DIR') . '/logs/zemzemeh_dlp.log';
        @unlink($this->log);
    }

    protected function tearDown(): void
    {
        $this->rcmail->oauth = $this->original_oauth;
        $this->rcmail->user = $this->original_user;
        $_SESSION = $_POST = $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    private function refused(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail("expected {$reason}");
        } catch (zemzemeh_dlp_attach_exception $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    private function events(): array
    {
        preg_match_all('/(\{.*\})$/m', is_file($this->log) ? file_get_contents($this->log) : '', $lines);

        return array_map(static fn ($line) => json_decode($line, true), $lines[1]);
    }

    private function spooled(): array
    {
        return glob($this->rcmail->config->get('temp_dir') . '/RCMTEMPzzdlp*');
    }

    private function provenance(): array
    {
        $db = $this->rcmail->get_dbh();
        $result = $db->query('SELECT * FROM ' . $db->table_name('zemzemeh_dlp_provenance', true) . ' WHERE `user_id` = ?',
            $this->rcmail->user->ID);
        $rows = [];
        while ($row = $db->fetch_assoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function testListsMetadataOnlyWithTheUsersToken(): void
    {
        $files = $this->plugin->list_files();

        $this->assertSame(self::FILE, $files[0]['id']);
        $this->assertSame(['id', 'name', 'content_type', 'size', 'owner_username', 'created_at'], array_keys($files[0]));
        $this->assertStringContainsString('Bearer ' . self::TOKEN . ' /api/mail/files', self::$server->requests());
    }

    public function testAttachesAnAuthorizedFileWithServerSideProvenance(): void
    {
        $attachment = $this->plugin->attach('c1', self::FILE);

        $uploads = $this->rcmail->list_uploaded_files('c1');
        $this->assertCount(1, $uploads);
        $this->assertSame($attachment['id'], $uploads[0]['id']);
        $this->assertSame('report.pdf', $uploads[0]['name']);
        $this->assertSame('application/pdf', $uploads[0]['mimetype']);
        $this->assertSame($this->rcmail->config->get('temp_dir'), dirname($uploads[0]['path']));
        $this->assertSame('hello world', file_get_contents($uploads[0]['path']));

        [$row] = $this->provenance();
        $this->assertSame((string) $attachment['id'], $row['upload_id']);
        $this->assertSame('c1', $row['compose_id']);
        $this->assertSame(self::FILE, $row['zz_file_id']);
        $this->assertSame('alice', $row['zz_owner']);
        $this->assertSame(str_repeat('1', 64), $row['zz_version']);
        $this->assertSame(hash('sha256', 'hello world'), $row['sha256']);
        $this->assertSame('sub-attach', $row['oidc_sub']);
        $this->assertSame(hash('sha256', ROUNDCUBE_TEST_SESSION), $row['session_hash']);
        $this->assertSame(basename($uploads[0]['path']), $row['spool_name']);
        $this->assertSame('approved', $row['decision']);

        $event = $this->events()[0];
        $this->assertSame('file_selected', $event['event']);
        $this->assertSame(self::FILE, $event['file_id']);
        $this->assertStringNotContainsString('report.pdf', file_get_contents($this->log));
        $this->assertStringNotContainsString(self::TOKEN, file_get_contents($this->log));

        $this->rcmail->delete_uploaded_files('c1');
    }

    public function testRequestsMustBePostsForAComposeOfThisSession(): void
    {
        $_POST = ['_id' => 'c1', '_file_id' => self::FILE];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->refused('forged', fn () => $this->plugin->attach_from_request());

        $_SERVER['REQUEST_METHOD'] = 'POST';
        foreach (['c2', '../c1', '', str_repeat('9', 200)] as $compose) {
            $this->refused('forged', fn () => $this->plugin->attach($compose, self::FILE));
        }
        $_GET = ['_id' => 'c1', '_file_id' => self::FILE];
        $_POST = [];
        $this->refused('forged', fn () => $this->plugin->attach_from_request());  // query string is ignored

        $this->assertSame('', self::$server->requests());
        $this->assertSame([], $this->rcmail->list_uploaded_files('c1'));
        $this->assertSame('blocked_forged_request', $this->events()[0]['event']);

        $_POST = ['_id' => 'c1', '_file_id' => self::FILE];
        $this->assertSame('report.pdf', $this->plugin->attach_from_request()['name']);
        $this->rcmail->delete_uploaded_files('c1');
    }

    public function testUnauthorizedDeletedOrChangedFilesLeaveNothingBehind(): void
    {
        $before = $this->spooled();
        $this->refused('not_found', fn () => $this->plugin->attach('c1', str_repeat('b', 32)));
        $this->refused('not_found', fn () => $this->plugin->attach('c1', '../../etc/passwd'));
        $this->refused('auth', fn () => $this->plugin->attach('c1', str_repeat('c', 32)));
        $this->refused('changed', fn () => $this->plugin->attach('c1', str_repeat('e', 32)));
        $this->refused('unavailable', fn () => $this->plugin->attach('c1', str_repeat('d', 32)));

        $this->assertSame($before, $this->spooled());
        $this->assertSame([], $this->rcmail->list_uploaded_files('c1'));
        $this->assertSame([], $this->provenance());
        $this->assertContains('permission_denied', array_column($this->events(), 'event'));
    }

    public function testRespectsTheMessageSizeLimit(): void
    {
        $this->rcmail->config->set('max_message_size', '12K');  // 10 KiB body allowance + base64 growth
        $this->refused('too_large', fn () => $this->plugin->attach('c1', str_repeat('8', 32)));
        $this->assertStringNotContainsString(str_repeat('8', 32) . '/content', self::$server->requests());
    }

    public function testFailsClosedWithoutAUsableSessionOrSafeEnvironment(): void
    {
        $this->rcmail->oauth->valid = false;
        $this->refused('auth', fn () => $this->plugin->attach('c1', self::FILE));
        $this->refused('auth', fn () => $this->plugin->list_files());

        $this->rcmail->oauth = $this->original_oauth;  // password login: OAuth not configured
        $this->refused('inactive', fn () => $this->plugin->attach('c1', self::FILE));
        $this->assertSame('', self::$server->requests());
    }

    public function testThePrivateInsertHookOnlyServesThePluginsOwnAttach(): void
    {
        $file = $this->rcmail->config->get('temp_dir') . '/RCMTEMPzzdlp' . str_repeat('d', 32);
        file_put_contents($file, 'smuggled');
        $attachment = ['path' => $file, 'name' => 'x.pdf', 'size' => 8, 'mimetype' => 'application/pdf', 'group' => 'c1'];

        $this->assertFalse($this->rcmail->insert_uploaded_file($attachment, 'zemzemeh_dlp_insert'));
        $this->assertSame([], $this->rcmail->list_uploaded_files('c1'));
        unlink($file);
    }
}

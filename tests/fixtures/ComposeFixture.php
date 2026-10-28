<?php

/**
 * A signed-in OAuth user with compose session `c1`, the stub Zemzemeh and a clean audit log.
 * Test classes call composeSetUp()/composeTearDown() and start/stop the stub themselves.
 */
trait ComposeFixture
{
    private const TOKEN = 'compose-bearer-token';
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
        $this->rcmail->task = 'mail';  // set_task() forces 'cli' under PHP CLI
        $this->rcmail->action = 'send';
        $this->plugin = $this->rcmail->plugins->get_plugin('zemzemeh_dlp');
        $this->original_oauth = $this->rcmail->oauth;
        $this->original_user = $this->rcmail->user;
        $this->rcmail->oauth = new FakeOauth();
        $this->rcmail->config->set('zemzemeh_dlp_api_url', self::$server->url);
        $this->rcmail->config->set('zemzemeh_dlp_system_assets', []);
        $this->rcmail->config->set('max_message_size', '10M');

        $db = $this->rcmail->get_dbh();
        $db->query('INSERT INTO ' . $db->table_name('users', true) . " (`username`, `mail_host`, `created`) VALUES (?, 'localhost', "
            . $db->now() . ')', 'compose' . bin2hex(random_bytes(4)));
        $this->rcmail->set_user(new rcube_user((int) $db->insert_id('users')));

        $_SESSION = [
            'password' => $this->rcmail->encrypt('Bearer ' . self::TOKEN),
            'oauth_token' => ['expires' => time() + 200, 'identity' => ['sub' => 'sub-compose']],
            'compose_data_c1' => ['id' => 'c1', 'param' => []],
            'compose_data_c2' => ['id' => 'c2', 'param' => []],
        ];
        $_POST = $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $this->log = getenv('ZZDLP_TEST_DIR') . '/logs/zemzemeh_dlp.log';
        @unlink($this->log);
    }

    protected function tearDown(): void
    {
        foreach (['c1', 'c2'] as $compose) {
            $this->rcmail->delete_uploaded_files($compose);
        }
        $this->rcmail->oauth = $this->original_oauth;
        $this->rcmail->user = $this->original_user;
        $this->rcmail->action = '';
        $_SESSION = $_POST = $_GET = [];
        unset($_SERVER['REQUEST_METHOD']);
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
}

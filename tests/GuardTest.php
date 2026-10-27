<?php

use PHPUnit\Framework\TestCase;

final class GuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = getenv('ZZDLP_TEST_DIR') . '/guard-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/spool', 0700, true);
    }

    private function problems(array $changes = []): array
    {
        $args = $changes + [
            'version' => zemzemeh_dlp::SUPPORTED_VERSION,
            'loaded_plugins' => ['zemzemeh_dlp', 'filesystem_attachments'],
            'extra_denied' => [],
            'oauth_enabled' => true,
            'password_claim' => null,
            'temp_dir' => $this->dir . '/spool',
            'web_root' => INSTALL_PATH . 'public_html',
        ];

        return zemzemeh_dlp_guard::problems(...$args);
    }

    public function testSafeEnvironmentHasNoProblems(): void
    {
        $this->assertSame([], $this->problems());
    }

    public function testEachUnsafeConditionIsReported(): void
    {
        $this->assertSame(['unsupported_version'], $this->problems(['version' => '1.7.4']));
        $this->assertSame(['denied_plugin:zipdownload'], $this->problems(['loaded_plugins' => ['zemzemeh_dlp', 'zipdownload']]));
        $this->assertSame(['denied_plugin:custom_upload'], $this->problems(
            ['loaded_plugins' => ['custom_upload'], 'extra_denied' => ['custom_upload']]));
        $this->assertSame(['oauth_disabled'], $this->problems(['oauth_enabled' => false]));
        $this->assertSame(['oauth_password_claim'], $this->problems(['password_claim' => 'imap_password']));
        $this->assertSame(['temp_dir_missing'], $this->problems(['temp_dir' => $this->dir . '/absent']));
        $this->assertSame(['temp_dir_not_absolute'], $this->problems(['temp_dir' => 'temp/']));

        chmod($this->dir . '/spool', 0750);
        $this->assertSame(['temp_dir_permissions'], $this->problems());
        chmod($this->dir . '/spool', 0700);

        symlink($this->dir . '/spool', $this->dir . '/link');
        $this->assertSame(['temp_dir_symlink'], $this->problems(['temp_dir' => $this->dir . '/link']));

        mkdir($this->dir . '/www/temp', 0700, true);
        $this->assertSame(['temp_dir_public'], $this->problems(
            ['temp_dir' => $this->dir . '/www/temp', 'web_root' => $this->dir . '/www']));
    }

    public function testEveryBuiltInConflictingPluginIsDenied(): void
    {
        foreach (['zipdownload', 'vcard_attachments', 'enigma', 'database_attachments', 'redundant_attachments'] as $plugin) {
            $this->assertContains("denied_plugin:{$plugin}", $this->problems(['loaded_plugins' => [$plugin]]));
        }
    }

    public function testTheTestInstallationFailsClosedWithoutOauth(): void
    {
        // tests/config uses password login, so the plugin must stay inactive while still blocking.
        $plugin = rcmail::get_instance()->plugins->get_plugin('zemzemeh_dlp');
        $this->assertContains('oauth_disabled', $plugin->problems());
        $this->assertFalse($plugin->is_active());
    }
}

<?php

use PHPUnit\Framework\TestCase;

final class BlockingTest extends TestCase
{
    private rcmail $rcmail;
    private string $log;

    protected function setUp(): void
    {
        $this->rcmail = rcmail::get_instance();
        $this->rcmail->task = 'mail';  // set_task() forces 'cli' under PHP CLI
        $this->log = getenv('ZZDLP_TEST_DIR') . '/logs/zemzemeh_dlp.log';
        @unlink($this->log);
        $_FILES = $_POST = $_GET = [];
    }

    protected function tearDown(): void
    {
        $_FILES = $_POST = $_GET = [];
        $this->rcmail->task = 'mail';  // set_task() forces 'cli' under PHP CLI
    }

    private function ready(string $action, string $task = 'mail'): array
    {
        return $this->rcmail->plugins->exec_hook('ready', ['task' => $task, 'action' => $action]);
    }

    private function events(): array
    {
        if (!is_file($this->log)) {
            return [];
        }
        preg_match_all('/(\{.*\})$/m', file_get_contents($this->log), $lines);

        return array_map(static fn ($line) => json_decode($line, true), $lines[1]);
    }

    public function testLocalUploadsAreBlockedBeforeDispatch(): void
    {
        $_FILES = ['_attachments' => ['name' => ['secret.pdf'], 'tmp_name' => ['/tmp/php123'], 'error' => [0],
            'size' => [5], 'type' => ['application/pdf']]];

        // The picker, drag-and-drop and paste all post here; spelling variants reach the same action class.
        foreach (['upload', 'attachment-upload', 'attachment_upload', 'Upload', 'ATTACHMENT-UPLOAD'] as $action) {
            $this->assertSame('plugin.zemzemeh_dlp.blocked', $this->ready($action)['action'], $action);
        }

        $events = $this->events();
        $this->assertSame('blocked_local_upload', $events[0]['event']);
        $this->assertSame('attachment_upload', $events[0]['action']);
        $this->assertStringNotContainsString('secret.pdf', file_get_contents($this->log));
    }

    public function testDirectUploadRequestsWithoutFilesAreBlocked(): void
    {
        $this->assertSame('plugin.zemzemeh_dlp.blocked', $this->ready('upload')['action']);
        $this->assertSame('blocked_local_upload', $this->events()[0]['event']);
    }

    public function testAttachFromUriIsBlockedAsForged(): void
    {
        $_POST = ['_uri' => 'imap://user@localhost/?_mbox=INBOX&_uid=1&_part=2'];
        $this->assertSame('plugin.zemzemeh_dlp.blocked', $this->ready('upload')['action']);
        $this->assertSame('blocked_forged_request', $this->events()[0]['event']);
    }

    public function testAttachmentBytesNeverReachTheBrowser(): void
    {
        foreach (['get', 'GET', 'display-attachment', 'attachment-display', 'attachment_display', 'viewsource'] as $action) {
            $this->assertSame('plugin.zemzemeh_dlp.blocked', $this->ready($action)['action'], $action);
        }
        $this->assertSame('blocked_download', $this->events()[0]['event']);
    }

    public function testBounceIsBlocked(): void
    {
        $this->assertSame('plugin.zemzemeh_dlp.blocked', $this->ready('bounce')['action']);
        $this->assertSame('blocked_send', $this->events()[0]['event']);
        $this->assertSame('bounce', $this->events()[0]['reason']);
    }

    public function testOrdinaryActionsAndOtherTasksPass(): void
    {
        foreach (['compose', 'show', 'preview', 'send', 'list', 'remove-attachment', ''] as $action) {
            $this->assertSame($action, $this->ready($action)['action'], $action);
        }
        // Signature images and contact photos are uploaded in other tasks; outgoing images are checked at send time.
        $this->assertSame('upload', $this->ready('upload', 'settings')['action']);
        $this->assertSame([], $this->events());
    }

    public function testCoreDisabledActionsAreAddedAsASecondLayer(): void
    {
        $disabled = (array) $this->rcmail->config->get('disabled_actions');
        foreach (['mail.upload', 'mail.attachment-upload', 'mail.attachment_upload', 'mail.display-attachment',
            'mail.attachment-display', 'mail.attachment_display', 'mail.get', 'mail.viewsource', 'mail.bounce'] as $action) {
            $this->assertContains($action, $disabled);
        }
    }

    public function testUploadHooksDenyInMailEvenForLaterPlugins(): void
    {
        $late = 0;
        $this->rcmail->plugins->register_hook('attachment_upload', static function ($args) use (&$late) {
            $late++;
            return ['status' => true, 'abort' => false, 'id' => '1'];
        });
        $this->rcmail->plugins->register_hook('attachment_save', static function ($args) use (&$late) {
            $late++;
            return ['status' => true, 'abort' => false, 'id' => '1'];
        });

        foreach (['attachment_upload', 'attachment_save'] as $hook) {
            $result = $this->rcmail->plugins->exec_hook($hook, ['path' => '/tmp/x', 'name' => 'x.pdf', 'group' => 'c1']);
            $this->assertTrue($result['abort'], $hook);
            $this->assertFalse($result['status'], $hook);
        }
        $this->assertSame(0, $late);

        $file = getenv('ZZDLP_TEST_DIR') . '/temp/forwarded';
        file_put_contents($file, 'forwarded bytes');
        $attachment = ['path' => $file, 'name' => 'f.pdf', 'size' => 15, 'mimetype' => 'application/pdf', 'group' => 'c1'];
        $this->assertFalse($this->rcmail->insert_uploaded_file($attachment, 'attachment_save'));
        $this->assertSame([], $this->rcmail->list_uploaded_files('c1'));

        $this->rcmail->task = 'settings';
        $this->rcmail->plugins->exec_hook('attachment_upload', ['path' => '/tmp/x', 'name' => 'sig.png', 'group' => 'sig']);
        $this->assertSame(1, $late);  // Other tasks keep their normal upload handling.
    }

    public function testUriPreviewAndPartHooksDeny(): void
    {
        $from_uri = $this->rcmail->plugins->exec_hook('attachment_from_uri', ['attachment' => ['id' => '1'], 'uri' => 'x']);
        $this->assertNull($from_uri['attachment']);
        $this->assertTrue($from_uri['abort']);

        $display = $this->rcmail->plugins->exec_hook('attachment_display', ['path' => '/tmp/x', 'status' => true]);
        $this->assertFalse($display['status']);

        $part = $this->rcmail->plugins->exec_hook('message_part_get', ['uid' => 1, 'id' => '2', 'download' => true]);
        $this->assertTrue($part['abort']);
    }

    public function testComposeCannotBeSeededWithAttachments(): void
    {
        $compose = $this->rcmail->plugins->exec_hook('message_compose', [
            'param' => ['to' => 'a@example.test'], 'attachments' => [['path' => '/etc/hostname', 'name' => 'h']]]);
        $this->assertSame([], $compose['attachments']);
        $this->assertSame('blocked_local_upload', $this->events()[0]['event']);
    }
}

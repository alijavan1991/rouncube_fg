<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/FakeMessages.php';

final class SaveTest extends TestCase
{
    use ComposeFixture {
        setUp as composeSetUp;
    }

    private const BODY = "%PDF-1.4 received invoice\n";

    private FakeMessages $messages;

    protected function setUp(): void
    {
        $this->composeSetUp();
        $this->rcmail->action = 'plugin.zemzemeh_dlp.save';
        $part = static fn (string $name) => ['name' => $name, 'mimetype' => 'application/pdf', 'body' => self::BODY];
        $this->messages = new FakeMessages([
            'INBOX/5' => ['2' => $part("in\x01voice.pdf"), '3' => ['name' => 'notes', 'mimetype' => 'Text/Plain; x', 'body' => 'n']],
            'Archive/Clients/7' => array_map($part, ['2' => 'quota.pdf', '3' => 'large.pdf', '4' => 'slow.pdf',
                '5' => 'auth.pdf', '6' => 'off.pdf', '7' => 'boom.pdf', '8' => 'odd.pdf']),
        ]);
        $this->plugin->message_source = $this->messages;
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

    private function uploads(): array
    {
        preg_match_all('/^UPLOAD (.*)$/m', self::$server->requests(), $lines);

        return array_map(static fn ($line) => json_decode($line, true), $lines[1]);
    }

    public function testSavesAnIncomingAttachmentServerToServer(): void
    {
        $saved = $this->plugin->save_attachment('INBOX', '5', '2');

        $this->assertSame(['id' => str_repeat('b', 32), 'name' => 'in_voice.pdf'], $saved);
        $this->assertSame([['in_voice.pdf', 'application/pdf', strlen(self::BODY), hash('sha256', self::BODY), 1, []]],
            $this->uploads());
        $this->assertStringContainsString('Bearer ' . self::TOKEN . ' /api/mail/files', self::$server->requests());
        $this->assertSame([], $this->spooled());

        [$event] = $this->events();
        $this->assertSame('attachment_saved', $event['event']);
        $this->assertSame(str_repeat('b', 32), $event['file_id']);
        $this->assertSame(str_repeat('1', 64), $event['version']);
        $this->assertSame(hash('sha256', self::BODY), $event['sha256']);
        $this->assertSame(strlen(self::BODY), $event['size']);
        $log = file_get_contents($this->log);
        foreach (['voice.pdf', 'received invoice', self::TOKEN, 'INBOX', 'Bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }

        // Unsafe MIME types fall back like attached files do.
        $this->plugin->save_attachment('INBOX', '5', '3');
        $this->assertSame(['notes', 'application/octet-stream'], array_slice($this->uploads()[1], 0, 2));
    }

    public function testOnlyAttachmentPartsOfALoadedMessageAreAccepted(): void
    {
        foreach ([['', '5', '2'], ["IN\nBOX", '5', '2'], ['INBOX', '5a', '2'], ['INBOX', '', '2'], ['INBOX', '5', '../2'],
                  ['INBOX', '5', '2.'], ['INBOX', '5', ''], [str_repeat('x', 1025), '5', '2']] as [$mbox, $uid, $part]) {
            $this->refused('forged', fn () => $this->plugin->save_attachment($mbox, $uid, $part));
        }
        $this->assertSame('blocked_forged_request', $this->events()[0]['event']);

        @unlink($this->log);
        foreach ([['INBOX', '5', '1'], ['INBOX', '6', '2'], ['Drafts', '5', '2']] as [$mbox, $uid, $part]) {
            $this->refused('not_found', fn () => $this->plugin->save_attachment($mbox, $uid, $part));
        }
        $this->assertSame(['permission_denied'], array_unique(array_column($this->events(), 'event')));
        $this->assertSame(['part'], array_unique(array_column($this->events(), 'reason')));

        $this->assertSame(0, $this->messages->writes);
        $this->assertSame([], $this->uploads());
        $this->assertSame([], $this->spooled());
    }

    public function testZemzemehRefusalsMapToUserReasonsAndAlwaysRemoveTheSpoolFile(): void
    {
        $cases = ['2' => ['quota', 'quota_denied'], '3' => ['too_large', null], '4' => ['unavailable', null],
                  '5' => ['auth', 'permission_denied'], '6' => ['not_found', 'permission_denied'],
                  '7' => ['unavailable', null], '8' => ['unavailable', null]];
        foreach ($cases as $part => [$reason, $event]) {
            @unlink($this->log);
            $this->refused($reason, fn () => $this->plugin->save_attachment('Archive/Clients', '7', (string) $part));
            $this->assertSame([], $this->spooled(), $reason);
            $events = array_column($this->events(), 'event');
            $this->assertSame($event === null ? [] : [$event], $events, "part {$part}");
            $this->assertNotContains('attachment_saved', $events);
        }
        $this->assertCount(7, $this->uploads());
    }

    public function testRequestsArePostsAndTakeOnlyMessageCoordinates(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = ['_mbox' => 'INBOX', '_uid' => '5', '_part' => '2'];
        $this->refused('forged', fn () => $this->plugin->save_from_request());

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = $_POST;
        $_POST = [];
        $this->refused('forged', fn () => $this->plugin->save_from_request());  // query string is ignored
        $this->assertSame([], $this->uploads());

        $_POST = ['_mbox' => 'INBOX', '_uid' => '5', '_part' => '2', '_name' => 'evil.exe', '_type' => 'text/html'];
        $this->assertSame('in_voice.pdf', $this->plugin->save_from_request()['name']);
        $this->assertSame(['in_voice.pdf', 'application/pdf'], array_slice($this->uploads()[0], 0, 2));
    }

    public function testNoUsableSessionTokenRefusesBeforeReadingMail(): void
    {
        unset($_SESSION['oauth_token']);
        $this->refused('auth', fn () => $this->plugin->save_attachment('INBOX', '5', '2'));
        $this->assertSame(0, $this->messages->writes);
        $this->assertSame('', self::$server->requests());
        $this->assertSame([], $this->spooled());
    }

    public function testFailedSpoolCleanupIsAudited(): void
    {
        $this->messages->sabotage = true;
        try {
            $this->refused('storage', fn () => $this->plugin->save_attachment('INBOX', '5', '2'));
            $this->assertContains('cleanup_failed', array_column($this->events(), 'event'));
            $this->assertSame([], $this->uploads());
        } finally {
            array_map('rmdir', $this->spooled());
        }
    }

    public function testEveryRefusalReasonHasASaveMessage(): void
    {
        $message = new ReflectionMethod($this->plugin, 'save_error_message');
        foreach (['auth', 'not_found', 'quota', 'too_large', 'inactive', 'forged', 'unavailable', 'storage'] as $reason) {
            $this->assertStringStartsNotWith('[', $message->invoke($this->plugin, $reason), $reason);
        }
        $this->assertStringStartsNotWith('[', $this->plugin->gettext('savedtozemzemeh'));
    }
}

<?php

use PHPUnit\Framework\TestCase;

/**
 * Reopening a draft (or editing/forwarding a sent message) re-inserts attachments through
 * attachment_save. Only bytes that match a live approval of the same user, still confirmed
 * by Zemzemeh, come back; everything else is dropped.
 */
final class DraftTest extends TestCase
{
    use ComposeFixture;

    /** What compose.php hands to attachment_save when it re-reads a stored message part. */
    private function reopen(string $bytes, string $compose = 'c2', bool $as_file = false): array|false
    {
        $attachment = ['name' => 'report.pdf', 'mimetype' => 'application/pdf', 'size' => strlen($bytes), 'group' => $compose,
            'content_id' => null, 'charset' => null, 'data' => $bytes, 'path' => null];
        if ($as_file) {
            $attachment['path'] = $this->rcmail->config->get('temp_dir') . '/RCMTEMPattmnt' . bin2hex(random_bytes(6));
            file_put_contents($attachment['path'], $bytes);
            $attachment['data'] = null;
        }

        return $this->rcmail->insert_uploaded_file($attachment, 'attachment_save') ? $attachment : false;
    }

    private function sendable(array $attachment, string $compose): bool
    {
        $message = new Mail_mime("\r\n");
        $message->headers(['From' => 'alice@example.test', 'To' => 'bob@example.test', 'Subject' => 'Draft']);
        $message->setTXTBody('Draft body');
        $message->addAttachment($attachment['path'], $attachment['mimetype'], $attachment['name'], true);
        $_POST['_id'] = $compose;

        return !$this->rcmail->plugins->exec_hook('message_before_send',
            ['message' => $message, 'from' => 'alice@example.test', 'mailto' => 'bob@example.test', 'options' => null])['abort'];
    }

    public function testReopenedDraftAttachmentIsReprovenAndSendable(): void
    {
        $this->plugin->attach('c1', self::FILE);  // approved before the draft was saved

        $attachment = $this->reopen('hello world');

        $this->assertNotFalse($attachment);
        $this->assertMatchesRegularExpression('/\/RCMTEMPzzdlp[0-9a-f]{32}$/', $attachment['path']);
        $this->assertSame('hello world', file_get_contents($attachment['path']));
        $uploads = $this->rcmail->list_uploaded_files('c2');
        $this->assertCount(1, $uploads);
        $rows = array_values(array_filter($this->provenance(), static fn ($row) => $row['compose_id'] === 'c2'));
        $this->assertCount(1, $rows);
        $this->assertSame((string) $uploads[0]['id'], $rows[0]['upload_id']);
        $this->assertSame(self::FILE, $rows[0]['zz_file_id']);
        $this->assertContains('attachment_reproven', array_column($this->events(), 'event'));
        $this->assertTrue($this->sendable($attachment, 'c2'));
    }

    public function testReopenedFromATempFileMovesItIntoTheSpool(): void
    {
        $this->plugin->attach('c1', self::FILE);
        $before = glob($this->rcmail->config->get('temp_dir') . '/RCMTEMPattmnt*');

        $attachment = $this->reopen('hello world', 'c2', true);

        $this->assertNotFalse($attachment);
        $this->assertStringContainsString('RCMTEMPzzdlp', $attachment['path']);
        $this->assertSame($before, glob($this->rcmail->config->get('temp_dir') . '/RCMTEMPattmnt*'));
    }

    public function testRevokedReplacedOrExpiredApprovalsAreDropped(): void
    {
        $this->plugin->attach('c1', str_repeat('7', 32));  // access revoked after download
        $this->assertFalse($this->reopen('hello world'));

        $this->rcmail->delete_uploaded_files('c1');
        self::$server->reset();
        $this->plugin->attach('c1', str_repeat('6', 32));  // replaced after download
        $this->assertFalse($this->reopen('hello world'));

        $db = $this->rcmail->get_dbh();
        $db->query('UPDATE ' . $db->table_name('zemzemeh_dlp_provenance', true) . ' SET `expires_at` = ' . $db->now(-60));
        self::$server->reset();
        $this->assertFalse($this->reopen('hello world'));

        $this->assertSame([], $this->rcmail->list_uploaded_files('c2'));
        $this->assertSame([], array_filter($this->provenance(), static fn ($row) => $row['compose_id'] === 'c2'));
    }

    public function testReceivedOrForeignAttachmentsAreDropped(): void
    {
        $before = $this->spooled();
        $this->assertFalse($this->reopen('a received attachment'));
        $this->assertFalse($this->reopen('a received attachment', 'c2', true));
        $this->assertSame($before, $this->spooled());
        $this->assertSame([], $this->rcmail->list_uploaded_files('c2'));
        $this->assertContains('unproven_attachment', array_column($this->events(), 'reason'));
    }

    public function testAnotherUsersApprovalDoesNotCount(): void
    {
        $this->plugin->attach('c1', self::FILE);
        $db = $this->rcmail->get_dbh();
        $db->query('INSERT INTO ' . $db->table_name('users', true) . " (`username`, `mail_host`, `created`) VALUES (?, 'localhost', "
            . $db->now() . ')', 'other' . bin2hex(random_bytes(4)));
        $this->rcmail->set_user(new rcube_user((int) $db->insert_id('users')));

        $this->assertFalse($this->reopen('hello world'));
    }

    public function testFailsClosedWithoutZemzemeh(): void
    {
        $this->plugin->attach('c1', self::FILE);
        $this->rcmail->oauth->valid = false;
        $this->assertFalse($this->reopen('hello world'));

        $this->rcmail->oauth = $this->original_oauth;  // password login
        $this->assertFalse($this->reopen('hello world'));
    }

    public function testTempFilesOutsideTempDirAreNeverRead(): void
    {
        $this->plugin->attach('c1', self::FILE);
        $outside = getenv('ZZDLP_TEST_DIR') . '/outside-draft';
        file_put_contents($outside, 'hello world');
        $attachment = ['name' => 'x.pdf', 'mimetype' => 'application/pdf', 'size' => 11, 'group' => 'c2', 'path' => $outside];

        $this->assertFalse($this->rcmail->insert_uploaded_file($attachment, 'attachment_save'));
        $this->assertFileExists($outside);
    }
}

<?php

use PHPUnit\Framework\TestCase;

/** Plaintext spool files leave temp_dir on every exit path of a compose session. */
final class CleanupTest extends TestCase
{
    use ComposeFixture;

    public function testSuccessfulSendRemovesSpoolFilesAndKeepsTheConsumedApproval(): void
    {
        $attachment = $this->plugin->attach('c1', self::FILE);
        $message = new Mail_mime("\r\n");
        $message->headers(['From' => 'alice@example.test', 'To' => 'bob@example.test', 'Subject' => 'Sent']);
        $message->setTXTBody('Body');
        $message->addAttachment($attachment['path'], $attachment['mimetype'], $attachment['name'], true);
        $_POST['_id'] = 'c1';
        $this->assertFalse($this->rcmail->plugins->exec_hook('message_before_send',
            ['message' => $message, 'from' => 'a@example.test', 'mailto' => 'b@example.test', 'options' => null])['abort']);
        $this->rcmail->plugins->exec_hook('message_sent', ['headers' => [], 'body' => '', 'message' => $message]);

        $this->rcmail->delete_uploaded_files('c1');  // send.php after delivery

        $this->assertFileDoesNotExist($attachment['path']);
        $this->assertSame([], $this->rcmail->list_uploaded_files('c1'));
        $this->assertNotNull($this->provenance()[0]['consumed_at']);
    }

    public function testRemovingOneAttachmentRemovesItsFile(): void
    {
        $attachment = $this->plugin->attach('c1', self::FILE);
        $this->assertTrue($this->rcmail->delete_uploaded_file($attachment['id']));
        $this->assertFileDoesNotExist($attachment['path']);
    }

    public function testLogoutRemovesEveryComposeFileOfTheSession(): void
    {
        // Roundcube's own session_destroy cleanup lists uploads of a NULL group and finds none.
        $first = $this->plugin->attach('c1', self::FILE);
        $second = $this->plugin->attach('c2', self::FILE);

        $this->rcmail->plugins->exec_hook('session_destroy', []);

        $this->assertFileDoesNotExist($first['path']);
        $this->assertFileDoesNotExist($second['path']);
        $this->assertCount(2, $this->provenance());  // kept until expiry so saved drafts can be re-proven
    }

    public function testFailedProvenanceWriteLeavesNothingBehind(): void
    {
        $db = $this->rcmail->get_dbh();
        $table = $db->table_name('zemzemeh_dlp_provenance', true);
        $before = $this->spooled();
        $db->query("ALTER TABLE {$table} RENAME TO " . $db->table_name('zemzemeh_dlp_provenance_moved', true));
        try {
            $this->plugin->attach('c1', self::FILE);
            $this->fail('attach succeeded without provenance');
        } catch (zemzemeh_dlp_attach_exception $e) {
            $this->assertSame('storage', $e->reason);
        } finally {
            $db->query('ALTER TABLE ' . $db->table_name('zemzemeh_dlp_provenance_moved', true) . " RENAME TO {$table}");
        }
        $this->assertSame($before, $this->spooled());
        $this->assertSame([], $this->rcmail->list_uploaded_files('c1'));
    }
}

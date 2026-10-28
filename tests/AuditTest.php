<?php

use PHPUnit\Framework\TestCase;

/** Every audit line is one JSON object and none carries secrets or attachment content. */
final class AuditTest extends TestCase
{
    use ComposeFixture;

    public function testAFullComposeLifecycleLogsNoSecretsFilenamesOrContent(): void
    {
        // Selection, a blocked upload, a blocked forged attach, a blocked and an approved send, logout.
        $attachment = $this->plugin->attach('c1', self::FILE);
        $_FILES = ['_attachments' => ['name' => ['secret-plan.pdf'], 'tmp_name' => ['/tmp/x'], 'error' => [0], 'size' => [1],
            'type' => ['application/pdf']]];
        $this->rcmail->plugins->exec_hook('ready', ['task' => 'mail', 'action' => 'upload']);
        $_FILES = [];
        try {
            $this->plugin->attach('forged', self::FILE);
        } catch (zemzemeh_dlp_attach_exception) {
        }

        $_POST['_id'] = 'c1';
        $blocked = new Mail_mime("\r\n");
        $blocked->headers(['From' => 'alice@example.test', 'To' => 'bob@example.test', 'Subject' => 'Quarterly numbers']);
        $blocked->setTXTBody('confidential body text');
        $blocked->addAttachment('local secret bytes', 'application/pdf', 'secret-plan.pdf', false);
        $this->rcmail->plugins->exec_hook('message_before_send',
            ['message' => $blocked, 'from' => 'alice@example.test', 'mailto' => 'bob@example.test', 'options' => null]);

        $sent = new Mail_mime("\r\n");
        $sent->headers(['From' => 'alice@example.test', 'To' => 'bob@example.test', 'Subject' => 'Quarterly numbers']);
        $sent->setTXTBody('confidential body text');
        $sent->addAttachment($attachment['path'], $attachment['mimetype'], $attachment['name'], true);
        $this->rcmail->plugins->exec_hook('message_before_send',
            ['message' => $sent, 'from' => 'alice@example.test', 'mailto' => 'bob@example.test', 'options' => null]);
        $this->rcmail->plugins->exec_hook('message_sent', ['headers' => [], 'body' => '', 'message' => $sent]);
        $this->rcmail->plugins->exec_hook('session_destroy', []);

        $log = file_get_contents($this->log);
        $events = array_column($this->events(), 'event');
        foreach (['file_selected', 'blocked_local_upload', 'blocked_forged_request', 'hash_mismatch', 'send_approved'] as $event) {
            $this->assertContains($event, $events);
        }
        foreach ([self::TOKEN, 'Bearer', ROUNDCUBE_TEST_SESSION, 'report.pdf', 'secret-plan.pdf', 'hello world',
            'local secret bytes', 'confidential body text', 'Quarterly numbers', 'bob@example.test',
            getenv('ZZDLP_TEST_DES_KEY')] as $secret) {
            $this->assertStringNotContainsString($secret, $log);
        }
        preg_match_all('/^.*$/m', trim($log), $lines);
        foreach ($lines[0] as $line) {
            $this->assertMatchesRegularExpression('/^\[[^\]]+\]: \{.*\}$/', $line);
            $this->assertIsArray(json_decode(substr($line, strpos($line, '{')), true));
        }
    }
}

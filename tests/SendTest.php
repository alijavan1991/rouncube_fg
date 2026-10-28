<?php

use PHPUnit\Framework\TestCase;

final class SendTest extends TestCase
{
    use ComposeFixture;

    private function message(): Mail_mime
    {
        $message = new Mail_mime("\r\n");
        $message->headers(['From' => 'alice@example.test', 'To' => 'bob@example.test', 'Subject' => 'Report']);
        $message->setTXTBody('Please find the report attached.');
        $message->setHTMLBody('<p>Please find the report attached.</p>');

        return $message;
    }

    private function attached(Mail_mime $message, string $file_id = self::FILE, string $compose = 'c1'): array
    {
        $attachment = $this->plugin->attach($compose, $file_id);
        $message->addAttachment($attachment['path'], $attachment['mimetype'], $attachment['name'], true);

        return $attachment;
    }

    private function send($message, string $compose = 'c1'): array
    {
        $_POST['_id'] = $compose;

        return $this->rcmail->plugins->exec_hook('message_before_send',
            ['message' => $message, 'from' => 'alice@example.test', 'mailto' => 'bob@example.test', 'options' => null]);
    }

    private function assertBlocked(array $result, string $reason): void
    {
        $this->assertTrue($result['abort'], $reason);
        $this->assertFalse($result['result']);
        $this->assertNotEmpty($result['error']);
        $blocked = array_values(array_filter($this->events(), static fn ($e) => isset($e['reason'])
            && in_array($e['event'], ['blocked_send', 'hash_mismatch', 'permission_denied'], true)));
        $this->assertSame($reason, end($blocked)['reason']);
    }

    private function assertAllowed(array $result): void
    {
        $this->assertFalse($result['abort'], json_encode($this->events()));
    }

    public function testApprovedZemzemehAttachmentIsSentAndItsApprovalConsumed(): void
    {
        $message = $this->message();
        $this->attached($message);

        $this->assertAllowed($this->send($message));

        // The hook serialized the message; Roundcube's own delivery serialization must still be consistent.
        $head = $message->txtHeaders(['Bcc' => null], true);
        $body = $message->get();
        preg_match('/boundary="([^"]+)"/', $head, $boundary);
        $this->assertStringContainsString('--' . $boundary[1], $body);
        $delivered = rcube_mime::parse_message($head . "\r\n\r\n" . $body);
        $hashes = [];
        $walk = static function ($part) use (&$walk, &$hashes) {
            foreach ((array) $part->parts as $child) {
                $walk($child);
            }
            if (!$part->parts) {
                $hashes[] = hash('sha256', (string) $part->body);
            }
        };
        $walk($delivered);
        $this->assertSame(1, count(array_keys($hashes, hash('sha256', 'hello world'))));

        $this->rcmail->plugins->exec_hook('message_sent', ['headers' => [], 'body' => '', 'message' => $message]);

        $this->assertNotNull($this->provenance()[0]['consumed_at']);
        $sent = array_values(array_filter($this->events(), static fn ($e) => $e['event'] === 'send_approved'));
        $this->assertSame([self::FILE], $sent[0]['file_ids']);
        $this->assertStringNotContainsString('report.pdf', file_get_contents($this->log));
    }

    public function testPlainMessagesNeedNoZemzemehEvenWhenInactive(): void
    {
        $this->rcmail->oauth = $this->original_oauth;  // password login
        $this->assertAllowed($this->send($this->message()));
    }

    public function testUnprovenAttachmentsAreBlocked(): void
    {
        $message = $this->message();
        $message->addAttachment('local secret', 'application/pdf', 'secret.pdf', false);
        $this->assertBlocked($this->send($message), 'unproven');
    }

    public function testPastedAndInlineImagesAreBlocked(): void
    {
        $message = $this->message();
        $message->addHTMLImage("\x89PNG pasted", 'image/png', 'pasted.png', false);  // rcmail_sendmail's data: URI conversion
        $this->assertBlocked($this->send($message), 'unproven');

        $message = $this->message();
        $message->setHTMLBody('<p><img src="data:image/png;base64,iVBORw0KGgo="></p>');
        $this->assertBlocked($this->send($message), 'inline_data');
    }

    public function testForwardedMessagesAreBlocked(): void
    {
        $message = $this->message();
        $message->addAttachment("Subject: fwd\r\n\r\nbody", 'message/rfc822', 'fwd.eml', false);
        $this->assertBlocked($this->send($message), 'unproven');
    }

    public function testRevokedAccessBetweenSelectionAndSendBlocks(): void
    {
        $message = $this->message();
        $this->attached($message, str_repeat('7', 32));
        $this->assertBlocked($this->send($message), 'access_revoked');
    }

    public function testReplacedFileOrAlteredBytesBlock(): void
    {
        $message = $this->message();
        $this->attached($message, str_repeat('6', 32));
        $this->assertBlocked($this->send($message), 'version_changed');

        $this->rcmail->delete_uploaded_files('c1');
        $message = $this->message();
        $attachment = $this->plugin->attach('c1', self::FILE);
        file_put_contents($attachment['path'], 'hello WORLD');  // spool tampered after approval
        $message->addAttachment($attachment['path'], $attachment['mimetype'], $attachment['name'], true);
        $this->assertBlocked($this->send($message), 'hash_mismatch');
    }

    public function testApprovalsAreBoundToTheirComposeAndExpire(): void
    {
        $message = $this->message();
        $this->attached($message);
        $this->assertBlocked($this->send($message, 'c2'), 'unproven');
        $this->assertBlocked($this->send($message, 'missing'), 'compose');

        $db = $this->rcmail->get_dbh();
        $db->query('UPDATE ' . $db->table_name('zemzemeh_dlp_provenance', true) . ' SET `expires_at` = ' . $db->now(-60));
        $this->assertBlocked($this->send($message), 'unproven');
    }

    public function testEachApprovalCoversOnePart(): void
    {
        $message = $this->message();
        $attachment = $this->attached($message);
        $message->addAttachment($attachment['path'], $attachment['mimetype'], 'copy.pdf', true);
        $this->assertBlocked($this->send($message), 'unproven');
    }

    public function testTokenFailureAtSendTimeFailsClosed(): void
    {
        $message = $this->message();
        $this->attached($message);
        $this->rcmail->oauth->valid = false;
        $this->assertBlocked($this->send($message), 'auth');
    }

    public function testOperatorAllowlistedSystemAssetsPass(): void
    {
        $logo = "\x89PNG company logo";
        $this->rcmail->config->set('zemzemeh_dlp_system_assets', [['sha256' => hash('sha256', $logo), 'mimetype' => 'image/png']]);
        $message = $this->message();
        $message->addHTMLImage($logo, 'image/png', 'logo.png', false);
        $this->assertAllowed($this->send($message));

        $message = $this->message();
        $message->addHTMLImage($logo, 'image/gif', 'logo.gif', false);  // same bytes, other type
        $this->assertBlocked($this->send($message), 'unproven');
    }

    public function testReadReceiptsAreOnlyAllowedForTheReceiptAction(): void
    {
        $receipt = new Mail_mime("\r\n");
        $receipt->headers(['From' => 'alice@example.test', 'To' => 'bob@example.test', 'Subject' => 'Read']);
        $receipt->setContentType('multipart/report', ['report-type' => 'disposition-notification']);
        $receipt->setTXTBody('Your message was displayed.');
        $receipt->addAttachment("Disposition: manual-action/MDN-sent-manually; displayed\r\n",
            'message/disposition-notification', 'MDNPart2.txt', false, '7bit', 'inline');

        $this->rcmail->action = 'sendmdn';
        $this->assertAllowed($this->send($receipt, ''));
        $this->rcmail->action = 'send';
        $this->assertBlocked($this->send($receipt), 'unproven');
    }

    public function testOtherSendersAndOpaqueStructuresAreBlocked(): void
    {
        $this->rcmail->action = 'bounce';
        $this->assertBlocked($this->send($this->message()), 'action');

        $this->rcmail->action = 'send';
        $this->assertBlocked($this->send(new rcmail_resend_mail(['bounce_message' => null, 'bounce_headers' => []])), 'message_class');

        $signed = $this->message();
        $signed->setContentType('multipart/signed', ['protocol' => 'application/pgp-signature']);
        $this->assertBlocked($this->send($signed), 'signed_or_encrypted');
    }
}

<?php

/**
 * Attachment parts of messages in the signed-in user's IMAP mailboxes. Parts are looked up
 * in the loaded message structure by MIME id; body parts and unknown ids are not found.
 */
class zemzemeh_dlp_message_source
{
    private array $loaded = [];

    /** @return ?array{name: string, mimetype: string} */
    public function attachment(string $mbox, string $uid, string $mime_id): ?array
    {
        if (!$found = $this->part($mbox, $uid, $mime_id)) {
            return null;
        }

        return ['name' => rcmail_action_mail_index::attachment_name($found[1]),
            'mimetype' => rcube_mime::fix_mimetype($found[1]->mimetype)];
    }

    /** Stream the decoded part into `$fp`. */
    public function write(string $mbox, string $uid, string $mime_id, $fp, int $max_bytes = 0): bool
    {
        $found = $this->part($mbox, $uid, $mime_id);

        return $found !== null && $found[0]->get_part_body($mime_id, false, $max_bytes, $fp) !== false;
    }

    /** @return ?array{0: rcube_message, 1: rcube_message_part} */
    private function part(string $mbox, string $uid, string $mime_id): ?array
    {
        $message = $this->loaded["{$mbox}\0{$uid}"] ??= new rcube_message($uid, $mbox);
        $part = empty($message->headers) ? null : ($message->attachments[$mime_id] ?? null);

        return $part ? [$message, $part] : null;
    }
}

<?php

/**
 * Stands in for IMAP: one message per "mailbox/uid", attachments keyed by MIME id.
 * `writes` counts part reads, so tests can prove refusals happen before any mail data is read.
 */
final class FakeMessages extends zemzemeh_dlp_message_source
{
    public int $writes = 0;
    public ?int $last_max_bytes = null;
    public bool $sabotage = false;

    public function __construct(private array $messages)
    {
    }

    #[\Override]
    public function attachment(string $mbox, string $uid, string $mime_id): ?array
    {
        $part = $this->messages["{$mbox}/{$uid}"][$mime_id] ?? null;

        return $part === null ? null : ['name' => $part['name'], 'mimetype' => $part['mimetype']];
    }

    #[\Override]
    public function write(string $mbox, string $uid, string $mime_id, $fp, int $max_bytes = 0): bool
    {
        $this->writes++;
        $this->last_max_bytes = $max_bytes;
        $part = $this->messages["{$mbox}/{$uid}"][$mime_id] ?? null;
        $body = $part['body'] ?? null;
        if ($body === null) {
            return false;
        }
        if ($max_bytes > 0) {
            $body = substr($body, 0, $max_bytes);
        }
        if (fwrite($fp, $body) !== strlen($body)) {
            return false;
        }
        if ($this->sabotage) {
            // Replace the spool file with a directory, which the spool refuses to delete.
            foreach (glob(rcmail::get_instance()->config->get('temp_dir') . '/RCMTEMPzzdlp*') as $path) {
                unlink($path);
                mkdir($path);
            }
        }

        return true;
    }
}

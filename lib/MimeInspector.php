<?php

/**
 * Lists every non-body MIME part of an outgoing message exactly as it will be delivered:
 * the message is serialized and re-parsed, so parts added by Roundcube (pasted images,
 * forwarded messages) or by any plugin are all seen. Unknown structures fail closed.
 */
final class zemzemeh_dlp_mime_inspector
{
    private const MAX_DEPTH = 20;
    private const INLINE_DATA = '/(?:\b(?:src|href|background|srcset|poster)\s*=\s*["\']?\s*|url\(\s*["\']?\s*)data:/i';

    /**
     * @return array{problem: ?string, parts: list<array{sha256: string, mimetype: string, size: int}>}
     */
    public static function inspect(Mail_mime $message, bool $receipt): array
    {
        $raw = $message->getMessage();
        $structure = is_string($raw) ? rcube_mime::parse_message($raw) : false;
        $parts = [];
        $problem = $structure ? self::walk($structure, $receipt, 0, $parts) : 'unparsable';

        return ['problem' => $problem, 'parts' => $problem ? [] : $parts];
    }

    private static function walk($part, bool $receipt, int $depth, array &$parts): ?string
    {
        if (!$part instanceof rcube_message_part || $depth > self::MAX_DEPTH) {
            return 'unparsable';
        }
        $type = strtolower((string) $part->mimetype);
        if ($type === 'multipart/signed' || $type === 'multipart/encrypted') {
            return 'signed_or_encrypted';
        }
        if (str_starts_with($type, 'multipart/') && !empty($part->parts)) {
            foreach ($part->parts as $child) {
                if ($problem = self::walk($child, $receipt, $depth + 1, $parts)) {
                    return $problem;
                }
            }

            return null;
        }
        // Roundcube's parser keeps unrecognized multiparts as one opaque part; only a read
        // receipt's generated report is exempt, for the sendmdn action alone.
        if ($receipt && $type === 'multipart/report') {
            return null;
        }
        if (($type === 'text/plain' || $type === 'text/html') && empty($part->filename)
                && strtolower((string) $part->disposition) !== 'attachment') {
            if ($type === 'text/html' && preg_match(self::INLINE_DATA, (string) $part->body)) {
                return 'inline_data';
            }

            return null;
        }

        $body = (string) $part->body;
        $parts[] = ['sha256' => hash('sha256', $body), 'mimetype' => $type, 'size' => strlen($body)];

        return null;
    }
}

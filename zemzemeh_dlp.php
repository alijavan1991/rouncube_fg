<?php

/**
 * Zemzemeh attachment DLP for Roundcube.
 *
 * Makes Zemzemeh the only source and destination of mail attachments: local uploads
 * and attachment downloads are refused server-side, attachments come from Zemzemeh
 * server-to-server, and every attachment is checked against server-side provenance
 * before sending. See README.md and the Zemzemeh design document.
 */
class zemzemeh_dlp extends rcube_plugin
{
    /** The only Roundcube release whose internals this plugin was verified against. */
    public const SUPPORTED_VERSION = '1.7.5';

    /** Plugin database schema version, as recorded in Roundcube's `system` table. */
    public const SCHEMA_VERSION = '2026102700';

    public $task = '.*';

    #[\Override]
    public function init()
    {
        $this->load_config();
    }
}

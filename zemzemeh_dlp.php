<?php

require_once __DIR__ . '/lib/Audit.php';
require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Guard.php';
require_once __DIR__ . '/lib/ProvenanceStore.php';
require_once __DIR__ . '/lib/SessionToken.php';
require_once __DIR__ . '/lib/Spool.php';

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

    /** Canonical mail actions (see canonical_action) that put attachment bytes in or out of the browser. */
    private const BLOCKED_ACTIONS = [
        'attachment_upload' => 'blocked_local_upload',
        'attachment_display' => 'blocked_download',
        'get' => 'blocked_download',
        'viewsource' => 'blocked_download',
        // A bounce re-sends the original body once and cannot be inspected before delivery.
        'bounce' => 'blocked_send',
    ];

    /** Roundcube's own action filter, as a second layer; it compares exact spellings. */
    private const DISABLED_ACTIONS = [
        'mail.upload', 'mail.attachment-upload', 'mail.attachment_upload', 'mail.display-attachment',
        'mail.attachment-display', 'mail.attachment_display', 'mail.get', 'mail.viewsource', 'mail.bounce',
    ];

    private const DENY = ['status' => false, 'abort' => true, 'break' => true];

    public $task = '.*';

    private ?array $problems = null;
    private ?string $blocked = null;

    #[\Override]
    public function init()
    {
        $rcmail = rcmail::get_instance();
        $this->load_config();
        $this->add_texts('localization/');
        $rcmail->config->set('disabled_actions',
            array_values(array_unique(array_merge((array) $rcmail->config->get('disabled_actions'), self::DISABLED_ACTIONS))));

        $this->add_hook('ready', [$this, 'ready']);
        $this->add_hook('attachment_upload', [$this, 'deny_upload']);
        $this->add_hook('attachment_save', [$this, 'deny_save']);
        $this->add_hook('attachment_from_uri', [$this, 'deny_from_uri']);
        $this->add_hook('attachment_display', [$this, 'deny_display']);
        $this->add_hook('message_part_get', [$this, 'deny_part']);
        $this->add_hook('message_compose', [$this, 'compose']);
        $this->register_action('plugin.zemzemeh_dlp.blocked', [$this, 'blocked']);

        if ($rcmail->task === 'mail' && $rcmail->action === 'compose') {
            $this->add_label('localuploadblocked');
            $this->include_script('zemzemeh_dlp.js');
        }
    }

    /**
     * Problem codes of the current environment; computed once all plugins are loaded.
     */
    public function problems(): array
    {
        if ($this->problems === null) {
            $rcmail = rcmail::get_instance();
            $this->problems = zemzemeh_dlp_guard::problems(
                version: RCMAIL_VERSION,
                loaded_plugins: $this->api->loaded_plugins(),
                extra_denied: (array) $rcmail->config->get('zemzemeh_dlp_denied_plugins', []),
                oauth_enabled: $rcmail->oauth !== null && $rcmail->oauth->is_enabled(),
                password_claim: $rcmail->config->get('oauth_password_claim') ?: null,
                temp_dir: (string) $rcmail->config->get('temp_dir'),
                web_root: INSTALL_PATH . 'public_html',
            );
        }

        return $this->problems;
    }

    /**
     * Whether Zemzemeh features may run. Blocking never depends on this.
     */
    public function is_active(): bool
    {
        return $this->problems() === [];
    }

    /**
     * Lowercase, resolve Roundcube's aliases and use the class-name spelling, so every
     * request that reaches a given action class maps to one name.
     */
    public static function canonical_action(string $action): string
    {
        $action = strtolower($action);
        $action = rcmail_action_mail_index::$aliases[$action] ?? $action;

        return str_replace('-', '_', $action);
    }

    /**
     * 'ready' runs after authentication and CSRF checks, immediately before dispatch.
     */
    public function ready(array $args): array
    {
        if ($args['task'] !== 'mail') {
            return $args;
        }
        $action = self::canonical_action((string) $args['action']);
        if (!isset(self::BLOCKED_ACTIONS[$action])) {
            return $args;
        }

        $event = self::BLOCKED_ACTIONS[$action];
        $fields = ['action' => $action];
        if ($action === 'attachment_upload' && (isset($_POST['_uri']) || isset($_GET['_uri']))) {
            $event = 'blocked_forged_request';
        } elseif ($action === 'bounce') {
            $fields['reason'] = 'bounce';
        }
        zemzemeh_dlp_audit::log($event, $fields);
        $this->blocked = $action;

        return ['action' => 'plugin.zemzemeh_dlp.blocked', 'break' => true] + $args;
    }

    /**
     * Response for a blocked request: no attachment data, a clear message.
     */
    public function blocked(): void
    {
        $rcmail = rcmail::get_instance();
        $message = match ($this->blocked) {
            'attachment_upload' => 'localuploadblocked',
            'bounce' => 'sendblocked',
            default => 'downloadblocked',
        };

        if ($this->blocked === 'attachment_upload') {
            // Mirror attachment_upload.php so the compose UI removes its pending entry.
            $rcmail->output->reset();
            $rcmail->output->command('display_message', $this->gettext($message), 'error');
            if ($uploadid = rcube_utils::get_input_string('_uploadid', rcube_utils::INPUT_GPC)) {
                $rcmail->output->command('remove_from_attachment_list', $uploadid);
            }
            $rcmail->output->send('iframe');
        }
        if ($rcmail->output->ajax_call) {
            $rcmail->output->show_message($this->gettext($message), 'error');
            $rcmail->output->send();
        }
        rcmail::raise_error(['code' => 403, 'message' => "zemzemeh_dlp blocked {$this->blocked}"], false, true);
    }

    public function deny_upload(array $args): array
    {
        if (rcmail::get_instance()->task !== 'mail') {
            return $args;
        }
        zemzemeh_dlp_audit::log('blocked_local_upload', ['hook' => 'attachment_upload']);

        return ['error' => $this->gettext('localuploadblocked')] + self::DENY + $args;
    }

    // Forwarded, draft and inline attachments; Zemzemeh-proven ones are re-admitted in a later increment.
    public function deny_save(array $args): array
    {
        if (rcmail::get_instance()->task !== 'mail') {
            return $args;
        }
        zemzemeh_dlp_audit::log('blocked_send', ['hook' => 'attachment_save', 'reason' => 'unproven_attachment']);

        return self::DENY + $args;
    }

    public function deny_from_uri(array $args): array
    {
        zemzemeh_dlp_audit::log('blocked_forged_request', ['hook' => 'attachment_from_uri']);

        return ['attachment' => null] + self::DENY + $args;
    }

    public function deny_display(array $args): array
    {
        if (rcmail::get_instance()->task !== 'mail') {
            return $args;
        }
        zemzemeh_dlp_audit::log('blocked_download', ['hook' => 'attachment_display']);

        return self::DENY + $args;
    }

    public function deny_part(array $args): array
    {
        zemzemeh_dlp_audit::log('blocked_download', ['hook' => 'message_part_get']);

        return self::DENY + $args;
    }

    public function compose(array $args): array
    {
        if (!empty($args['attachments'])) {
            zemzemeh_dlp_audit::log('blocked_local_upload', ['hook' => 'message_compose', 'count' => count($args['attachments'])]);
        }
        $args['attachments'] = [];

        return $args;
    }
}

<?php

require_once __DIR__ . '/lib/Audit.php';
require_once __DIR__ . '/lib/Client.php';
require_once __DIR__ . '/lib/Guard.php';
require_once __DIR__ . '/lib/MimeInspector.php';
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

    private ?string $blocked = null;
    private bool $inserting = false;
    private bool $dropped_notice = false;
    private ?array $approved = null;

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
        $this->add_hook('attachment_save', [$this, 'save']);
        $this->add_hook('attachment_from_uri', [$this, 'deny_from_uri']);
        $this->add_hook('attachment_display', [$this, 'deny_display']);
        $this->add_hook('message_part_get', [$this, 'deny_part']);
        $this->add_hook('message_compose', [$this, 'compose']);
        $this->add_hook('zemzemeh_dlp_insert', [$this, 'insert_own']);
        $this->add_hook('message_before_send', [$this, 'before_send']);
        $this->add_hook('message_sent', [$this, 'sent']);
        $this->register_action('plugin.zemzemeh_dlp.blocked', [$this, 'blocked']);
        $this->register_action('plugin.zemzemeh_dlp.list', [$this, 'action_list']);
        $this->register_action('plugin.zemzemeh_dlp.attach', [$this, 'action_attach']);

        if ($rcmail->task === 'mail' && $rcmail->action === 'compose') {
            $this->add_label('localuploadblocked', 'attachfromzemzemeh', 'pickerempty');
            $this->include_script('zemzemeh_dlp.js');
        }
    }

    /**
     * Problem codes of the current environment. Evaluated on use, after all plugins are loaded.
     */
    public function problems(): array
    {
        $rcmail = rcmail::get_instance();

        return zemzemeh_dlp_guard::problems(
            version: RCMAIL_VERSION,
            loaded_plugins: $this->api->loaded_plugins(),
            extra_denied: (array) $rcmail->config->get('zemzemeh_dlp_denied_plugins', []),
            oauth_enabled: $rcmail->oauth !== null && $rcmail->oauth->is_enabled(),
            password_claim: $rcmail->config->get('oauth_password_claim') ?: null,
            temp_dir: (string) $rcmail->config->get('temp_dir'),
            web_root: INSTALL_PATH . 'public_html',
        );
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

    /**
     * Attachments Roundcube re-reads from stored messages (reopened drafts, edit as new,
     * forwards, inline images). Admitted only when the bytes match a live approval of this
     * user that Zemzemeh still confirms; they then get a new spool file and approval for
     * this compose. Everything else is dropped.
     */
    public function save(array $args): array
    {
        $rcmail = rcmail::get_instance();
        if ($rcmail->task !== 'mail') {
            return $args;
        }
        $compose_id = (string) ($args['group'] ?? '');
        if (empty($rcmail->user->ID) || !preg_match('/^[0-9A-Za-z.]{1,128}$/', $compose_id)) {
            return $this->drop_saved($args, 'unproven_attachment');
        }

        // Bytes come from the message part (`data`) or a Roundcube temp file; nothing else is read.
        $temp_dir = realpath((string) $rcmail->config->get('temp_dir'));
        $source = null;
        if (is_string($args['data'] ?? null) && $args['data'] !== '') {
            $sha256 = hash('sha256', $args['data']);
        } elseif (is_string($args['path'] ?? null) && !is_link($args['path']) && is_file($args['path'])
                && $temp_dir !== false && dirname((string) realpath($args['path'])) === $temp_dir) {
            $source = $args['path'];
            $sha256 = hash_file('sha256', $source);
        } else {
            return $this->drop_saved($args, 'unproven_attachment');
        }

        $store = new zemzemeh_dlp_provenance_store($rcmail->get_dbh());
        $row = $store->reprovable((int) $rcmail->user->ID, $sha256);
        if (!$row) {
            return $this->drop_saved($args, 'unproven_attachment');
        }
        try {
            [$client, $subject] = $this->client();
            $meta = $client->metadata($row['zz_file_id']);
        } catch (zemzemeh_dlp_attach_exception $e) {
            return $this->drop_saved($args, $e->reason);
        } catch (zemzemeh_dlp_client_exception $e) {
            return $this->drop_saved($args, $e->kind === 'not_found' ? 'access_revoked' : $e->kind);
        }
        if (!hash_equals($row['zz_version'], $meta['version'])) {
            return $this->drop_saved($args, 'version_changed');
        }

        $spool = new zemzemeh_dlp_spool($temp_dir);
        [$name, $handle] = $spool->create();
        if ($source === null) {
            $written = fwrite($handle, $args['data']);
        } else {
            $input = fopen($source, 'r');
            $written = $input ? stream_copy_to_stream($input, $handle) : false;
            if ($input) {
                fclose($input);
            }
        }
        fclose($handle);
        $path = $spool->path($name);
        // The source could have changed since it was hashed; the spool copy is what gets sent.
        if ($written === false || !hash_equals($sha256, hash_file('sha256', $path))) {
            $spool->delete($name);

            return $this->drop_saved($args, 'storage');
        }

        $id = $this->new_upload_id();
        $attachment = ['name' => self::clean_name((string) ($args['name'] ?? $row['filename'])),
            'mimetype' => self::clean_type((string) ($args['mimetype'] ?? $row['mimetype']))];
        try {
            $store->add([
                'user_id' => $rcmail->user->ID, 'oidc_sub' => $subject,
                'session_hash' => zemzemeh_dlp_provenance_store::session_hash($rcmail->get_session_id()),
                'compose_id' => $compose_id, 'upload_id' => $id, 'zz_file_id' => $row['zz_file_id'],
                'zz_owner' => $row['zz_owner'], 'zz_version' => $row['zz_version'], 'sha256' => $sha256,
                'filename' => $attachment['name'], 'mimetype' => $attachment['mimetype'], 'size' => filesize($path),
                'spool_name' => $name,
            ], (int) $rcmail->config->get('zemzemeh_dlp_provenance_ttl', 28800));
        } catch (Throwable) {
            $spool->delete($name);

            return $this->drop_saved($args, 'storage');
        }
        if ($source !== null && str_starts_with(basename($source), RCUBE_TEMP_FILE_PREFIX)) {
            @unlink($source);  // Roundcube's own temp copy, superseded by the spool file
        }
        zemzemeh_dlp_audit::log('attachment_reproven', [
            'compose_id' => $compose_id, 'upload_id' => $id, 'file_id' => $row['zz_file_id'],
            'version' => $row['zz_version'], 'sha256' => $sha256, 'size' => filesize($path),
        ]);

        return ['id' => $id, 'path' => $path, 'data' => null, 'size' => filesize($path), 'status' => true,
            'abort' => false, 'break' => true] + $attachment + $args;
    }

    private function drop_saved(array $args, string $reason): array
    {
        $rcmail = rcmail::get_instance();
        zemzemeh_dlp_audit::log('blocked_send', ['hook' => 'attachment_save', 'reason' => $reason]);
        // Tell the person composing once per request; CLI scripts have nobody to tell.
        if ($rcmail->output && !$rcmail->output instanceof rcmail_output_cli && !$this->dropped_notice) {
            $this->dropped_notice = true;
            $rcmail->output->show_message($this->gettext('attachmentsdropped'), 'warning');
        }

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

    // ------------------------------------------------------------ Send-time enforcement

    /**
     * Final gate before SMTP: every non-body part must match an unexpired approval of this
     * compose session by SHA-256, and Zemzemeh must still confirm access and version.
     */
    public function before_send(array $args): array
    {
        $rcmail = rcmail::get_instance();
        $this->approved = null;
        $action = self::canonical_action((string) $rcmail->action);
        $message = $args['message'] ?? null;
        if ($action !== 'send' && $action !== 'sendmdn') {
            return $this->refuse_send($args, 'action');
        }
        // Subclasses (e.g. the bounce message) serialize differently or only once.
        if (!is_object($message) || get_class($message) !== Mail_mime::class) {
            return $this->refuse_send($args, 'message_class');
        }

        $inspection = zemzemeh_dlp_mime_inspector::inspect($message, $action === 'sendmdn');
        if ($inspection['problem']) {
            return $this->refuse_send($args, $inspection['problem']);
        }
        $parts = [];
        foreach ($inspection['parts'] as $part) {
            if ($this->is_system_asset($part)) {
                zemzemeh_dlp_audit::log('system_asset_allowed', ['sha256' => $part['sha256'], 'mimetype' => $part['mimetype']]);
            } else {
                $parts[] = $part;
            }
        }
        if (!$parts) {
            return $args;
        }
        if ($action !== 'send') {
            return $this->refuse_send($args, 'unproven');
        }

        $compose_id = (string) rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
        if (!preg_match('/^[0-9A-Za-z.]{1,128}$/', $compose_id) || empty($_SESSION['compose_data_' . $compose_id])) {
            return $this->refuse_send($args, 'compose');
        }
        if (!$this->is_active()) {
            return $this->refuse_send($args, 'inactive');
        }

        // Match parts to approvals one-to-one by content hash.
        $rows = (new zemzemeh_dlp_provenance_store($rcmail->get_dbh()))->for_compose(
            (int) $rcmail->user->ID, zemzemeh_dlp_provenance_store::session_hash($rcmail->get_session_id()), $compose_id);
        $matched = [];
        foreach ($parts as $part) {
            foreach ($rows as $i => $row) {
                if (hash_equals($row['sha256'], $part['sha256'])) {
                    $matched[] = $row;
                    unset($rows[$i]);
                    continue 2;
                }
            }

            return $this->refuse_send($args, $rows ? 'hash_mismatch' : 'unproven', $rows ? 'hash_mismatch' : 'blocked_send');
        }

        try {
            [$client] = $this->client();
        } catch (zemzemeh_dlp_attach_exception $e) {
            return $this->refuse_send($args, $e->reason);
        }
        foreach ($matched as $row) {
            try {
                $meta = $client->metadata($row['zz_file_id']);
            } catch (zemzemeh_dlp_client_exception $e) {
                return match ($e->kind) {
                    'not_found' => $this->refuse_send($args, 'access_revoked', 'permission_denied', ['file_id' => $row['zz_file_id']]),
                    'auth' => $this->refuse_send($args, 'auth'),
                    default => $this->refuse_send($args, 'unavailable'),
                };
            }
            if (!hash_equals($row['zz_version'], $meta['version'])) {
                return $this->refuse_send($args, 'version_changed', 'hash_mismatch', ['file_id' => $row['zz_file_id']]);
            }
        }

        $this->approved = ['message' => spl_object_id($message), 'compose_id' => $compose_id, 'rows' => $matched];

        return $args;
    }

    /**
     * After SMTP accepted the message: consume its approvals and record the send.
     */
    public function sent(array $args): array
    {
        $approved = $this->approved;
        $this->approved = null;
        if (!$approved || !is_object($args['message'] ?? null) || spl_object_id($args['message']) !== $approved['message']) {
            return $args;
        }
        $store = new zemzemeh_dlp_provenance_store(rcmail::get_instance()->get_dbh());
        foreach ($approved['rows'] as $row) {
            $store->consume((int) $row['id']);
        }
        zemzemeh_dlp_audit::log('send_approved', [
            'compose_id' => $approved['compose_id'],
            'file_ids' => array_column($approved['rows'], 'zz_file_id'),
            'sha256' => array_column($approved['rows'], 'sha256'),
        ]);

        return $args;
    }

    private function refuse_send(array $args, string $reason, string $event = 'blocked_send', array $fields = []): array
    {
        zemzemeh_dlp_audit::log($event, ['reason' => $reason] + $fields);

        return ['abort' => true, 'result' => false, 'error' => $this->gettext('sendblocked'), 'break' => true] + $args;
    }

    private function is_system_asset(array $part): bool
    {
        foreach ((array) rcmail::get_instance()->config->get('zemzemeh_dlp_system_assets', []) as $asset) {
            if (is_array($asset) && is_string($asset['sha256'] ?? null) && is_string($asset['mimetype'] ?? null)
                    && hash_equals(strtolower($asset['sha256']), $part['sha256'])
                    && strtolower($asset['mimetype']) === $part['mimetype']) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------------------ Attach from Zemzemeh

    /**
     * Metadata of files the user may attach; never file contents.
     */
    public function list_files(): array
    {
        [$client] = $this->client();
        try {
            $files = $client->list();
        } catch (zemzemeh_dlp_client_exception $e) {
            throw new zemzemeh_dlp_attach_exception($e->kind);
        }

        return array_map(static fn ($file) => [
            'id' => $file['id'], 'name' => $file['name'], 'content_type' => $file['content_type'], 'size' => $file['size'],
            'owner_username' => $file['owner_username'], 'created_at' => $file['created_at'] ?? null,
        ], $files);
    }

    /**
     * Attach from a browser request: POST only (Roundcube checks the request token on POST),
     * compose and file ids from the body; nothing else is taken from the browser.
     */
    public function attach_from_request(): array
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            zemzemeh_dlp_audit::log('blocked_forged_request', ['reason' => 'method']);
            throw new zemzemeh_dlp_attach_exception('forged');
        }

        return $this->attach(
            (string) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST),
            (string) rcube_utils::get_input_string('_file_id', rcube_utils::INPUT_POST)
        );
    }

    /**
     * Copy a Zemzemeh file the user may read into the compose session, server-to-server,
     * and record its provenance. Returns the Roundcube attachment record.
     */
    public function attach(string $compose_id, string $file_id): array
    {
        $rcmail = rcmail::get_instance();
        if (!preg_match('/^[0-9A-Za-z.]{1,128}$/', $compose_id) || empty($_SESSION['compose_data_' . $compose_id])) {
            zemzemeh_dlp_audit::log('blocked_forged_request', ['reason' => 'compose']);
            throw new zemzemeh_dlp_attach_exception('forged');
        }
        [$client, $subject] = $this->client();
        $spool = new zemzemeh_dlp_spool((string) $rcmail->config->get('temp_dir'));
        try {
            $download = $client->download($file_id, $spool, $this->allowance($compose_id));
        } catch (zemzemeh_dlp_client_exception $e) {
            if ($e->kind === 'not_found') {
                zemzemeh_dlp_audit::log('permission_denied', [
                    'file_id' => preg_match('/^[0-9a-f]{32}$/', $file_id) ? $file_id : 'invalid', 'compose_id' => $compose_id]);
            }
            throw new zemzemeh_dlp_attach_exception($e->kind);
        }

        $meta = $download['meta'];
        $attachment = [
            'path' => $spool->path($download['spool_name']), 'name' => self::clean_name($meta['name']),
            'mimetype' => self::clean_type($meta['content_type']), 'size' => $download['size'], 'group' => $compose_id,
        ];
        $this->inserting = true;
        try {
            $inserted = $rcmail->insert_uploaded_file($attachment, 'zemzemeh_dlp_insert');
        } finally {
            $this->inserting = false;
        }
        if (!$inserted) {
            $spool->delete($download['spool_name']);
            throw new zemzemeh_dlp_attach_exception('storage');
        }

        try {
            (new zemzemeh_dlp_provenance_store($rcmail->get_dbh()))->add([
                'user_id' => $rcmail->user->ID, 'oidc_sub' => $subject,
                'session_hash' => zemzemeh_dlp_provenance_store::session_hash($rcmail->get_session_id()),
                'compose_id' => $compose_id, 'upload_id' => $attachment['id'], 'zz_file_id' => $meta['id'],
                'zz_owner' => $meta['owner_username'], 'zz_version' => $meta['version'], 'sha256' => $download['sha256'],
                'filename' => $attachment['name'], 'mimetype' => $attachment['mimetype'], 'size' => $download['size'],
                'spool_name' => $download['spool_name'],
            ], (int) $rcmail->config->get('zemzemeh_dlp_provenance_ttl', 28800));
        } catch (Throwable) {
            // Without provenance the attachment could never be sent; remove it now.
            $rcmail->delete_uploaded_file($attachment['id']);
            $spool->delete($download['spool_name']);
            throw new zemzemeh_dlp_attach_exception('storage');
        }

        zemzemeh_dlp_audit::log('file_selected', [
            'compose_id' => $compose_id, 'upload_id' => $attachment['id'], 'file_id' => $meta['id'],
            'version' => $meta['version'], 'sha256' => $download['sha256'], 'size' => $download['size'],
        ]);

        return $attachment;
    }

    /**
     * Answers only insertions made by attach(); the hook name alone proves nothing.
     */
    public function insert_own(array $args): array
    {
        if (!$this->inserting) {
            return self::DENY + $args;
        }
        return ['id' => $this->new_upload_id(), 'status' => true, 'abort' => false, 'break' => true] + $args;
    }

    private function new_upload_id(): string
    {
        $rcmail = rcmail::get_instance();
        do {
            $id = $rcmail->user->ID . random_int(100000000000, 999999999999);
        } while ($rcmail->get_uploaded_file($id));

        return $id;
    }

    public function action_list(): void
    {
        $rcmail = rcmail::get_instance();
        try {
            $rcmail->output->command('plugin.zemzemeh_dlp_files', $this->list_files());
        } catch (zemzemeh_dlp_attach_exception $e) {
            $rcmail->output->show_message($this->error_message($e->reason), 'error');
        }
        $rcmail->output->send();
    }

    public function action_attach(): void
    {
        $rcmail = rcmail::get_instance();
        try {
            $attachment = $this->attach_from_request();
            // Render the standard compose attachment row; the compose id was validated above.
            rcmail_action_mail_attachment_upload::init();
            rcmail_action_mail_attachment_upload::attachment_success($attachment, null);
            $rcmail->output->command('auto_save_start', false);
        } catch (zemzemeh_dlp_attach_exception $e) {
            $rcmail->output->show_message($this->error_message($e->reason), 'error');
        }
        $rcmail->output->send();
    }

    /** Bytes still allowed by max_message_size, using Roundcube's own estimate. */
    private function allowance(string $compose_id): int
    {
        $rcmail = rcmail::get_instance();
        $limit = parse_bytes($rcmail->config->get('max_message_size'));
        if (!$limit) {
            return \PHP_INT_MAX;
        }
        $used = 10 * 1024;
        foreach ($rcmail->list_uploaded_files($compose_id) as $attachment) {
            $used += $attachment['size'] * ($attachment['mimetype'] == 'message/rfc822' ? 1 : 1.33);
        }

        return max(0, (int) floor(($limit - $used) / 1.33));
    }

    /** @return array{0: zemzemeh_dlp_client, 1: string} */
    private function client(): array
    {
        $rcmail = rcmail::get_instance();
        $url = (string) $rcmail->config->get('zemzemeh_dlp_api_url');
        if (!$this->is_active() || $url === '') {
            throw new zemzemeh_dlp_attach_exception('inactive');
        }
        try {
            $token = new zemzemeh_dlp_session_token($rcmail, (int) $rcmail->config->get('zemzemeh_dlp_token_margin', 30));
            $bearer = $token->bearer();
            $subject = $token->subject();
        } catch (zemzemeh_dlp_auth_exception) {
            throw new zemzemeh_dlp_attach_exception('auth');
        }

        return [new zemzemeh_dlp_client($url, $bearer, (bool) $rcmail->config->get('zemzemeh_dlp_verify_tls', true),
            (int) $rcmail->config->get('zemzemeh_dlp_timeout', 30)), $subject];
    }

    private function error_message(string $reason): string
    {
        return $this->gettext(match ($reason) {
            'auth' => 'signinagain',
            'not_found' => 'filenotavailable',
            'too_large' => 'filetoolarge',
            'changed' => 'filechanged',
            'inactive' => 'notavailable',
            'forged' => 'requestrefused',
            default => 'zemzemehunavailable',
        });
    }

    // Zemzemeh already strips directories and NULs; also drop other controls before MIME headers.
    private static function clean_name(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/', '_', $name));

        return $name === '' ? 'attachment' : $name;
    }

    private static function clean_type(string $type): string
    {
        $type = strtolower($type);

        return strlen($type) <= 255 && preg_match('#^[a-z0-9][a-z0-9!\#$&^_.+-]*/[a-z0-9][a-z0-9!\#$&^_.+-]*$#', $type)
            ? $type : 'application/octet-stream';
    }
}

/** An attach request was refused; `reason` selects the user message. */
final class zemzemeh_dlp_attach_exception extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

# zemzemeh_dlp — Roundcube attachment DLP for Zemzemeh

Roundcube plugin that makes Zemzemeh, a self-hosted encrypted file workspace, the only
source and destination of mail attachments:

- outgoing attachments are picked from Zemzemeh and transferred server-to-server;
- local uploads (file picker, drag-and-drop, paste, direct requests) are refused;
- received attachments cannot be downloaded or previewed, only saved to Zemzemeh;
- every attachment is checked against server-side provenance before sending.

It controls the Roundcube attachment channel only. It does not prevent copying text,
screenshots, other mail clients or other exfiltration from the endpoint.

**Status:** outgoing enforcement implemented and unit-tested (delivery Tasks 4-9):
blocking, Attach from Zemzemeh, send-time provenance checks, draft re-proving, cleanup
and GC. Not yet verified against a live Roundcube/Keycloak/Zemzemeh stack (Task 11), and
Save to Zemzemeh is a later increment; do not deploy yet. Design and plan live in the Zemzemeh repository:
`docs/superpowers/specs/2026-10-27-roundcube-zemzemeh-dlp-design.md` and
`docs/superpowers/plans/2026-10-27-roundcube-zemzemeh-dlp.md`.

## Requirements

- Roundcube **1.7.5** exactly (the plugin relies on verified internals and will refuse
  other versions), PHP 8.4.
- Roundcube signs users in with Keycloak OIDC and requests the `zemzemeh.mail` scope.
- Zemzemeh with `MAIL_INTEGRATION_ENABLED=true` and matching `MAIL_OIDC_*` settings.
- Roundcube's `temp_dir` is the attachment spool: outside the web root, mode 0700,
  ideally tmpfs.

## Install (outline)

1. Copy or check out this repository as `plugins/zemzemeh_dlp`.
2. Copy `config.inc.php.dist` to `config.inc.php` and set `zemzemeh_dlp_api_url`.
3. Create the table: `bin/initdb.sh --dir=plugins/zemzemeh_dlp/SQL`.
4. Add `zemzemeh_dlp` to `$config['plugins']`.
5. Run the cleanup every few minutes as the PHP user, e.g.
   `*/5 * * * * php /path/to/roundcube/plugins/zemzemeh_dlp/bin/gc.php`. It removes
   expired approvals and spool files of ended sessions or expired approvals; a non-zero
   exit means a file could not be removed (audited as `cleanup_failed`).

Full deployment guidance (Keycloak client and scope, Zemzemeh settings, reverse proxy,
Roundcube configuration, spool directory, validation) is in the Zemzemeh repository:
`docs/roundcube-dlp.md`.

## Tests

PHPUnit runs inside the pinned Roundcube image against a throwaway SQLite database;
no mail server, IdP or Zemzemeh is contacted.

```bash
docker compose -f tests/docker/compose.yml run --rm tests
```

## License

Not yet chosen by the owner.

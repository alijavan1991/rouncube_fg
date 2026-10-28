<?php

/**
 * Server-side record of Zemzemeh files approved for a compose session. Only plugin
 * server code writes it; no browser input is stored apart from ids Zemzemeh authorized.
 */
final class zemzemeh_dlp_provenance_store
{
    private const FIELDS = [
        'user_id', 'oidc_sub', 'session_hash', 'compose_id', 'upload_id', 'zz_file_id', 'zz_owner', 'zz_version',
        'sha256', 'filename', 'mimetype', 'size', 'spool_name',
    ];

    public function __construct(private rcube_db $db)
    {
    }

    /** Session ids are bearer secrets; records keep only their digest. */
    public static function session_hash(string $session_id): string
    {
        return hash('sha256', $session_id);
    }

    public function add(array $row, int $ttl): int
    {
        $missing = array_diff(self::FIELDS, array_keys($row));
        if ($missing) {
            throw new InvalidArgumentException('Provenance record is missing ' . implode(', ', $missing));
        }
        $columns = implode(', ', array_map(static fn ($field) => "`{$field}`", self::FIELDS));
        $result = $this->db->query(
            "INSERT INTO {$this->table()} ({$columns}, `decision`, `selected_at`, `expires_at`)"
            . ' VALUES (' . implode(', ', array_fill(0, count(self::FIELDS), '?')) . ", 'approved', "
            . $this->db->now() . ', ' . $this->db->now($ttl) . ')',
            array_map(static fn ($field) => $row[$field], self::FIELDS)
        );
        if (!$result || !$this->db->affected_rows($result)) {
            throw new RuntimeException('Could not store provenance record');
        }

        return (int) $this->db->insert_id('zemzemeh_dlp_provenance');
    }

    /** Active (unexpired, unconsumed) records of one compose session. */
    public function for_compose(int $user_id, string $session_hash, string $compose_id): array
    {
        $result = $this->db->query(
            "SELECT * FROM {$this->table()} WHERE `user_id` = ? AND `session_hash` = ? AND `compose_id` = ?"
            . " AND {$this->active()} ORDER BY `id`",
            $user_id, $session_hash, $compose_id
        );
        $rows = [];
        while ($row = $this->db->fetch_assoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function by_upload(string $session_hash, string $upload_id): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM {$this->table()} WHERE `session_hash` = ? AND `upload_id` = ? AND {$this->active()}",
            $session_hash, $upload_id
        );

        return $this->db->fetch_assoc($result) ?: null;
    }

    /**
     * Newest unexpired approval of this user for these bytes, in any compose or session
     * (consumed ones included: a sent message's attachment came from Zemzemeh too).
     */
    public function reprovable(int $user_id, string $sha256): ?array
    {
        $result = $this->db->limitquery(
            "SELECT * FROM {$this->table()} WHERE `user_id` = ? AND `sha256` = ? AND `expires_at` > {$this->db->now()}"
            . ' ORDER BY `id` DESC', 0, 1, $user_id, $sha256);

        return $this->db->fetch_assoc($result) ?: null;
    }

    /** Every record of a session (optionally one compose), expired and consumed included, for cleanup. */
    public function for_cleanup(string $session_hash, ?string $compose_id = null): array
    {
        $sql = "SELECT * FROM {$this->table()} WHERE `session_hash` = ?";
        $params = [$session_hash];
        if ($compose_id !== null) {
            $sql .= ' AND `compose_id` = ?';
            $params[] = $compose_id;
        }
        $result = $this->db->query($sql, $params);
        $rows = [];
        while ($row = $this->db->fetch_assoc($result)) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function consume(int $id): bool
    {
        $result = $this->db->query(
            "UPDATE {$this->table()} SET `consumed_at` = {$this->db->now()} WHERE `id` = ? AND `consumed_at` IS NULL", $id);

        return $result && $this->db->affected_rows($result) > 0;
    }

    /** Delete one attachment's record and return it, so the caller can remove its spool file. */
    public function delete_upload(string $session_hash, string $upload_id): ?array
    {
        $result = $this->db->query(
            "SELECT * FROM {$this->table()} WHERE `session_hash` = ? AND `upload_id` = ?", $session_hash, $upload_id);
        $row = $this->db->fetch_assoc($result);
        if (!$row) {
            return null;
        }
        $this->db->query("DELETE FROM {$this->table()} WHERE `id` = ?", $row['id']);

        return $row;
    }

    private function table(): string
    {
        return $this->db->table_name('zemzemeh_dlp_provenance', true);
    }

    private function active(): string
    {
        return '`consumed_at` IS NULL AND `expires_at` > ' . $this->db->now();
    }
}

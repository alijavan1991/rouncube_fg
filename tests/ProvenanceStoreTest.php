<?php

use PHPUnit\Framework\TestCase;

final class ProvenanceStoreTest extends TestCase
{
    private rcube_db $db;
    private zemzemeh_dlp_provenance_store $store;
    private int $user;

    protected function setUp(): void
    {
        $this->db = rcmail::get_instance()->get_dbh();
        $this->db->query('DELETE FROM ' . $this->db->table_name('zemzemeh_dlp_provenance', true));
        $this->db->query('INSERT INTO ' . $this->db->table_name('users', true)
            . " (`username`, `mail_host`, `created`) VALUES (?, 'localhost', " . $this->db->now() . ')', 'u' . bin2hex(random_bytes(4)));
        $this->user = (int) $this->db->insert_id('users');
        $this->store = new zemzemeh_dlp_provenance_store($this->db);
    }

    private function row(array $changes = []): array
    {
        return $changes + [
            'user_id' => $this->user, 'oidc_sub' => 'sub-1', 'session_hash' => hash('sha256', 'session-1'),
            'compose_id' => 'compose-1', 'upload_id' => '101', 'zz_file_id' => str_repeat('a', 32), 'zz_owner' => 'alice',
            'zz_version' => str_repeat('1', 64), 'sha256' => hash('sha256', 'hello world'), 'filename' => 'report.pdf',
            'mimetype' => 'application/pdf', 'size' => 11, 'spool_name' => 'RCMTEMPzzdlp' . str_repeat('c', 32),
        ];
    }

    public function testStoresAnApprovedRecordForItsComposeSession(): void
    {
        $id = $this->store->add($this->row(), 3600);
        $rows = $this->store->for_compose($this->user, hash('sha256', 'session-1'), 'compose-1');

        $this->assertCount(1, $rows);
        $this->assertSame($id, (int) $rows[0]['id']);
        $this->assertSame('approved', $rows[0]['decision']);
        $this->assertSame(hash('sha256', 'hello world'), $rows[0]['sha256']);
        $this->assertSame('101', $this->store->by_upload(hash('sha256', 'session-1'), '101')['upload_id']);
    }

    public function testRecordsAreScopedToUserSessionAndCompose(): void
    {
        $this->store->add($this->row(), 3600);
        $session = hash('sha256', 'session-1');

        $this->assertSame([], $this->store->for_compose($this->user + 1000, $session, 'compose-1'));
        $this->assertSame([], $this->store->for_compose($this->user, hash('sha256', 'session-2'), 'compose-1'));
        $this->assertSame([], $this->store->for_compose($this->user, $session, 'compose-2'));
        $this->assertNull($this->store->by_upload(hash('sha256', 'session-2'), '101'));
    }

    public function testExpiredAndConsumedRecordsAreNotReturned(): void
    {
        $this->store->add($this->row(['upload_id' => '201']), -10);
        $consumed = $this->store->add($this->row(['upload_id' => '202']), 3600);
        $this->assertTrue($this->store->consume($consumed));

        $this->assertSame([], $this->store->for_compose($this->user, hash('sha256', 'session-1'), 'compose-1'));
        $this->assertNull($this->store->by_upload(hash('sha256', 'session-1'), '201'));
        $this->assertNull($this->store->by_upload(hash('sha256', 'session-1'), '202'));
    }

    public function testDeletingAnUploadReturnsItsSpoolName(): void
    {
        $this->store->add($this->row(), 3600);
        $deleted = $this->store->delete_upload(hash('sha256', 'session-1'), '101');

        $this->assertSame('RCMTEMPzzdlp' . str_repeat('c', 32), $deleted['spool_name']);
        $this->assertNull($this->store->delete_upload(hash('sha256', 'session-1'), '101'));
    }

    public function testRejectsIncompleteOrDuplicateRecords(): void
    {
        $row = $this->row();
        unset($row['sha256']);
        try {
            $this->store->add($row, 3600);
            $this->fail('accepted a record without a hash');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $this->store->add($this->row(), 3600);
        $this->expectException(RuntimeException::class);
        $this->store->add($this->row(), 3600);  // same session and Roundcube attachment id
    }

    public function testSessionHashNeverStoresTheSessionId(): void
    {
        $this->assertSame(hash('sha256', 'abc'), zemzemeh_dlp_provenance_store::session_hash('abc'));
    }
}

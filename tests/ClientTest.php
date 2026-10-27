<?php

use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const TOKEN = 'stub-bearer-token';

    private static StubServer $server;
    private static string $url;
    private static string $log;
    private zemzemeh_dlp_spool $spool;

    public static function setUpBeforeClass(): void
    {
        self::$server = new StubServer();
        self::$url = self::$server->url;
        self::$log = self::$server->log;
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    protected function setUp(): void
    {
        @unlink(self::$log);
        $this->spool = new zemzemeh_dlp_spool(rcmail::get_instance()->config->get('temp_dir'));
    }

    private function client(?string $url = null): zemzemeh_dlp_client
    {
        return new zemzemeh_dlp_client($url ?? self::$url, self::TOKEN, true, 5);
    }

    private function spooled(): array
    {
        return glob(rcmail::get_instance()->config->get('temp_dir') . '/RCMTEMPzzdlp*');
    }

    private function assertFailure(string $kind, callable $call): void
    {
        try {
            $call();
            $this->fail("expected {$kind}");
        } catch (zemzemeh_dlp_client_exception $e) {
            $this->assertSame($kind, $e->kind);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
        }
    }

    public function testListsAndReadsMetadataWithTheUsersBearerToken(): void
    {
        $files = $this->client()->list();
        $this->assertSame(str_repeat('a', 32), $files[0]['id']);
        $this->assertSame(str_repeat('1', 64), $this->client()->metadata(str_repeat('a', 32))['version']);
        foreach (file(self::$log, FILE_IGNORE_NEW_LINES) as $line) {
            $this->assertStringStartsWith('Bearer ' . self::TOKEN . ' /api/mail/files', $line);
        }
    }

    public function testErrorsAreClassifiedWithoutLeakingTheToken(): void
    {
        $this->assertFailure('not_found', fn () => $this->client()->metadata(str_repeat('b', 32)));
        $this->assertFailure('auth', fn () => $this->client()->metadata(str_repeat('c', 32)));
        $this->assertFailure('unavailable', fn () => $this->client()->metadata(str_repeat('d', 32)));
        $this->assertFailure('unavailable', fn () => $this->client('http://127.0.0.1:9')->list());
    }

    public function testForgedFileIdsNeverReachZemzemeh(): void
    {
        foreach (['../admin', str_repeat('a', 31), str_repeat('A', 32), 'a/b', ''] as $id) {
            $this->assertFailure('not_found', fn () => $this->client()->metadata($id));
        }
        $this->assertFileDoesNotExist(self::$log);
    }

    public function testDownloadsIntoTheSpoolWhileHashing(): void
    {
        $result = $this->client()->download(str_repeat('a', 32), $this->spool, 1000);

        $this->assertSame('report.pdf', $result['meta']['name']);
        $this->assertSame(str_repeat('1', 64), $result['meta']['version']);
        $this->assertSame(11, $result['size']);
        $this->assertSame(hash('sha256', 'hello world'), $result['sha256']);
        $this->assertSame('hello world', file_get_contents($this->spool->path($result['spool_name'])));
        $this->spool->delete($result['spool_name']);
    }

    public function testChangedTruncatedOrOversizedContentLeavesNoSpoolFile(): void
    {
        $before = $this->spooled();
        $this->assertFailure('changed', fn () => $this->client()->download(str_repeat('e', 32), $this->spool, 1000));
        $this->assertFailure('changed', fn () => $this->client()->download(str_repeat('f', 32), $this->spool, 1000));
        $this->assertFailure('changed', fn () => $this->client()->download(str_repeat('9', 32), $this->spool, 1000));
        $this->assertFailure('too_large', fn () => $this->client()->download(str_repeat('8', 32), $this->spool, 1000));
        $this->assertFailure('not_found', fn () => $this->client()->download(str_repeat('b', 32), $this->spool, 1000));
        $this->assertSame($before, $this->spooled());
        // Oversized files are refused from their metadata, before any content request.
        $this->assertStringNotContainsString(str_repeat('8', 32) . '/content', (string) file_get_contents(self::$log));
    }

    public function testTlsVerificationIsPassedThrough(): void
    {
        $this->assertTrue($this->client()->verifies_tls());
        $this->assertFalse((new zemzemeh_dlp_client(self::$url, self::TOKEN, false, 5))->verifies_tls());
    }
}

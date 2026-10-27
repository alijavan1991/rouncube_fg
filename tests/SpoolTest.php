<?php

use PHPUnit\Framework\TestCase;

final class SpoolTest extends TestCase
{
    private string $dir;
    private zemzemeh_dlp_spool $spool;

    protected function setUp(): void
    {
        $this->dir = rcmail::get_instance()->config->get('temp_dir');
        $this->spool = new zemzemeh_dlp_spool($this->dir);
    }

    public function testCreatesPrivateUnpredictableFilesThatRoundcubeAccepts(): void
    {
        [$name, $handle] = $this->spool->create();
        [$other, $other_handle] = $this->spool->create();
        fwrite($handle, 'plaintext');
        fclose($handle);
        fclose($other_handle);

        $this->assertMatchesRegularExpression('/^RCMTEMPzzdlp[0-9a-f]{32}$/', $name);
        $this->assertNotSame($name, $other);
        $path = $this->spool->path($name);
        $this->assertSame($this->dir . '/' . $name, $path);
        $this->assertSame(0600, fileperms($path) & 0777);
        $this->assertSame('plaintext', file_get_contents($path));

        // filesystem_attachments only serves paths directly inside temp_dir.
        $rcmail = rcmail::get_instance();
        $rcmail->task = 'mail';
        $this->assertSame($path, $rcmail->plugins->exec_hook('attachment_get', ['path' => $path])['path']);

        $this->spool->delete($name);
        $this->spool->delete($other);
    }

    public function testRejectsNamesThatAreNotSpoolNames(): void
    {
        foreach (['', '../etc/passwd', 'RCMTEMPzzdlp' . str_repeat('g', 32), 'RCMTEMPzzdlp' . str_repeat('A', 32),
            'RCMTEMPzzdlp' . str_repeat('a', 31), 'x/RCMTEMPzzdlp' . str_repeat('a', 32), 'RCMTEMPattmnt123'] as $name) {
            try {
                $this->spool->path($name);
                $this->fail("accepted {$name}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testSymlinksAreNeverFollowed(): void
    {
        $target = getenv('ZZDLP_TEST_DIR') . '/outside';
        file_put_contents($target, 'keep me');
        $name = 'RCMTEMPzzdlp' . str_repeat('a', 32);
        symlink($target, $this->dir . '/' . $name);

        try {
            $this->spool->path($name);
            $this->fail('followed a symlink');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
        $this->assertTrue($this->spool->delete($name));
        $this->assertFalse(is_link($this->dir . '/' . $name));
        $this->assertSame('keep me', file_get_contents($target));
    }

    public function testDeleteIsIdempotentAndReportsFailure(): void
    {
        [$name, $handle] = $this->spool->create();
        fclose($handle);
        $this->assertTrue($this->spool->delete($name));
        $this->assertTrue($this->spool->delete($name));

        $stuck = 'RCMTEMPzzdlp' . str_repeat('b', 32);
        mkdir($this->dir . '/' . $stuck);  // unlink() cannot remove a directory
        $this->assertFalse(@$this->spool->delete($stuck));
        rmdir($this->dir . '/' . $stuck);
    }

    public function testRequiresAnAbsoluteExistingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new zemzemeh_dlp_spool('temp/');
    }
}

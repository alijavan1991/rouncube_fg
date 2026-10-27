<?php

use PHPUnit\Framework\TestCase;

final class SessionTokenTest extends TestCase
{
    private rcmail $rcmail;
    private FakeOauth $oauth;
    private $original_oauth;

    protected function setUp(): void
    {
        $this->rcmail = rcmail::get_instance();
        $this->original_oauth = $this->rcmail->oauth;
        $this->oauth = new FakeOauth();
        $this->rcmail->oauth = $this->oauth;
        $_SESSION = [
            'password' => $this->rcmail->encrypt('Bearer session-access-token'),
            'oauth_token' => ['expires' => time() + 200, 'identity' => ['sub' => 'sub-1']],
        ];
    }

    protected function tearDown(): void
    {
        $this->rcmail->oauth = $this->original_oauth;
        $_SESSION = [];
    }

    private function token(): zemzemeh_dlp_session_token
    {
        return new zemzemeh_dlp_session_token($this->rcmail, 30);
    }

    private function assertRefused(string $secret = 'session-access-token'): void
    {
        try {
            $this->token()->bearer();
            $this->fail('token was returned');
        } catch (zemzemeh_dlp_auth_exception $e) {
            $this->assertStringNotContainsString($secret, $e->getMessage());
        }
    }

    public function testReturnsTheSessionAccessTokenAndSubject(): void
    {
        $token = $this->token();
        $this->assertSame('session-access-token', $token->bearer());
        $this->assertSame('sub-1', $token->subject());
        $this->assertSame(0, $this->oauth->refreshes);
    }

    public function testRefreshesWhenTheTokenExpiresWithinTheMargin(): void
    {
        $_SESSION['oauth_token']['expires'] = time() + 10;
        $this->oauth->refreshed_token = 'fresh-token';
        $this->assertSame('fresh-token', $this->token()->bearer());
        $this->assertSame(1, $this->oauth->refreshes);
    }

    public function testFailsClosedWithoutAUsableToken(): void
    {
        $_SESSION['oauth_token']['expires'] = time() + 10;
        $this->assertRefused();  // refresh fails

        $this->setUp();
        $this->oauth->valid = false;
        $this->assertRefused();

        $this->setUp();
        $this->oauth->enabled = false;
        $this->assertRefused();

        $this->setUp();
        $this->rcmail->oauth = null;
        $this->assertRefused();

        $this->setUp();
        unset($_SESSION['oauth_token']);
        $this->assertRefused();

        $this->setUp();
        $_SESSION['password'] = $this->rcmail->encrypt('imap-password');  // password login, not OAuth
        $this->assertRefused('imap-password');
    }

    public function testSubjectIsRequired(): void
    {
        unset($_SESSION['oauth_token']['identity']);
        $this->expectException(zemzemeh_dlp_auth_exception::class);
        $this->token()->subject();
    }
}

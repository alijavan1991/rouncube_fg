<?php

/** Stands in for rcmail_oauth: the plugin only calls these three public methods. */
final class FakeOauth
{
    public bool $enabled = true;
    public bool $valid = true;
    public ?string $refreshed_token = null;
    public int $refreshes = 0;

    public function is_enabled(): bool
    {
        return $this->enabled;
    }

    public function is_token_valid(): bool
    {
        return $this->valid;
    }

    public function refresh_access_token(array $token)
    {
        $this->refreshes++;
        if ($this->refreshed_token === null) {
            return false;
        }
        $_SESSION['password'] = rcmail::get_instance()->encrypt('Bearer ' . $this->refreshed_token);
        $_SESSION['oauth_token'] = ['expires' => time() + 300] + $token;

        return ['token' => [], 'authorization' => 'Bearer ' . $this->refreshed_token];
    }
}

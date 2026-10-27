<?php

/** The user must sign in again; never carries token material in its message. */
final class zemzemeh_dlp_auth_exception extends RuntimeException
{
}

/**
 * The signed-in user's OIDC access token from Roundcube's OAuth session (Approach B:
 * it carries the Zemzemeh mail audience). Read server-side only, never from a request.
 */
final class zemzemeh_dlp_session_token
{
    public function __construct(private rcmail $rcmail, private int $margin)
    {
    }

    public function bearer(): string
    {
        $oauth = $this->rcmail->oauth;
        if (!$oauth || !$oauth->is_enabled() || empty($_SESSION['oauth_token'])) {
            throw new zemzemeh_dlp_auth_exception('No OAuth session');
        }
        if (!$oauth->is_token_valid()) {
            throw new zemzemeh_dlp_auth_exception('OAuth session expired');
        }
        $expires = $_SESSION['oauth_token']['expires'] ?? null;
        if ($expires !== null && $expires - time() < $this->margin
                && $oauth->refresh_access_token($_SESSION['oauth_token']) === false) {
            throw new zemzemeh_dlp_auth_exception('OAuth token refresh failed');
        }

        $authorization = (string) $this->rcmail->decrypt($_SESSION['password'] ?? '');
        if (!preg_match('/^Bearer (\S+)$/', $authorization, $match)) {
            throw new zemzemeh_dlp_auth_exception('Session is not authenticated with an OAuth bearer token');
        }

        return $match[1];
    }

    public function subject(): string
    {
        $sub = $_SESSION['oauth_token']['identity']['sub'] ?? null;
        if (!is_string($sub) || $sub === '') {
            throw new zemzemeh_dlp_auth_exception('OAuth identity has no subject');
        }

        return $sub;
    }
}

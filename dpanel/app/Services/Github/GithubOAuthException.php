<?php

namespace App\Services\Github;

/** A failed token request against GitHub's OAuth endpoint, with its error code. */
class GithubOAuthException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = '')
    {
        parent::__construct($message);
    }

    /** GitHub says the refresh token is gone: only reconnecting can fix it. */
    public function refreshTokenRejected(): bool
    {
        return in_array($this->errorCode, ['bad_refresh_token', 'bad_verification_code'], true)
            || str_contains(strtolower($this->getMessage()), 'refresh token');
    }
}

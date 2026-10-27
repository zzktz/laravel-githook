<?php

namespace Zzktz\LaravelGithook\Services;

class WebhookAuthenticator
{
    public function verifyGiteeToken($token, $secret)
    {
        if (!is_string($token) || $token === '' || !is_string($secret) || $secret === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }

    public function verifyGithubSignature($signature, $body, $secret)
    {
        if (!is_string($signature) || !is_string($body) || !is_string($secret) || $secret === '') {
            return false;
        }

        if (preg_match('/\Asha256=[a-f0-9]{64}\z/i', $signature) !== 1) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

        return hash_equals($expected, strtolower($signature));
    }
}

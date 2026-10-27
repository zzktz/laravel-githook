<?php

namespace Zzktz\LaravelGithook\Tests;

use PHPUnit\Framework\TestCase;
use Zzktz\LaravelGithook\Services\WebhookAuthenticator;

class WebhookAuthenticatorTest extends TestCase
{
    private $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authenticator = new WebhookAuthenticator();
    }

    public function testItVerifiesGiteeTokens()
    {
        $this->assertTrue($this->authenticator->verifyGiteeToken('shared-secret', 'shared-secret'));
        $this->assertFalse($this->authenticator->verifyGiteeToken('wrong-secret', 'shared-secret'));
        $this->assertFalse($this->authenticator->verifyGiteeToken('', 'shared-secret'));
        $this->assertFalse($this->authenticator->verifyGiteeToken('shared-secret', ''));
    }

    public function testItVerifiesGitHubBodySignatures()
    {
        $body = '{"ref":"refs/heads/main"}';
        $secret = 'github-webhook-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue($this->authenticator->verifyGithubSignature($signature, $body, $secret));
    }

    public function testItRejectsChangedGitHubBodies()
    {
        $body = '{"ref":"refs/heads/main"}';
        $secret = 'github-webhook-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertFalse($this->authenticator->verifyGithubSignature($signature, $body . ' ', $secret));
    }

    public function testItRejectsMalformedGitHubSignatures()
    {
        $this->assertFalse($this->authenticator->verifyGithubSignature('sha1=abcdef', '{}', 'secret'));
        $this->assertFalse($this->authenticator->verifyGithubSignature('sha256=abcd', '{}', 'secret'));
        $this->assertFalse($this->authenticator->verifyGithubSignature('', '{}', 'secret'));
        $this->assertFalse($this->authenticator->verifyGithubSignature('sha256=' . str_repeat('a', 64), '{}', ''));
    }
}

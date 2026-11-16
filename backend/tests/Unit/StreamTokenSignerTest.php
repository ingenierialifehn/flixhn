<?php

namespace Tests\Unit;

use App\Services\Ott\Security\StreamTokenSigner;
use PHPUnit\Framework\TestCase;

class StreamTokenSignerTest extends TestCase
{
    public function test_generate_and_verify_token(): void
    {
        $tokenData = StreamTokenSigner::generateToken('content_123', 3600);
        $this->assertNotEmpty($tokenData['token']);
        $this->assertGreaterThan(time(), $tokenData['expires_at']);

        $verification = StreamTokenSigner::verifyToken($tokenData['token'], 'content_123');
        $this->assertTrue($verification['valid']);
        $this->assertNull($verification['error']);
        $this->assertEquals('content_123', $verification['payload']['cid']);
    }

    public function test_tampered_token_is_rejected(): void
    {
        $tokenData = StreamTokenSigner::generateToken('content_123', 3600);
        $tampered = $tokenData['token'] . 'bad';

        $verification = StreamTokenSigner::verifyToken($tampered, 'content_123');
        $this->assertFalse($verification['valid']);
        $this->assertStringContainsString('Firma digital', $verification['error']);
    }

    public function test_content_mismatch_is_rejected(): void
    {
        $tokenData = StreamTokenSigner::generateToken('content_123', 3600);

        $verification = StreamTokenSigner::verifyToken($tokenData['token'], 'other_content');
        $this->assertFalse($verification['valid']);
        $this->assertStringContainsString('Token no corresponde', $verification['error']);
    }

    public function test_sign_url_attaches_parameter(): void
    {
        $url = 'https://cdn.flixhn.com/hls/master.m3u8';
        $signed = StreamTokenSigner::signUrl($url, 'hls_45', 3600);

        $this->assertStringContainsString('ott_token=', $signed);
        $this->assertStringStartsWith('https://cdn.flixhn.com/hls/master.m3u8?ott_token=', $signed);
    }
}

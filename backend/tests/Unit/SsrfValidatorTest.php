<?php

namespace Tests\Unit;

use App\Services\Ott\Security\SsrfValidator;
use PHPUnit\Framework\TestCase;

class SsrfValidatorTest extends TestCase
{
    public function test_blocks_loopback_and_private_ips(): void
    {
        $res = SsrfValidator::validate('http://127.0.0.1/admin', false);
        $this->assertFalse($res['safe']);
        $this->assertNotEmpty($res['reason']);

        $resCloud = SsrfValidator::validate('http://169.254.169.254/latest/meta-data', false);
        $this->assertFalse($resCloud['safe']);

        $resPrivateA = SsrfValidator::validate('http://10.0.0.5:8080/manifest.m3u8', false);
        $this->assertFalse($resPrivateA['safe']);

        $resPrivateC = SsrfValidator::validate('http://192.168.1.100/stream', false);
        $this->assertFalse($resPrivateC['safe']);
    }

    public function test_blocks_dangerous_schemes(): void
    {
        $resFile = SsrfValidator::validate('file:///etc/passwd');
        $this->assertFalse($resFile['safe']);
        $this->assertStringContainsString('Esquema no permitido', $resFile['reason']);

        $resFtp = SsrfValidator::validate('ftp://cdn.example.com/media.mp4');
        $this->assertFalse($resFtp['safe']);
    }

    public function test_allows_public_https_domains(): void
    {
        $res = SsrfValidator::validate('https://api.netflix.com/v1');
        $this->assertTrue($res['safe']);
        $this->assertNull($res['reason']);
    }

    public function test_is_private_ip_detection(): void
    {
        $this->assertTrue(SsrfValidator::isPrivateIp('127.0.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateIp('10.254.1.1'));
        $this->assertTrue(SsrfValidator::isPrivateIp('172.16.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateIp('192.168.0.1'));
        $this->assertTrue(SsrfValidator::isPrivateIp('169.254.1.1'));
        $this->assertTrue(SsrfValidator::isPrivateIp('::1'));
        $this->assertFalse(SsrfValidator::isPrivateIp('8.8.8.8'));
        $this->assertFalse(SsrfValidator::isPrivateIp('1.1.1.1'));
    }
}

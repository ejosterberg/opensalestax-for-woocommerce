<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use OpenSalesTax\WooCommerce\UrlValidator;
use PHPUnit\Framework\TestCase;
use WP_Mock;

final class UrlValidatorTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
        // Default: private networks NOT allowed (strict mode).
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_allow_private_nets', '0'],
            'return' => '0',
        ]);
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testEmptyUrlIsBadFormat(): void
    {
        $r = UrlValidator::validate('');
        self::assertSame(UrlValidator::BAD_FORMAT, $r['code']);
    }

    public function testUrlMissingSchemeIsBadFormat(): void
    {
        $r = UrlValidator::validate('engine.example.com:8080');
        self::assertSame(UrlValidator::BAD_FORMAT, $r['code']);
    }

    public function testFtpSchemeRejected(): void
    {
        $r = UrlValidator::validate('ftp://engine.example.com');
        self::assertSame(UrlValidator::BAD_SCHEME, $r['code']);
    }

    public function testFileSchemeRejected(): void
    {
        $r = UrlValidator::validate('file:///etc/passwd');
        self::assertSame(UrlValidator::BAD_SCHEME, $r['code']);
    }

    public function testLoopbackIpRejected(): void
    {
        $r = UrlValidator::validate('http://127.0.0.1:8080');
        self::assertSame(UrlValidator::PRIVATE_HOST_BLOCKED, $r['code']);
        self::assertStringContainsString('127.0.0.1', $r['message']);
    }

    public function testRfc1918TenSlash8Rejected(): void
    {
        $r = UrlValidator::validate('http://10.32.161.126:8080');
        self::assertSame(UrlValidator::PRIVATE_HOST_BLOCKED, $r['code']);
    }

    public function testRfc1918OneNinetyTwoOneSixtyEightRejected(): void
    {
        $r = UrlValidator::validate('http://192.168.1.50:8080');
        self::assertSame(UrlValidator::PRIVATE_HOST_BLOCKED, $r['code']);
    }

    public function testRfc1918OneSeventyTwoSixteenRejected(): void
    {
        $r = UrlValidator::validate('http://172.16.5.10:8080');
        self::assertSame(UrlValidator::PRIVATE_HOST_BLOCKED, $r['code']);
    }

    public function testLinkLocalRejected(): void
    {
        $r = UrlValidator::validate('http://169.254.169.254/');
        self::assertSame(UrlValidator::PRIVATE_HOST_BLOCKED, $r['code']);
    }

    public function testCarrierGradeNatRejected(): void
    {
        $r = UrlValidator::validate('http://100.64.10.20/');
        self::assertSame(UrlValidator::PRIVATE_HOST_BLOCKED, $r['code']);
    }

    public function testPublicIpAccepted(): void
    {
        $r = UrlValidator::validate('http://8.8.8.8/');
        self::assertSame(UrlValidator::OK, $r['code']);
    }

    public function testPublicHostnameAccepted(): void
    {
        // Accepts even if DNS isn't reachable from the test runner — but if DNS
        // resolves, it must resolve to a public IP. example.com resolves to
        // 23.x or similar (public).
        $r = UrlValidator::validate('http://example.com');
        // Either OK (DNS resolved + public) or HOST_UNRESOLVABLE (no DNS in the
        // test runner). Both are acceptable from a security standpoint — the
        // private-IP check did its job either way.
        self::assertContains($r['code'], [UrlValidator::OK, UrlValidator::HOST_UNRESOLVABLE]);
    }

    public function testPrivateHostAcceptedWhenOptedIn(): void
    {
        // Re-stub get_option to return "1" — admin opted in
        WP_Mock::tearDown();
        WP_Mock::setUp();
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_allow_private_nets', '0'],
            'return' => '1',
        ]);

        $r = UrlValidator::validate('http://10.32.161.126:8080');
        self::assertSame(UrlValidator::OK, $r['code']);
    }

    public function testIsPrivateRangeIpForLoopback(): void
    {
        self::assertTrue(UrlValidator::isPrivateRangeIp('127.0.0.1'));
    }

    public function testIsPrivateRangeIpForRfc1918(): void
    {
        self::assertTrue(UrlValidator::isPrivateRangeIp('10.0.0.1'));
        self::assertTrue(UrlValidator::isPrivateRangeIp('192.168.1.1'));
        self::assertTrue(UrlValidator::isPrivateRangeIp('172.16.0.1'));
    }

    public function testIsPrivateRangeIpForPublic(): void
    {
        self::assertFalse(UrlValidator::isPrivateRangeIp('8.8.8.8'));
        self::assertFalse(UrlValidator::isPrivateRangeIp('1.1.1.1'));
    }

    public function testIsPrivateRangeIpForCgnat(): void
    {
        self::assertTrue(UrlValidator::isPrivateRangeIp('100.64.0.1'));
        self::assertTrue(UrlValidator::isPrivateRangeIp('100.127.255.255'));
        self::assertFalse(UrlValidator::isPrivateRangeIp('100.128.0.0'));   // outside CGNAT
    }
}

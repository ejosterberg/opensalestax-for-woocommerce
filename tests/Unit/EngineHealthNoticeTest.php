<?php

// SPDX-License-Identifier: Apache-2.0 OR GPL-2.0-or-later

declare(strict_types=1);

namespace OpenSalesTax\WooCommerce\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use OpenSalesTax\Client as OpenSalesTaxClient;
use OpenSalesTax\Responses\HealthResponse;
use OpenSalesTax\WooCommerce\ClientFactory;
use OpenSalesTax\WooCommerce\EngineHealthNotice;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as Psr18Client;
use WP_Mock;

final class EngineHealthNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
    }

    public function testRendersNothingWithoutCapability(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => false,
        ]);

        $notice = new EngineHealthNotice($this->factoryThatShouldNotBeCalled());
        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();
        self::assertSame('', $output);
    }

    public function testRendersNothingWhenNotConfigured(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => '',
        ]);

        $notice = new EngineHealthNotice($this->factoryThatShouldNotBeCalled());
        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();
        self::assertSame('', $output);
    }

    public function testRendersNothingWhenCachedHealthIsHealthy(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        $healthy = new HealthResponse(status: 'ok', version: '0.39.0', databaseConnected: true);
        WP_Mock::userFunction('get_transient', [
            'args' => [EngineHealthNotice::HEALTH_CACHE_KEY],
            'return' => $healthy,
        ]);
        WP_Mock::userFunction('delete_transient', [
            'args' => [EngineHealthNotice::FAILURE_MARKER],
            'return' => true,
        ]);

        $notice = new EngineHealthNotice($this->factoryThatShouldNotBeCalled());
        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();
        self::assertSame('', $output);
    }

    public function testRendersErrorBannerWhenFailureMarkerSet(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [EngineHealthNotice::HEALTH_CACHE_KEY],
            'return' => false,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [EngineHealthNotice::FAILURE_MARKER],
            'return' => '1',
        ]);
        WP_Mock::userFunction('admin_url', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_url', ['return_arg' => 0]);

        $notice = new EngineHealthNotice($this->factoryThatShouldNotBeCalled());
        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('engine is unreachable', $output);
        self::assertStringContainsString('notice notice-error', $output);
        self::assertStringContainsString('Open settings', $output);
    }

    public function testProbesAndRecordsFailureWhenNoCache(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [EngineHealthNotice::HEALTH_CACHE_KEY],
            'return' => false,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [EngineHealthNotice::FAILURE_MARKER],
            'return' => false,
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 1,
            'return' => true,
        ]);
        WP_Mock::userFunction('admin_url', ['return_arg' => 0]);
        WP_Mock::userFunction('esc_url', ['return_arg' => 0]);

        $notice = new EngineHealthNotice($this->factoryReturningHttpStatus(500));
        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('engine is unreachable', $output);
    }

    public function testProbesAndPopulatesCacheOnSuccess(): void
    {
        WP_Mock::userFunction('current_user_can', [
            'args' => ['manage_woocommerce'],
            'return' => true,
        ]);
        WP_Mock::userFunction('get_option', [
            'args' => ['opensalestax_base_url', ''],
            'return' => 'http://10.32.161.126:8080',
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [EngineHealthNotice::HEALTH_CACHE_KEY],
            'return' => false,
        ]);
        WP_Mock::userFunction('get_transient', [
            'args' => [EngineHealthNotice::FAILURE_MARKER],
            'return' => false,
        ]);
        WP_Mock::userFunction('set_transient', [
            'times' => 1,
            'return' => true,
        ]);
        WP_Mock::userFunction('delete_transient', [
            'args' => [EngineHealthNotice::FAILURE_MARKER],
            'return' => true,
        ]);

        $notice = new EngineHealthNotice($this->factoryReturning([
            'status' => 'ok',
            'version' => '0.39.0',
            'database_connected' => true,
        ]));
        ob_start();
        $notice->render();
        $output = (string) ob_get_clean();

        // Healthy probe → no banner.
        self::assertSame('', $output);
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    private function factoryReturning(array $responseBody): ClientFactory
    {
        $http = $this->createMock(Psr18Client::class);
        $http->method('sendRequest')->willReturn(
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($responseBody, JSON_THROW_ON_ERROR)),
        );
        $client = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);
        return new class ($client) extends ClientFactory {
            public function __construct(private readonly OpenSalesTaxClient $client)
            {
            }
            public function build(): ?OpenSalesTaxClient
            {
                return $this->client;
            }
        };
    }

    private function factoryReturningHttpStatus(int $status): ClientFactory
    {
        $http = $this->createMock(Psr18Client::class);
        $http->method('sendRequest')->willReturn(
            new Response($status, ['Content-Type' => 'text/plain'], 'engine error'),
        );
        $client = new OpenSalesTaxClient(baseUrl: 'http://mock', httpClient: $http);
        return new class ($client) extends ClientFactory {
            public function __construct(private readonly OpenSalesTaxClient $client)
            {
            }
            public function build(): ?OpenSalesTaxClient
            {
                return $this->client;
            }
        };
    }

    private function factoryThatShouldNotBeCalled(): ClientFactory
    {
        return new class () extends ClientFactory {
            public function build(): ?OpenSalesTaxClient
            {
                throw new \RuntimeException('Factory should not have been called for this test');
            }
        };
    }
}

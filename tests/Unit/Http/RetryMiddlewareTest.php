<?php

declare(strict_types=1);

namespace GtelPhp\Vault\Tests\Unit\Http;

use GtelPhp\Vault\Http\RetryMiddleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RetryMiddlewareTest extends TestCase
{
    public function test_decider_retries_on_5xx_response(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 3);
        $decider = $middleware->decider();

        $request = new Request('GET', 'https://vault.example.com/v1/secret/data/x');
        $response = new Response(500);

        self::assertTrue($decider(0, $request, $response));
    }

    public function test_decider_retries_on_429_response(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 3);
        $decider = $middleware->decider();

        $request = new Request('GET', 'https://vault.example.com/v1/secret/data/x');
        $response = new Response(429);

        self::assertTrue($decider(0, $request, $response));
    }

    public function test_decider_does_not_retry_on_4xx_other_than_429(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 3);
        $decider = $middleware->decider();

        $request = new Request('GET', 'https://vault.example.com/v1/secret/data/x');
        $response = new Response(403);

        self::assertFalse($decider(0, $request, $response));
    }

    public function test_decider_retries_on_connection_exception(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 3);
        $decider = $middleware->decider();

        $request = new Request('GET', 'https://vault.example.com/v1/secret/data/x');

        self::assertTrue($decider(0, $request, null, new RuntimeException('connection refused')));
    }

    public function test_decider_stops_after_max_retries(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 2);
        $decider = $middleware->decider();

        $request = new Request('GET', 'https://vault.example.com/v1/secret/data/x');
        $response = new Response(500);

        self::assertFalse($decider(2, $request, $response));
    }

    public function test_decider_does_not_retry_successful_response(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 3);
        $decider = $middleware->decider();

        $request = new Request('GET', 'https://vault.example.com/v1/secret/data/x');
        $response = new Response(200);

        self::assertFalse($decider(0, $request, $response));
    }

    public function test_delay_grows_exponentially(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 5, baseDelayMs: 100);
        $delay = $middleware->delay();

        $first = $delay(0, null);
        $second = $delay(1, null);
        $third = $delay(2, null);

        // Allow for the +/-20% jitter around the exponential base value.
        self::assertGreaterThanOrEqual(80, $first);
        self::assertLessThanOrEqual(120, $first);

        self::assertGreaterThanOrEqual(160, $second);
        self::assertLessThanOrEqual(240, $second);

        self::assertGreaterThanOrEqual(320, $third);
        self::assertLessThanOrEqual(480, $third);
    }

    public function test_delay_honours_retry_after_header(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 3);
        $delay = $middleware->delay();

        $response = new Response(429, ['Retry-After' => '5']);

        self::assertSame(5000, $delay(0, $response));
    }

    public function test_delay_is_capped_at_ten_seconds(): void
    {
        $middleware = new RetryMiddleware(maxRetries: 10, baseDelayMs: 5000);
        $delay = $middleware->delay();

        self::assertLessThanOrEqual(10_000, $delay(5, null));
    }
}

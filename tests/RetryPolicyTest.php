<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Http\RetryPolicy;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    public function testRetriesRateLimitsAndUnavailableForEveryMethod(): void
    {
        $policy = new RetryPolicy(3);
        $post = new Request('POST', '/issues.json');
        self::assertTrue($policy->shouldRetry(0, $post, new Response(429), null));
        self::assertTrue($policy->shouldRetry(2, $post, new Response(503), null));
        self::assertFalse($policy->shouldRetry(3, $post, new Response(429), null));
        self::assertFalse($policy->shouldRetry(0, $post, new Response(500), null));
        self::assertFalse($policy->shouldRetry(0, $post, new Response(422), null));
    }

    public function testConnectionFailuresAreRetriedForReadsOnly(): void
    {
        $policy = new RetryPolicy();
        $get = new Request('GET', '/x');
        $error = new ConnectException('down', $get);
        self::assertTrue($policy->shouldRetry(0, $get, null, $error));
        self::assertFalse($policy->shouldRetry(0, new Request('POST', '/x'), null, new ConnectException('down', $get)));
    }

    public function testDelayHonoursRetryAfterAndBacksOff(): void
    {
        $now = new DateTimeImmutable('2026-10-13 12:00:00 UTC');
        $policy = new RetryPolicy(5, 60000, static fn () => $now);
        self::assertSame(1000, $policy->delayMs(1, null));
        self::assertSame(4000, $policy->delayMs(3, new Response(429)));
        self::assertSame(7000, $policy->delayMs(1, new Response(429, ['Retry-After' => '7'])));
        self::assertSame(30000, $policy->delayMs(1, new Response(503, ['Retry-After' => 'Tue, 13 Oct 2026 12:00:30 GMT'])));
        self::assertSame(60000, $policy->delayMs(1, new Response(429, ['Retry-After' => '3600'])));
    }

    public function testClientRetriesA429AndSucceeds(): void
    {
        $mock = new MockHandler([new Response(429, ['Retry-After' => '0']), new Response(200, [], '{"ok":true}')]);
        $stack = HandlerStack::create($mock);
        $stack->push((new RetryPolicy(5, 0))->middleware());
        $client = new Client(['handler' => $stack]);
        self::assertSame('{"ok":true}', (string)$client->get('/x')->getBody());
        self::assertSame(0, $mock->count());
    }

    public function testFactoriesInstallTheRetryUnlessSwitchedOff(): void
    {
        $client = ClientFactory::redmine(['base_url' => 'https://example.org', 'api_key' => 'k']);
        self::assertStringContainsString("'retry'", (string)$client->getConfig('handler'));
        $client = ClientFactory::redmine(['base_url' => 'https://example.org', 'api_key' => 'k', 'retry' => ['max_retries' => 0]]);
        self::assertStringNotContainsString("'retry'", (string)$client->getConfig('handler'));
        $client = ClientFactory::jira(['base_url' => 'https://e.atlassian.net', 'username' => 'u', 'api_token' => 't']);
        self::assertStringContainsString("'retry'", (string)$client->getConfig('handler'));
    }
}

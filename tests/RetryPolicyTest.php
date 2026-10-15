<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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

    public function testATransferCutOffIsRetriedForReadsOnly(): void
    {
        $policy = new RetryPolicy();
        $get = new Request('GET', '/rest/api/3/users/search');
        $cut = new RequestException('cURL error 18: transfer closed with outstanding read data remaining', $get);
        self::assertTrue($policy->shouldRetry(0, $get, null, $cut));
        $post = new Request('POST', '/issues.json');
        self::assertFalse($policy->shouldRetry(0, $post, null, new RequestException('cURL error 18', $post)));
    }

    public function testClientRetriesACutOffReadButNotAnHttpError(): void
    {
        $get = new Request('GET', '/x');
        $mock = new MockHandler([new RequestException('cURL error 18', $get), new Response(200, [], '{"ok":true}')]);
        $stack = HandlerStack::create($mock);
        $stack->push((new RetryPolicy(5, 0))->middleware());
        self::assertSame('{"ok":true}', (string)(new Client(['handler' => $stack]))->get('/x')->getBody());

        $mock = new MockHandler([new Response(404), new Response(200)]);
        $stack = HandlerStack::create($mock);
        $stack->push((new RetryPolicy(5, 0))->middleware());
        try {
            (new Client(['handler' => $stack]))->get('/x');
            self::fail('a 404 must not be retried');
        } catch (ClientException $exception) {
            self::assertSame(404, $exception->getResponse()->getStatusCode());
        }
        self::assertSame(1, $mock->count());
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

    public function testAGatewayTimeoutIsRetriedForReadsOnly(): void
    {
        // B71: the Atlassian gateway answered 504 to GET issue/{id}/watchers and the 14-jira phase stopped
        $policy = new RetryPolicy(3);
        self::assertTrue($policy->shouldRetry(0, new Request('GET', '/rest/api/3/issue/1/watchers'), new Response(504), null));
        self::assertTrue($policy->shouldRetry(1, new Request('GET', '/x'), new Response(502), null));
        self::assertFalse($policy->shouldRetry(3, new Request('GET', '/x'), new Response(504), null));
        // a write may have reached the server behind the gateway: not sent again
        self::assertFalse($policy->shouldRetry(0, new Request('POST', '/issues.json'), new Response(504), null));
        self::assertFalse($policy->shouldRetry(0, new Request('PUT', '/issues/1.json'), new Response(502), null));
    }
}

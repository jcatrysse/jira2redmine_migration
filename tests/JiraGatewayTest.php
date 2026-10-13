<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\TestCase;

final class JiraGatewayTest extends TestCase
{
    private const SITE = 'https://example.atlassian.net';
    private const CLOUD_ID = '11111111-2222-3333-4444-555555555555';
    private const GATEWAY = 'https://api.atlassian.com/ex/jira/11111111-2222-3333-4444-555555555555';

    public function testNoCloudIdMeansNoGateway(): void
    {
        self::assertNull(jiraGatewayPrefix(['base_url' => self::SITE]));
        self::assertNull(jiraGatewayPrefix(['base_url' => self::SITE, 'cloud_id' => '  ']));
    }

    public function testCloudIdGivesGatewayPrefix(): void
    {
        self::assertSame(self::GATEWAY, jiraGatewayPrefix(['cloud_id' => self::CLOUD_ID]));
    }

    public function testLeadingSlashPathOnGatewayHostGetsPrefixBack(): void
    {
        $uri = new Uri('https://api.atlassian.com/rest/api/3/search/jql?jql=project%3DABC');
        $rewritten = jiraGatewayRewriteUri($uri, self::GATEWAY, self::SITE);
        self::assertSame(self::GATEWAY . '/rest/api/3/search/jql?jql=project%3DABC', (string)$rewritten);
    }

    public function testPrefixedPathIsLeftAlone(): void
    {
        $uri = new Uri(self::GATEWAY . '/rest/api/3/myself');
        self::assertSame((string)$uri, (string)jiraGatewayRewriteUri($uri, self::GATEWAY, self::SITE));
    }

    public function testAbsoluteSiteUrlMovesToGateway(): void
    {
        $uri = new Uri(self::SITE . '/rest/api/3/attachment/content/10001');
        $rewritten = jiraGatewayRewriteUri($uri, self::GATEWAY, self::SITE);
        self::assertSame(self::GATEWAY . '/rest/api/3/attachment/content/10001', (string)$rewritten);
    }

    public function testOtherHostsAreLeftAlone(): void
    {
        $uri = new Uri('https://api.media.atlassian.com/file/abc/binary?token=x');
        self::assertSame((string)$uri, (string)jiraGatewayRewriteUri($uri, self::GATEWAY, self::SITE));
    }

    /**
     * The scripts build their client with the site as base_uri and call paths with a leading slash.
     * Without the handler those requests go to the site; with a cloud id they must reach the gateway.
     */
    public function testClientAsBuiltByTheScriptsReachesTheGateway(): void
    {
        $seen = [];
        $config = ['base_url' => self::SITE, 'cloud_id' => self::CLOUD_ID];
        $stack = jiraHandlerStack($config);
        $stack->setHandler(new MockHandler([new Response(200), new Response(200)]));
        // Pushed after the gateway middleware, so it runs inside it and sees the rewritten request.
        $stack->push(static function (callable $next) use (&$seen) {
            return static function ($request, array $options) use ($next, &$seen) {
                $seen[] = [(string)$request->getUri(), $request->getHeaderLine('Host')];

                return $next($request, $options);
            };
        });

        $client = new Client(['base_uri' => self::SITE, 'handler' => $stack]);
        $client->get('/rest/api/3/project/search');
        $client->get(self::SITE . '/rest/api/3/attachment/content/10001');

        self::assertSame([
            [self::GATEWAY . '/rest/api/3/project/search', 'api.atlassian.com'],
            [self::GATEWAY . '/rest/api/3/attachment/content/10001', 'api.atlassian.com'],
        ], $seen);
    }

    public function testHandlerWithoutCloudIdHasNoGatewayMiddleware(): void
    {
        $stack = jiraHandlerStack(['base_url' => self::SITE]);
        self::assertStringNotContainsString('jira_gateway', (string)$stack);
        $stack = jiraHandlerStack(['base_url' => self::SITE, 'cloud_id' => self::CLOUD_ID]);
        self::assertStringContainsString('jira_gateway', (string)$stack);
    }
}

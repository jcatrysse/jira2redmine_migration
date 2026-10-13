<?php

declare(strict_types=1);

use Jira2Redmine\Http\ClientFactory;
use PHPUnit\Framework\TestCase;

final class ClientFactoryTest extends TestCase
{
    public function testRedmineClientKeepsASubPathAndSendsTheKey(): void
    {
        $client = ClientFactory::redmine(['base_url' => 'https://example.org/redmine', 'api_key' => 'k'], 30.0);
        self::assertSame('https://example.org/redmine/', (string)$client->getConfig('base_uri'));
        self::assertSame('k', $client->getConfig('headers')['X-Redmine-API-Key']);
        self::assertSame(30.0, $client->getConfig('timeout'));
    }

    public function testNoTimeoutUnlessGiven(): void
    {
        $client = ClientFactory::redmine(['base_url' => 'https://example.org', 'api_key' => 'k']);
        self::assertNull($client->getConfig('timeout'));
    }

    public function testJiraClientUsesTheGatewayHandlerWhenACloudIdIsSet(): void
    {
        $client = ClientFactory::jira([
            'base_url' => 'https://example.atlassian.net', 'username' => 'u', 'api_token' => 't', 'cloud_id' => 'abc',
        ]);
        self::assertSame(['u', 't'], $client->getConfig('auth'));
        self::assertStringContainsString('jira_gateway', (string)$client->getConfig('handler'));
    }

    public function testIncompleteConfigurationIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        ClientFactory::jira(['base_url' => 'https://example.atlassian.net', 'username' => 'u']);
    }
}

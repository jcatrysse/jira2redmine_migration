<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jira2Redmine\Redmine\ExtendedApi;
use PHPUnit\Framework\TestCase;

final class ExtendedApiTest extends TestCase
{
    public function testItIsUsedWhenTheFlagOrTheConfigSaysSo(): void
    {
        self::assertTrue(ExtendedApi::enabled([], true));
        self::assertTrue(ExtendedApi::enabled(['extended_api' => ['enabled' => true]], false));
        self::assertFalse(ExtendedApi::enabled(['extended_api' => ['enabled' => 0]], false));
        self::assertFalse(ExtendedApi::enabled(['extended_api' => 'yes'], false));
        self::assertFalse(ExtendedApi::enabled([], false));
    }

    public function testPathsStayRelativeToTheRedmineBaseUrl(): void
    {
        // a leading slash would drop a sub-path like https://host/redmine/ (F6)
        self::assertSame('extended_api', ExtendedApi::prefix([]));
        self::assertSame('extended_api', ExtendedApi::prefix(['extended_api' => ['prefix' => '  ']]));
        self::assertSame('custom/api', ExtendedApi::prefix(['extended_api' => ['prefix' => '/custom/api/']]));
        self::assertSame('extended_api/roles.json', ExtendedApi::path('extended_api', '/roles.json'));
        self::assertSame('roles.json', ExtendedApi::path('/', 'roles.json'));
    }

    public function testARedmineUnderASubPathKeepsIt(): void
    {
        // 08 built "/extended_api/..." (an absolute path): Guzzle then drops the base URL's path, so a Redmine at
        // https://host/redmine/ got https://host/extended_api/... (F6); it now uses this class like 04 to 12
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, ['X-Redmine-Extended-API' => '1'])]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $client = new Client(['handler' => $stack, 'base_uri' => 'https://host.example/redmine/']);

        ExtendedApi::verifyAvailable($client, ExtendedApi::prefix(['extended_api' => ['prefix' => '/extended_api']]), 'custom_fields.json');

        self::assertSame('https://host.example/redmine/extended_api/custom_fields.json', (string)$history[0]['request']->getUri());
        $script = (string)file_get_contents(__DIR__ . '/../08_migrate_custom_fields.php');
        self::assertStringNotContainsString('function buildExtendedApiPath(', $script);
        self::assertStringContainsString('ExtendedApi::path($extendedApiPrefix', $script);
    }

    public function testTheAvailabilityCheckNeedsThePluginsHeader(): void
    {
        $client = self::client(new Response(200, ['X-Redmine-Extended-API' => '1']));
        ExtendedApi::verifyAvailable($client, 'extended_api', 'roles.json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extended API response missing X-Redmine-Extended-API header');
        ExtendedApi::verifyAvailable(self::client(new Response(200)), 'extended_api', 'roles.json');
    }

    public function testAnHttpErrorNamesThePath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Extended API availability check failed (extended_api/roles.json): HTTP 404 Not Found');

        ExtendedApi::verifyAvailable(self::client(new Response(404)), 'extended_api', 'roles.json');
    }

    public function testTheErrorSummaryCarriesRedminesMessages(): void
    {
        $request = new Request('POST', 'extended_api/roles.json');
        $body = '{"errors":["Name has already been taken","Name is too long"]}';
        $errors = new ClientException('x', $request, new Response(422, [], $body));
        $single = new ClientException('x', $request, new Response(403, [], '{"error":"Forbidden"}'));
        $plain = new ClientException('x', $request, new Response(500, [], 'Oops'));

        $expected = 'HTTP 422 Unprocessable Entity: Name has already been taken; Name is too long';
        self::assertSame($expected, ExtendedApi::summarizeError($errors));
        self::assertSame('HTTP 403 Forbidden: Forbidden', ExtendedApi::summarizeError($single));
        self::assertSame('HTTP 500 Internal Server Error: Oops', ExtendedApi::summarizeError($plain));
        self::assertSame('no response', ExtendedApi::summarizeError(new RuntimeException('no response')));
        self::assertNull(ExtendedApi::errorDetails(new Response(422, [], '  ')));
    }

    private static function client(Response $response): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
    }
}

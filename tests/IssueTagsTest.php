<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Jira2Redmine\Redmine\IssueTags;
use PHPUnit\Framework\TestCase;

final class IssueTagsTest extends TestCase
{
    /** @var \ArrayObject<int, mixed> */
    private \ArrayObject $history;

    private function client(Response ...$responses): Client
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $history = new \ArrayObject();
        $stack->push(Middleware::history($history));
        self::assertInstanceOf(\ArrayObject::class, $history);
        $this->history = $history;

        return new Client(['base_uri' => 'http://redmine.test/', 'handler' => $stack]);
    }

    /** @return list<string> */
    private function calls(): array
    {
        $calls = [];
        foreach ($this->history as $entry) {
            $request = is_array($entry) ? $entry['request'] : null;
            self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
            $calls[] = $request->getMethod() . ' ' . $request->getUri()->getPath();
        }

        return $calls;
    }

    private function body(int $index): string
    {
        $entry = $this->history[$index];
        $request = is_array($entry) ? $entry['request'] : null;
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);

        return (string)$request->getBody();
    }

    public function testUsesTheTagsRouteOn51(): void
    {
        $client = $this->client(new Response(201), new Response(201));
        $endpoint = IssueTags::push($client, 5, ['a', 'b'], null);
        $endpoint = IssueTags::push($client, 6, ['c'], $endpoint);
        self::assertSame(IssueTags::ENDPOINT_TAGS, $endpoint);
        self::assertSame(['POST /issues/5/tags.json', 'POST /issues/6/tags.json'], $this->calls());
        self::assertSame('{"tags":["a","b"]}', $this->body(0));
    }

    public function testFallsBackToTheIssueUpdateOn70AndStaysThere(): void
    {
        $client = $this->client(new Response(404), new Response(204), new Response(204));
        $endpoint = IssueTags::push($client, 5, ['a', 'b'], null);
        $endpoint = IssueTags::push($client, 6, ['c'], $endpoint);
        self::assertSame(IssueTags::ENDPOINT_ISSUE, $endpoint);
        self::assertSame(['POST /issues/5/tags.json', 'PUT /issues/5.json', 'PUT /issues/6.json'], $this->calls());
        self::assertSame('{"issue":{"add_tag_list":"a,b"}}', $this->body(1));
    }

    public function testTheIssueUpdateGoesThroughTheExtendedApiWithoutMail(): void
    {
        // Jan (2026-10-15): a plain issue update mails the watchers and fires Redmine 7's webhooks; the 5.1 route stays
        $client = $this->client(new Response(404), new Response(204));
        IssueTags::push($client, 5, ['a', 'b'], null, 'extended_api');
        self::assertSame(['POST /issues/5/tags.json', 'PUT /extended_api/issues/5.json'], $this->calls());
        $entry = $this->history[1];
        $request = is_array($entry) ? $entry['request'] : null;
        self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
        self::assertSame('notify=false&send_notification=0', $request->getUri()->getQuery());
        self::assertSame('{"issue":{"add_tag_list":"a,b"}}', $this->body(1));
    }

    public function testA404AfterTheRouteWorkedIsAnError(): void
    {
        $client = $this->client(new Response(404));
        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        IssueTags::push($client, 9, ['a'], IssueTags::ENDPOINT_TAGS);
    }
}

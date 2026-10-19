<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Jira2Redmine\History\HistoryPush;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * Decision 41: the history step's requests to the Extended API, and what its answer must hold.
 */
final class HistoryPushTest extends TestCase
{
    /** @var array<int, mixed>|\ArrayAccess<int, mixed> */
    private array|\ArrayAccess $history = [];

    private function client(Response ...$responses): Client
    {
        $stack = HandlerStack::create(new MockHandler(array_values($responses)));
        $history = new \ArrayObject();
        $stack->push(Middleware::history($history));
        $this->history = $history;

        return new Client(['base_uri' => 'http://redmine.test/', 'handler' => $stack]);
    }

    private function request(int $index): RequestInterface
    {
        $entry = $this->history[$index];
        $request = is_array($entry) ? $entry['request'] : null;
        self::assertInstanceOf(RequestInterface::class, $request);

        return $request;
    }

    public function testTheFileGoesUpWithTheAuthorAndTimeOfItsLastTransition(): void
    {
        $client = $this->client(new Response(201, [], '{"upload":{"id":4,"token":"4.abc"}}'));
        $token = HistoryPush::upload($client, '/extended_api', "{}\n", ['author_id' => 31, 'created_on' => '2024-01-09T17:30:00+00:00']);
        self::assertSame('4.abc', $token);
        $request = $this->request(0);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/extended_api/uploads.json', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame(
            ['filename' => 'jira-history.json', 'attachment' => ['author_id' => '31', 'created_on' => '2024-01-09T17:30:00+00:00']],
            $query
        );
        self::assertSame("{}\n", (string)$request->getBody());
    }

    public function testOneRequestPerIssueGivesTheJournalIdsInOrder(): void
    {
        $client = $this->client(new Response(201, [], '{"journals":[{"id":51},{"id":52}],"attachments":[{"id":9}]}'));
        $body = ['journals' => [
            ['user_id' => 1, 'created_on' => '2024-01-01T08:00:00+00:00', 'details' => []],
            ['user_id' => 1, 'created_on' => '2024-01-02T08:00:00+00:00', 'details' => []],
        ]];
        self::assertSame(['journal_ids' => [51, 52], 'attachment_id' => 9], HistoryPush::send($client, 'extended_api', 42, $body));
        self::assertSame('/extended_api/issues/42/history.json', $this->request(0)->getUri()->getPath());
        self::assertSame($body, json_decode((string)$this->request(0)->getBody(), true));
    }

    public function testAnAnswerWithoutAnIdPerJournalIsAnError(): void
    {
        $client = $this->client(new Response(201, [], '{"journals":[{"id":51}]}'));
        $this->expectException(\RuntimeException::class);
        HistoryPush::send($client, 'extended_api', 42, ['journals' => [
            ['user_id' => 1, 'created_on' => '2024-01-01T08:00:00+00:00', 'details' => []],
            ['user_id' => 1, 'created_on' => '2024-01-02T08:00:00+00:00', 'details' => []],
        ]]);
    }

    public function testARefusalStaysAnError(): void
    {
        $client = $this->client(new Response(422, [], '{"errors":["Prop key is invalid"]}'));
        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        HistoryPush::send($client, 'extended_api', 42, ['journals' => []]);
    }
}

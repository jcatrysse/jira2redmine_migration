<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Jira2Redmine\Redmine\ChecklistItem;
use PHPUnit\Framework\TestCase;

/**
 * B80: a checklist item longer than redmine_checklists allows (512 on 5.1) was refused, and 15 stopped the issue's
 * checklist there: the items after it were lost too.
 */
final class ChecklistItemTest extends TestCase
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

    /** @return list<array{subject: string, is_done: bool}> */
    private function sent(): array
    {
        $sent = [];
        self::assertInstanceOf(\ArrayObject::class, $this->history);
        foreach ($this->history as $entry) {
            $request = is_array($entry) ? $entry['request'] : null;
            self::assertInstanceOf(\Psr\Http\Message\RequestInterface::class, $request);
            self::assertSame('POST /issues/7/checklists.json', $request->getMethod() . ' ' . $request->getUri()->getPath());
            $sent[] = json_decode((string)$request->getBody(), true)['checklist'];
        }

        return $sent;
    }

    public function testAnItemThatFitsIsPostedOnce(): void
    {
        self::assertFalse(ChecklistItem::post($this->client(new Response(201)), 7, 'Check the cable', true));
        self::assertSame([['subject' => 'Check the cable', 'is_done' => true]], $this->sent());
    }

    public function testATooLongItemIsPostedAgainCutToTheMaximumRedmineNames(): void
    {
        $long = str_repeat('word ', 104);
        $refused = new Response(422, [], '{"errors":["Subject is too long (maximum is 512 characters)"]}');
        self::assertTrue(ChecklistItem::post($this->client($refused, new Response(201)), 7, $long, false));
        $sent = $this->sent();
        self::assertCount(2, $sent);
        self::assertSame($long, $sent[0]['subject']);
        self::assertLessThanOrEqual(512, mb_strlen($sent[1]['subject']));
        self::assertStringEndsWith('…', $sent[1]['subject']);
        self::assertStringStartsWith('word word', $sent[1]['subject']);
    }

    public function testTheMaximumIsReadInAnyLanguage(): void
    {
        self::assertSame(512, ChecklistItem::maximumFrom('{"errors":["Onderwerp is te lang (maximaal 512 tekens)"]}', 520));
        self::assertNull(ChecklistItem::maximumFrom('{"errors":["Subject cannot be blank"]}', 520));
        // a number that is not below the length is no maximum for this subject
        self::assertNull(ChecklistItem::maximumFrom('{"errors":["Subject is too long (maximum is 512 characters)"]}', 40));
    }

    public function testAnotherRefusalStaysAnError(): void
    {
        $this->expectException(\GuzzleHttp\Exception\ClientException::class);
        ChecklistItem::post($this->client(new Response(422, [], '{"errors":["Subject cannot be blank"]}')), 7, '', false);
    }

    public function testThe15UsesItAndGoesOnAfterAShortenedItem(): void
    {
        $fifteen = (string)file_get_contents(__DIR__ . '/../15_migrate_checklists.php');
        self::assertStringContainsString('ChecklistItem::post($client, $redmineIssueId,', $fifteen);
        self::assertStringNotContainsString("\$client->post(sprintf('/issues/%d/checklists.json'", $fifteen);
    }

    public function testARerunOf15TriesAFailedChecklistAgain(): void
    {
        // B83: the transform kept every row whose checklist had not changed, a FAILED one too, so a rerun never retried
        $fifteen = (string)file_get_contents(__DIR__ . '/../15_migrate_checklists.php');
        $keep = "if (\$storedHash !== null && hash_equals(\$storedHash, \$automationHash) && \$current['migration_status'] !== 'FAILED') {";
        self::assertStringContainsString($keep, $fifteen);
        self::assertStringContainsString('automation_hash, migration_status FROM migration_mapping_checklists', $fifteen);
    }
}

<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Jira2Redmine\Http\JiraTape;
use PHPUnit\Framework\TestCase;

/**
 * The Jira tape of the phase 6 proof: a recorded run's answers come back in the same order in a later run, without
 * the network, and a request the tape does not hold fails.
 */
final class JiraTapeTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/j2r-tape-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        putenv('J2R_JIRA_TAPE');
        putenv('J2R_JIRA_TAPE_MODE');
        foreach ([$this->directory . '/played', $this->directory] as $directory) {
            foreach (glob($directory . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    /**
     * @param list<Response|Throwable> $answers
     */
    private function client(string $mode, array $answers = []): Client
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push((new JiraTape($this->directory, $mode))->middleware(), 'jira_tape');

        return new Client(['base_uri' => 'https://jira.example/', 'handler' => $stack]);
    }

    public function testARecordedRunIsPlayedBackInOrderWithoutTheNetwork(): void
    {
        $recorder = $this->client(JiraTape::RECORD, [
            new Response(200, ['Content-Type' => 'application/json'], '{"n":1}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"n":2}'),
            new Response(200, [], '{"page":"b"}'),
        ]);
        self::assertSame('{"n":1}', (string)$recorder->get('rest/api/3/myself')->getBody());
        self::assertSame('{"n":2}', (string)$recorder->get('rest/api/3/myself')->getBody());
        self::assertSame('{"page":"b"}', (string)$recorder->post('rest/api/3/search/jql', ['json' => ['token' => 'b']])->getBody());

        $player = $this->client(JiraTape::REPLAY);
        $first = $player->get('rest/api/3/myself');
        self::assertSame('{"n":1}', (string)$first->getBody());
        self::assertSame('application/json', $first->getHeaderLine('Content-Type'));
        self::assertSame('{"n":2}', (string)$player->get('rest/api/3/myself')->getBody());
        self::assertSame('{"page":"b"}', (string)$player->post('rest/api/3/search/jql', ['json' => ['token' => 'b']])->getBody());
    }

    public function testARequestTheTapeDoesNotHoldFails(): void
    {
        $this->client(JiraTape::RECORD, [new Response(200, [], 'a')])->post('rest/api/3/search/jql', ['json' => ['token' => 'a']]);
        $player = $this->client(JiraTape::REPLAY);

        try {
            $player->post('rest/api/3/search/jql', ['json' => ['token' => 'other']]);
            self::fail('a request with another body was played');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('Not on the Jira tape: POST https://jira.example/rest/api/3/search/jql', $exception->getMessage());
        }
        $player->post('rest/api/3/search/jql', ['json' => ['token' => 'a']]);
        $this->expectExceptionMessage('Not on the Jira tape (1 times): POST');
        $player->post('rest/api/3/search/jql', ['json' => ['token' => 'a']]);
    }

    public function testErrorAnswersAndTransportFailuresComeBackAsTheyWere(): void
    {
        $recorder = $this->client(JiraTape::RECORD, [
            new Response(404, [], '{"errorMessages":["gone"]}'),
            new ConnectException('cURL error 7', new Request('GET', 'rest/api/3/issue/1')),
        ]);
        try {
            $recorder->get('rest/api/3/issue/2');
        } catch (ClientException) {
        }
        try {
            $recorder->get('rest/api/3/issue/1');
        } catch (ConnectException) {
        }

        $player = $this->client(JiraTape::REPLAY);
        try {
            $player->get('rest/api/3/issue/2');
            self::fail('the 404 was not played');
        } catch (ClientException $exception) {
            self::assertSame(404, $exception->getResponse()->getStatusCode());
            self::assertSame('{"errorMessages":["gone"]}', (string)$exception->getResponse()->getBody());
        }
        $this->expectException(ConnectException::class);
        $this->expectExceptionMessage('cURL error 7');
        $player->get('rest/api/3/issue/1');
    }

    public function testADownloadIntoASinkIsWrittenThereOnPlayback(): void
    {
        $this->client(JiraTape::RECORD, [new Response(200, [], "\x00binary")])->get('rest/api/3/attachment/content/7');
        $sink = $this->directory . '/sink.bin';
        $response = $this->client(JiraTape::REPLAY)->get('rest/api/3/attachment/content/7', ['sink' => $sink]);

        self::assertSame("\x00binary", (string)file_get_contents($sink));
        self::assertSame("\x00binary", (string)$response->getBody());
    }

    public function testAHeaderThatIsNotUtf8IsRecordedAndPlayedBackAsItWas(): void
    {
        // B90: 30 attachment downloads of the test projects carry such a header; the tape could not store them, so the
        // download failed in the record run and in every replay of it
        $disposition = "attachment; filename=\"caf\xe9 r\xe9sum\xe9.pdf\"";
        $recorder = $this->client(JiraTape::RECORD, [new Response(200, ['Content-Disposition' => $disposition], "\x00pdf")]);
        self::assertSame("\x00pdf", (string)$recorder->get('rest/api/3/attachment/content/8')->getBody());

        $played = $this->client(JiraTape::REPLAY)->get('rest/api/3/attachment/content/8');
        self::assertSame($disposition, $played->getHeaderLine('Content-Disposition'));
        self::assertSame("\x00pdf", (string)$played->getBody());
        self::assertFileDoesNotExist($this->directory . '/' . JiraTape::FAILED);
    }

    public function testATapeThatCannotPlayAnAnswerMarksItselfFailed(): void
    {
        // B90: a tape entry left empty played as "Syntax error", which the script logged as a failed download and went
        // on; the run must stop instead (run_all.sh checks the mark after every step)
        $this->client(JiraTape::RECORD, [new Response(200, [], 'a')])->get('rest/api/3/issue/1');
        foreach (glob($this->directory . '/*.json') ?: [] as $entry) {
            file_put_contents($entry, '');
        }

        try {
            $this->client(JiraTape::REPLAY)->get('rest/api/3/issue/1');
            self::fail('an empty tape entry was played');
        } catch (Throwable $exception) {
            self::assertStringContainsString('Syntax error', $exception->getMessage());
        }
        self::assertStringContainsString(
            'GET https://jira.example/rest/api/3/issue/1',
            (string)file_get_contents($this->directory . '/' . JiraTape::FAILED)
        );

        $script = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertStringContainsString('[ -f "$J2R_JIRA_TAPE/FAILED" ]', $script);
        self::assertStringContainsString('rm -rf "$J2R_JIRA_TAPE/played" "$J2R_JIRA_TAPE/FAILED"', $script);
    }

    public function testARequestNotOnTheTapeMarksItFailedToo(): void
    {
        $this->client(JiraTape::RECORD, [new Response(200, [], 'a')])->get('rest/api/3/issue/1');
        try {
            $this->client(JiraTape::REPLAY)->get('rest/api/3/issue/2');
            self::fail('a request the tape does not hold was played');
        } catch (RuntimeException) {
        }
        self::assertStringContainsString(
            'Not on the Jira tape: GET https://jira.example/rest/api/3/issue/2',
            (string)file_get_contents($this->directory . '/' . JiraTape::FAILED)
        );
    }

    public function testWithoutTheVariableTheStackStaysAsItIs(): void
    {
        putenv('J2R_JIRA_TAPE');
        $stack = HandlerStack::create(new MockHandler());
        $before = (string)$stack;
        JiraTape::attach($stack);
        self::assertSame($before, (string)$stack);

        putenv('J2R_JIRA_TAPE=' . $this->directory);
        putenv('J2R_JIRA_TAPE_MODE=record');
        JiraTape::attach($stack);
        self::assertStringContainsString("Name: 'jira_tape'", (string)$stack);
        // the Jira client's own stack carries it too
        self::assertStringContainsString("Name: 'jira_tape'", (string)jiraHandlerStack(['base_url' => 'https://jira.example']));
    }

    public function testTheTestRunStartsATapeEmptyAndPlaysItFromTheStart(): void
    {
        $run = (string)file_get_contents(__DIR__ . '/../tools/testenv/run_all.sh');
        self::assertStringContainsString('[ -z "$(ls -A "$J2R_JIRA_TAPE" 2>/dev/null)" ] || {', $run);
        self::assertStringContainsString('rm -rf "$J2R_JIRA_TAPE/played"', $run);
    }

    public function testAnUnknownModeIsRefused(): void
    {
        $this->expectExceptionMessage('J2R_JIRA_TAPE_MODE must be record or replay, not "play".');
        new JiraTape($this->directory, 'play');
    }
}

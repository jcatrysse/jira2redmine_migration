<?php

declare(strict_types=1);

namespace Jira2Redmine\Http;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * Test tool for the phase 6 proof (no behaviour change): a run records every Jira answer on a tape, a later run plays
 * the tape back instead of calling Jira. The runs before and after a refactor then read the same Jira, also when Jira
 * changed in between, so their Redmine requests can be compared one by one (tools/testenv/run_all.sh, RECORD).
 *
 * Off unless J2R_JIRA_TAPE names a directory; J2R_JIRA_TAPE_MODE is record or replay. The tape holds Jira data: keep
 * it outside the repository. A request is known by its method, URL and body; a request sent again gets the answer of
 * its next recording. Playback refuses a request the tape does not hold, so a run that reads Jira differently fails.
 * The middleware sits innermost, below the redirects (each hop of an attachment download is on the tape) and above
 * the retry (a rate limit while recording is not on the tape).
 */
final class JiraTape
{
    public const RECORD = 'record';
    public const REPLAY = 'replay';

    public function __construct(private readonly string $directory, private readonly string $mode)
    {
        if (!in_array($mode, [self::RECORD, self::REPLAY], true)) {
            throw new RuntimeException(sprintf('J2R_JIRA_TAPE_MODE must be record or replay, not "%s".', $mode));
        }
    }

    /** Puts the tape on the Jira client's stack when J2R_JIRA_TAPE is set; without it the stack stays as it is. */
    public static function attach(HandlerStack $stack): void
    {
        $directory = getenv('J2R_JIRA_TAPE');
        if (!is_string($directory) || $directory === '') {
            return;
        }
        $mode = getenv('J2R_JIRA_TAPE_MODE');
        $tape = new self($directory, is_string($mode) && $mode !== '' ? $mode : self::REPLAY);
        $stack->push($tape->middleware(), 'jira_tape');
    }

    public function middleware(): callable
    {
        return function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
                $key = self::key($request);
                if ($this->mode === self::REPLAY) {
                    return $this->play($request, $key, $options);
                }

                /** @var PromiseInterface $promise */
                $promise = $handler($request, $options);

                return $promise->then(
                    fn (ResponseInterface $response): ResponseInterface => $this->record($key, $response),
                    function (mixed $reason) use ($key): PromiseInterface {
                        $this->store($key, ['error' => $reason instanceof Throwable ? $reason->getMessage() : 'rejected'], '');

                        return Create::rejectionFor($reason);
                    }
                );
            };
        };
    }

    /** Method, URL and a hash of the body: what makes two requests the same request. */
    public static function key(RequestInterface $request): string
    {
        $body = $request->getBody();
        $content = (string)$body;
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return sprintf('%s %s %s', $request->getMethod(), (string)$request->getUri(), hash('sha256', $content));
    }

    private function record(string $key, ResponseInterface $response): ResponseInterface
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
            $content = $body->getContents();
            $body->rewind();
        } else {
            // a streamed download is read once: the caller gets the same bytes from memory
            $content = $body->getContents();
            $response = $response->withBody(Utils::streamFor($content));
        }
        $this->store($key, ['status' => $response->getStatusCode(), 'headers' => $response->getHeaders()], $content);

        return $response;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function store(string $key, array $meta, string $content): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException(sprintf('Cannot create the Jira tape directory %s.', $this->directory));
        }
        $hash = sha1($key);
        // the next free number, claimed by creating its file (downloads run side by side)
        $n = 0;
        while (($handle = @fopen(sprintf('%s/%s.%d.json', $this->directory, $hash, $n), 'x')) === false) {
            $n++;
        }
        file_put_contents(sprintf('%s/%s.%d.body', $this->directory, $hash, $n), $content);
        fwrite($handle, (string)json_encode(['key' => $key] + $meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        fclose($handle);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function play(RequestInterface $request, string $key, array $options): PromiseInterface
    {
        $hash = sha1($key);
        $n = $this->nextPlay($hash);
        $file = sprintf('%s/%s.%d.json', $this->directory, $hash, $n);
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Not on the Jira tape%s: %s', $n > 0 ? sprintf(' (%d times)', $n) : '', $key));
        }
        $meta = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($meta) || ($meta['key'] ?? null) !== $key) {
            throw new RuntimeException(sprintf('The Jira tape entry %s does not belong to %s.', $file, $key));
        }
        if (isset($meta['error'])) {
            return Create::rejectionFor(new ConnectException((string)$meta['error'], $request));
        }
        $content = (string)file_get_contents(sprintf('%s/%s.%d.body', $this->directory, $hash, $n));
        self::sink($options['sink'] ?? null, $content);
        /** @var array<string, list<string>> $headers */
        $headers = is_array($meta['headers'] ?? null) ? $meta['headers'] : [];

        return Create::promiseFor(new Response((int)$meta['status'], $headers, $content));
    }

    /** Which recording of this request is next; the count is kept on disk, a run is many processes. */
    private function nextPlay(string $hash): int
    {
        $directory = $this->directory . '/played';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Cannot create %s.', $directory));
        }
        $handle = fopen($directory . '/' . $hash, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException(sprintf('Cannot count the plays in %s.', $directory));
        }
        $n = (int)stream_get_contents($handle);
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string)($n + 1));
        flock($handle, LOCK_UN);
        fclose($handle);

        return $n;
    }

    /** Guzzle's sink option, as the network handler honours it: the body goes to that file or stream too. */
    private static function sink(mixed $sink, string $content): void
    {
        if (is_string($sink)) {
            file_put_contents($sink, $content);
        } elseif (is_resource($sink)) {
            fwrite($sink, $content);
        } elseif ($sink instanceof StreamInterface) {
            $sink->write($content);
        }
    }
}

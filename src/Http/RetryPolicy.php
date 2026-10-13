<?php

declare(strict_types=1);

namespace Jira2Redmine\Http;

use DateTimeImmutable;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Central retry on rate limits (decision 5), for Jira and Redmine alike.
 *
 * Retried: 429 and 503 for every method (the server did not process the request), and a connection
 * failure for GET/HEAD only. The wait honours Retry-After (seconds or an HTTP date); without it the
 * wait doubles from 1 s. Waits are capped at $maxDelayMs.
 */
final class RetryPolicy
{
    public function __construct(
        private readonly int $maxRetries = 5,
        private readonly int $maxDelayMs = 60000,
        private readonly ?\Closure $now = null,
    ) {
    }

    public function shouldRetry(int $retries, RequestInterface $request, ?ResponseInterface $response, ?Throwable $error): bool
    {
        if ($retries >= $this->maxRetries) {
            return false;
        }
        if ($response !== null) {
            return in_array($response->getStatusCode(), [429, 503], true);
        }

        return $error instanceof ConnectException && in_array($request->getMethod(), ['GET', 'HEAD'], true);
    }

    /**
     * @param int $retries the number of retries so far, starting at 1 for the first retry
     */
    public function delayMs(int $retries, ?ResponseInterface $response): int
    {
        $delay = 1000 * (int)(2 ** max(0, $retries - 1));
        $header = $response?->getHeaderLine('Retry-After') ?? '';
        if ($header !== '') {
            if (ctype_digit($header)) {
                $delay = 1000 * (int)$header;
            } else {
                $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);
                if ($date !== false) {
                    $now = $this->now !== null ? ($this->now)() : new DateTimeImmutable();
                    $delay = (int)max(0, 1000 * ($date->getTimestamp() - $now->getTimestamp()));
                }
            }
        }

        return min($delay, $this->maxDelayMs);
    }

    public function middleware(): callable
    {
        return Middleware::retry(
            function (int $retries, RequestInterface $request, ?ResponseInterface $response = null, ?Throwable $error = null): bool {
                $retry = $this->shouldRetry($retries, $request, $response, $error);
                if ($retry) {
                    fwrite(STDERR, sprintf(
                        "[retry] %s %s: %s, retry %d\n",
                        $request->getMethod(),
                        $request->getUri()->getPath(),
                        $response !== null ? 'HTTP ' . $response->getStatusCode() : 'connection failed',
                        $retries + 1
                    ));
                }

                return $retry;
            },
            fn (int $retries, ?ResponseInterface $response = null): int => $this->delayMs($retries, $response)
        );
    }
}

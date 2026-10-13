<?php

/**
 * Jira Cloud scoped API tokens only work through the gateway https://api.atlassian.com/ex/jira/{cloudId}.
 * This file is the one place that turns the configured Jira site into the URL the scripts talk to.
 *
 * Config: jira.base_url is the site (https://example.atlassian.net, also used for links back to Jira);
 * jira.cloud_id switches the API calls to the gateway when it is set. The client keeps the site as
 * base_uri; the middleware below moves every request to the gateway.
 */

declare(strict_types=1);

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Uri;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

const JIRA_GATEWAY_BASE_URL = 'https://api.atlassian.com/ex/jira/';

/**
 * @param array<string, mixed> $jiraConfig
 */
function jiraGatewayPrefix(array $jiraConfig): ?string
{
    $cloudId = trim((string)($jiraConfig['cloud_id'] ?? ''));
    if ($cloudId === '') {
        return null;
    }

    return JIRA_GATEWAY_BASE_URL . rawurlencode($cloudId);
}

/**
 * Rewrites a resolved request URI so it goes through the gateway:
 * - a path with a leading slash ("/rest/api/3/...") resolves to the gateway host root and loses
 *   "/ex/jira/{cloudId}"; the prefix is put back;
 * - an absolute URL on the Jira site (attachment "content" links) moves to the gateway;
 * - a path that already carries "/ex/jira/{cloudId}" (a gateway link Jira returned, used as a path)
 *   keeps it once.
 * Other hosts (e.g. a redirect to Atlassian's media service) are left alone.
 */
function jiraGatewayRewriteUri(UriInterface $uri, string $gatewayPrefix, string $siteBaseUrl): UriInterface
{
    $gateway = new Uri($gatewayPrefix);
    $prefixPath = rtrim($gateway->getPath(), '/');
    $host = strtolower($uri->getHost());

    $siteHost = '';
    if ($siteBaseUrl !== '') {
        $siteHost = strtolower((new Uri($siteBaseUrl))->getHost());
    }

    if ($host === strtolower($gateway->getHost())) {
        $path = $uri->getPath();
        if ($path === $prefixPath || str_starts_with($path, $prefixPath . '/')) {
            return $uri;
        }

        return $uri->withPath($prefixPath . '/' . ltrim($path, '/'));
    }

    if ($siteHost !== '' && $host === $siteHost) {
        $path = $uri->getPath();
        if ($path !== $prefixPath && !str_starts_with($path, $prefixPath . '/')) {
            $path = $prefixPath . '/' . ltrim($path, '/');
        }

        return $uri
            ->withScheme($gateway->getScheme())
            ->withHost($gateway->getHost())
            ->withPort($gateway->getPort())
            ->withPath($path);
    }

    return $uri;
}

/**
 * Guzzle handler stack for the Jira client; adds the gateway rewrite when a cloud id is configured.
 *
 * @param array<string, mixed> $jiraConfig
 */
function jiraHandlerStack(array $jiraConfig): HandlerStack
{
    $stack = HandlerStack::create();
    $prefix = jiraGatewayPrefix($jiraConfig);
    if ($prefix === null) {
        return $stack;
    }

    $site = rtrim(trim((string)($jiraConfig['base_url'] ?? '')), '/');
    // unshift: the rewrite runs before http_errors, so error messages show the URL actually called.
    $stack->unshift(Middleware::mapRequest(
        static fn (RequestInterface $request): RequestInterface => $request->withUri(
            jiraGatewayRewriteUri($request->getUri(), $prefix, $site)
        )
    ), 'jira_gateway');

    return $stack;
}

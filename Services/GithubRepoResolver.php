<?php

namespace Modules\ModuleManager\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;

/**
 * Resolves a pasted GitHub URL into the same {owner, repo, ref, label}
 * shape the manual "Add a Repository" form already collects field-by-field
 * -- so an admin can paste a link instead of typing out all four values by
 * hand.
 *
 * Mirrors the split GithubRepoFetcher already established between
 * buildZipUrl() (pure, no network) and download() (the network call): here
 * that's parseUrl() (pure, no network) and fetchMetadata() (the network
 * call), with resolve() as the convenience method that chains the two.
 */
class GithubRepoResolver
{
    /**
     * Matches GitHub owner/user and repo name rules closely enough to reject
     * arbitrary strings without being a full spec implementation:
     *   - owner: alphanumeric or single hyphens, no leading/trailing hyphen.
     *   - repo: alphanumeric, hyphens, underscores, or dots.
     * GitHub also caps usernames at 39 characters; not enforced here since
     * an over-long segment will simply 404 against the real API anyway, and
     * enforcing it here would just be one more place that constant could
     * drift out of sync with GitHub's actual limit.
     */
    private const OWNER_PATTERN = '[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?';

    private const REPO_PATTERN = '[A-Za-z0-9_.-]+';

    private ClientInterface $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * Parses a pasted GitHub URL into ['owner', 'repo', 'ref'], with 'ref'
     * only set when the URL itself pinned one (a `/tree/{branch}` link).
     * Returns null for anything that doesn't look like a real
     * github.com repo URL. Pure -- no network access.
     *
     * Supported forms:
     *   - https://github.com/{owner}/{repo}
     *   - https://github.com/{owner}/{repo}/  (trailing slash)
     *   - https://github.com/{owner}/{repo}.git
     *   - https://github.com/{owner}/{repo}/tree/{branch}
     *   - git@github.com:{owner}/{repo}.git   (SSH form)
     *
     * @return array{owner: string, repo: string, ref: ?string}|null
     */
    public function parseUrl(string $url): ?array
    {
        $url = trim($url);

        $ownerPattern = self::OWNER_PATTERN;
        $repoPattern = self::REPO_PATTERN;

        // SSH form: git@github.com:{owner}/{repo}.git -- no scheme, no
        // /tree/{branch} support (that's an https-web-UI-only concept).
        $sshPattern = "~^git@github\\.com:({$ownerPattern})/({$repoPattern})\\.git$~";
        if (preg_match($sshPattern, $url, $matches)) {
            return [
                'owner' => $matches[1],
                'repo' => $this->stripDotGit($matches[2]),
                'ref' => null,
            ];
        }

        // https (or http) web form, with an optional /tree/{branch} suffix.
        $webPattern = "~^https?://(?:www\\.)?github\\.com/({$ownerPattern})/({$repoPattern})(?:/tree/([^/?#]+))?/?$~";
        if (preg_match($webPattern, $url, $matches)) {
            $ref = isset($matches[3]) && $matches[3] !== '' ? rawurldecode($matches[3]) : null;

            return [
                'owner' => $matches[1],
                'repo' => $this->stripDotGit($matches[2]),
                'ref' => $ref,
            ];
        }

        return null;
    }

    /**
     * GET https://api.github.com/repos/{owner}/{repo} and pulls out
     * ['default_branch', 'name']. Throws GithubDownloadException (reusing
     * the module's existing GitHub-failure exception type rather than
     * adding a new one) with a clear, specific message for every failure
     * mode: repo not found/private (404, can't tell which), rate-limited
     * (403 with rate-limit headers), any other non-2xx status, a transport
     * failure, or a response body that isn't valid JSON.
     *
     * @return array{default_branch: string, name: string}
     */
    public function fetchMetadata(string $owner, string $repo): array
    {
        $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);

        try {
            $response = $this->client->request('GET', $url, [
                'timeout' => 15,
                'http_errors' => false,
                // GitHub's REST API rejects any request with no User-Agent
                // header at all (returns 403), unlike most APIs that just
                // don't care. Verified against the real endpoint.
                'headers' => [
                    'User-Agent' => 'freescout-module-manager',
                    'Accept' => 'application/vnd.github+json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new GithubDownloadException(
                "Could not reach GitHub to look up {$owner}/{$repo}: {$e->getMessage()}"
            );
        }

        $status = $response->getStatusCode();

        if ($status === 404) {
            throw new GithubDownloadException(
                "GitHub has no repository at {$owner}/{$repo} that this request can see -- "
                . 'it may not exist, or it may be private. Add it manually instead.'
            );
        }

        if ($status === 403) {
            $remaining = $response->getHeaderLine('X-RateLimit-Remaining');

            if ($remaining === '0') {
                throw new GithubDownloadException(
                    "GitHub's API rate limit was hit while looking up {$owner}/{$repo}. "
                    . 'Wait a bit and try again, or add the repository manually instead.'
                );
            }

            throw new GithubDownloadException(
                "GitHub refused the request to look up {$owner}/{$repo} (HTTP 403). "
                . 'Add the repository manually instead.'
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new GithubDownloadException(
                "GitHub returned HTTP {$status} while looking up {$owner}/{$repo}."
            );
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new GithubDownloadException(
                "GitHub's response for {$owner}/{$repo} was not valid JSON."
            );
        }

        if (!isset($data['default_branch']) || !is_string($data['default_branch']) || $data['default_branch'] === '') {
            throw new GithubDownloadException(
                "GitHub's response for {$owner}/{$repo} did not include a default branch."
            );
        }

        $name = isset($data['name']) && is_string($data['name']) && $data['name'] !== '' ? $data['name'] : $repo;

        return [
            'default_branch' => $data['default_branch'],
            'name' => $name,
        ];
    }

    /**
     * Convenience method chaining parseUrl() and fetchMetadata(): parses
     * $url, looks up its metadata on GitHub, and returns the same
     * {owner, repo, ref, label} shape SavedRepoStore::add() expects.
     *
     * 'ref' prefers the URL's own /tree/{branch} hint (an admin pasting a
     * link to a specific branch clearly means that branch, not whatever
     * the repo's default happens to be) and falls back to the API's
     * default_branch otherwise. 'label' defaults to the API's repo name.
     *
     * @return array{owner: string, repo: string, ref: string, label: string}
     * @throws GithubDownloadException if $url doesn't parse, or the
     *     metadata lookup fails.
     */
    public function resolve(string $url): array
    {
        $parsed = $this->parseUrl($url);

        if ($parsed === null) {
            throw new GithubDownloadException(
                "Could not recognize \"{$url}\" as a GitHub repository URL. "
                . 'Add the repository manually instead.'
            );
        }

        $metadata = $this->fetchMetadata($parsed['owner'], $parsed['repo']);

        return [
            'owner' => $parsed['owner'],
            'repo' => $parsed['repo'],
            'ref' => $parsed['ref'] ?? $metadata['default_branch'],
            'label' => $metadata['name'],
        ];
    }

    private function stripDotGit(string $repo): string
    {
        return preg_match('#\.git$#', $repo) ? substr($repo, 0, -4) : $repo;
    }
}

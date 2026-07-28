<?php

namespace Modules\ModuleManager\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;
use Modules\ModuleManager\Services\Support\UpdateTarget;
use Psr\Http\Message\ResponseInterface;

/**
 * Finds the latest version of a saved repo available on GitHub, for the
 * "Check for Updates" / "Update" actions on the settings page.
 *
 * Two tracking modes, tried in order:
 *   1. Tagged release (preferred): GET /repos/{owner}/{repo}/releases/latest.
 *      GitHub 404s this endpoint specifically when a repo has never
 *      published a release (confirmed against the real API -- it does not
 *      return an empty array the way /releases does), which is exactly the
 *      "no releases" signal used to fall through to mode 2 below.
 *   2. Commit on a branch (fallback): prefers a branch literally named
 *      "stable" if the repo has one -- a maintainer who curates a branch by
 *      that name means it to be safer to install than whatever
 *      $fallbackRef happens to be (often "main"/"dev", which can carry
 *      in-progress work). Falls back to $fallbackRef itself otherwise.
 *
 * Mirrors GithubRepoResolver's request style: same base URL, same required
 * User-Agent header (GitHub's API 403s any request without one),
 * http_errors=false with manual status handling, and GithubDownloadException
 * reused for every failure mode.
 */
class UpdateChecker
{
    private ClientInterface $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    /**
     * @throws GithubDownloadException on any transport failure, rate limit,
     *     or other non-2xx/non-404 response from GitHub.
     */
    public function findLatest(string $owner, string $repo, string $fallbackRef): UpdateTarget
    {
        $latestRelease = $this->fetchLatestRelease($owner, $repo);

        if ($latestRelease !== null) {
            return new UpdateTarget(
                UpdateTarget::MODE_TAG,
                $latestRelease['tag_name'],
                $latestRelease['tag_name'],
                $latestRelease['html_url']
            );
        }

        $branch = $this->branchExists($owner, $repo, 'stable') ? 'stable' : $fallbackRef;
        $commit = $this->fetchLatestCommit($owner, $repo, $branch);

        return new UpdateTarget(
            UpdateTarget::MODE_COMMIT,
            $commit['sha'],
            'commit ' . substr($commit['sha'], 0, 7) . " on {$branch}",
            $commit['html_url']
        );
    }

    /**
     * Resolves $ref -- a branch, tag, or commit SHA, whatever the caller is
     * currently treating as "installed" -- to its current commit SHA.
     * Deliberately does not ask "is there anything newer" the way
     * findLatest() does: an install always downloads exactly $ref, so this
     * answers "what does $ref actually point at right now", independent of
     * whether a newer release exists elsewhere for a *different* ref. Used
     * to record what a plain install actually put on disk, rather than
     * conflating that with findLatest()'s answer and silently drifting the
     * saved repo onto a ref nobody asked to track.
     *
     * @throws GithubDownloadException on any transport failure or other
     *     non-2xx response from GitHub.
     */
    public function resolveCommit(string $owner, string $repo, string $ref): string
    {
        return $this->fetchLatestCommit($owner, $repo, $ref)['sha'];
    }

    /**
     * @return array{tag_name: string, html_url: ?string}|null null when the
     *     repo has never published a release.
     */
    private function fetchLatestRelease(string $owner, string $repo): ?array
    {
        $response = $this->request($this->apiUrl($owner, $repo, '/releases/latest'), $owner, $repo);

        if ($response->getStatusCode() === 404) {
            return null;
        }

        $this->assertSuccessStatus($response, $owner, $repo);
        $data = $this->decodeJson($response, $owner, $repo);

        if (!isset($data['tag_name']) || !is_string($data['tag_name']) || $data['tag_name'] === '') {
            throw new GithubDownloadException(
                "GitHub's latest-release response for {$owner}/{$repo} did not include a tag name."
            );
        }

        $htmlUrl = isset($data['html_url']) && is_string($data['html_url']) ? $data['html_url'] : null;

        return ['tag_name' => $data['tag_name'], 'html_url' => $htmlUrl];
    }

    private function branchExists(string $owner, string $repo, string $branch): bool
    {
        $response = $this->request($this->apiUrl($owner, $repo, '/branches/' . rawurlencode($branch)), $owner, $repo);

        if ($response->getStatusCode() === 404) {
            return false;
        }

        $this->assertSuccessStatus($response, $owner, $repo);

        return true;
    }

    /**
     * @return array{sha: string, html_url: ?string}
     */
    private function fetchLatestCommit(string $owner, string $repo, string $ref): array
    {
        $response = $this->request($this->apiUrl($owner, $repo, '/commits/' . rawurlencode($ref)), $owner, $repo);

        $this->assertSuccessStatus($response, $owner, $repo);
        $data = $this->decodeJson($response, $owner, $repo);

        if (!isset($data['sha']) || !is_string($data['sha']) || $data['sha'] === '') {
            throw new GithubDownloadException(
                "GitHub's commit lookup for {$owner}/{$repo}@{$ref} did not include a commit SHA."
            );
        }

        $htmlUrl = isset($data['html_url']) && is_string($data['html_url']) ? $data['html_url'] : null;

        return ['sha' => $data['sha'], 'html_url' => $htmlUrl];
    }

    private function apiUrl(string $owner, string $repo, string $suffix): string
    {
        return 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . $suffix;
    }

    private function request(string $url, string $owner, string $repo): ResponseInterface
    {
        try {
            return $this->client->request('GET', $url, [
                'timeout' => 15,
                'http_errors' => false,
                'headers' => [
                    'User-Agent' => 'freescout-module-manager',
                    'Accept' => 'application/vnd.github+json',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new GithubDownloadException(
                "Could not reach GitHub to check for updates to {$owner}/{$repo}: {$e->getMessage()}"
            );
        }
    }

    private function assertSuccessStatus(ResponseInterface $response, string $owner, string $repo): void
    {
        $status = $response->getStatusCode();

        if ($status === 403) {
            $remaining = $response->getHeaderLine('X-RateLimit-Remaining');

            if ($remaining === '0') {
                throw new GithubDownloadException(
                    "GitHub's API rate limit was hit while checking {$owner}/{$repo} for updates. Wait a bit and try again."
                );
            }

            throw new GithubDownloadException(
                "GitHub refused the request to check {$owner}/{$repo} for updates (HTTP 403)."
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new GithubDownloadException(
                "GitHub returned HTTP {$status} while checking {$owner}/{$repo} for updates."
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(ResponseInterface $response, string $owner, string $repo): array
    {
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            throw new GithubDownloadException(
                "GitHub's response while checking {$owner}/{$repo} for updates was not valid JSON."
            );
        }

        return $data;
    }
}

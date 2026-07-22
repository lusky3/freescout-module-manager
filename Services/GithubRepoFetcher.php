<?php

namespace Modules\ModuleManager\Services;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;
use Psr\Http\Message\ResponseInterface;

class GithubRepoFetcher
{
    /**
     * Maximum accepted size for a downloaded GitHub archive, in bytes (50MB).
     *
     * Matches the spirit of ModuleManagerController::MAX_UPLOAD_KB (also 50MB): a
     * repo archive fetched on the admin's behalf shouldn't be allowed to consume
     * more disk than a directly uploaded ZIP would.
     */
    private const MAX_DOWNLOAD_BYTES = 52428800; // 50 * 1024 * 1024

    private ClientInterface $client;

    public function __construct(ClientInterface $client)
    {
        $this->client = $client;
    }

    public function buildZipUrl(string $owner, string $repo, string $ref): string
    {
        $owner = rawurlencode(trim($owner, '/'));
        $repo = rawurlencode(trim($repo, '/'));
        $ref = rawurlencode(trim($ref, '/'));

        return "https://github.com/{$owner}/{$repo}/archive/{$ref}.zip";
    }

    public function download(string $owner, string $repo, string $ref, string $destinationPath): void
    {
        $url = $this->buildZipUrl($owner, $repo, $ref);

        try {
            $response = $this->client->request('GET', $url, [
                'sink' => $destinationPath,
                'timeout' => 30,
                'http_errors' => false,
                // Fires once headers are received, before the body streams to
                // 'sink'. Throwing here aborts the transfer early instead of
                // letting an oversized archive fill the disk. Guzzle catches
                // exceptions thrown from on_headers and surfaces them wrapped
                // in a RequestException, which is a GuzzleException and is
                // caught below just like any other transport failure.
                // Untyped/unchecked on purpose: Guzzle's MockHandler (used in
                // tests) invokes on_headers on whatever was queued, including
                // exception objects for simulated connection failures, before
                // it has been resolved into a real ResponseInterface. The real
                // CurlFactory-driven handler only ever calls on_headers once
                // actual response headers have arrived, so this guard is a
                // no-op in production and only matters for the test double.
                'on_headers' => function ($response) use ($owner, $repo, $ref) {
                    if (!$response instanceof ResponseInterface) {
                        return;
                    }

                    $contentLength = $response->getHeaderLine('Content-Length');

                    // Fail open when Content-Length is absent. Verified against
                    // GitHub directly: github.com/.../archive/{ref}.zip 302s to
                    // codeload.github.com, which streams the generated archive
                    // over HTTP/2 without ever sending a Content-Length header
                    // (the zip is built on the fly, so its final size isn't known
                    // upfront). Failing closed here would reject every real
                    // GitHub download, defeating the feature. This check is a
                    // best-effort guard for responses that do declare a length;
                    // it is not a substitute for verifying the resulting file on
                    // disk if a hard cap is needed on codeload's chunked path.
                    if ($contentLength === '') {
                        return;
                    }

                    if ((int) $contentLength > self::MAX_DOWNLOAD_BYTES) {
                        throw new GithubDownloadException(
                            "Refusing to download {$owner}/{$repo}@{$ref}: archive size ({$contentLength} bytes) exceeds the " . self::MAX_DOWNLOAD_BYTES . '-byte limit.'
                        );
                    }
                },
            ]);
        } catch (GuzzleException $e) {
            // If the on_headers callback above threw a GithubDownloadException
            // (the size cap was exceeded), Guzzle wraps it in a RequestException
            // with a generic message ("An error was encountered during the
            // on_headers event") and stashes our original exception as the
            // "previous" exception. Unwrap and rethrow it directly so callers
            // see the specific, clear message instead of Guzzle's wrapper text.
            $previous = $e->getPrevious();
            if ($previous instanceof GithubDownloadException) {
                throw $previous;
            }

            throw new GithubDownloadException(
                "Could not download {$owner}/{$repo}@{$ref}: {$e->getMessage()}"
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new GithubDownloadException(
                "GitHub returned HTTP {$status} for {$owner}/{$repo}@{$ref}. Check the owner, repo, and branch/tag name."
            );
        }
    }
}

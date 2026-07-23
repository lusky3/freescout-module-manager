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
        $owner = $this->sanitizeSegment($owner);
        $repo = $this->sanitizeSegment($repo);
        $ref = $this->sanitizeSegment($ref);

        return "https://github.com/{$owner}/{$repo}/archive/{$ref}.zip";
    }

    /**
     * Trims stray leading/trailing slashes and percent-encodes a single URL
     * path segment (owner, repo, or ref) for safe interpolation into the
     * GitHub archive URL above.
     */
    private function sanitizeSegment(string $value): string
    {
        return rawurlencode(trim($value, '/'));
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
                //
                // This is a best-effort fast-path only: see the 'progress'
                // callback below for the check that actually matters against
                // real GitHub traffic.
                'on_headers' => function ($response) use ($owner, $repo, $ref) {
                    if (!$response instanceof ResponseInterface) {
                        return;
                    }

                    $contentLength = $response->getHeaderLine('Content-Length');

                    // Fail open when Content-Length is absent. Verified against
                    // GitHub directly: github.com/.../archive/{ref}.zip 302s to
                    // codeload.github.com, which streams the generated archive
                    // over chunked transfer without ever sending a
                    // Content-Length header (the zip is built on the fly, so
                    // its final size isn't known upfront). Failing closed here
                    // would reject every real GitHub download, defeating the
                    // feature. This is why this check alone is not sufficient —
                    // see the 'progress' callback below, which is what actually
                    // bounds codeload downloads.
                    if ($contentLength === '') {
                        return;
                    }

                    if ((int) $contentLength > self::MAX_DOWNLOAD_BYTES) {
                        throw new GithubDownloadException(
                            "Refusing to download {$owner}/{$repo}@{$ref}: archive size ({$contentLength} bytes) exceeds the " . self::MAX_DOWNLOAD_BYTES . '-byte limit.'
                        );
                    }
                },
                // The authoritative cap. codeload.github.com — what
                // github.com/.../archive/{ref}.zip actually resolves to —
                // streams generated archives with no Content-Length header at
                // all (confirmed against the real endpoint), which makes the
                // on_headers check above a no-op on the one code path that
                // matters: $downloadTotal here will likewise be 0/unknown for
                // that same reason. So this callback ignores $downloadTotal
                // entirely and instead tracks $downloadedBytes — the actual
                // number of bytes received so far, updated as the transfer
                // streams in — aborting the moment it crosses the cap
                // regardless of what (if anything) the server claimed about
                // the total size. Guzzle propagates an exception thrown here
                // the same way it does for on_headers: wrapped in a
                // RequestException, caught below, and unwrapped back to this
                // specific exception.
                'progress' => function ($downloadTotal, $downloadedBytes) use ($owner, $repo, $ref) {
                    if ($downloadedBytes > self::MAX_DOWNLOAD_BYTES) {
                        throw new GithubDownloadException(
                            "Refusing to download {$owner}/{$repo}@{$ref}: downloaded {$downloadedBytes} bytes, which exceeds the " . self::MAX_DOWNLOAD_BYTES . '-byte limit.'
                        );
                    }
                },
            ]);
        } catch (GuzzleException $e) {
            // If the on_headers or progress callback above threw a
            // GithubDownloadException (the size cap was exceeded), Guzzle
            // normally wraps it in a RequestException with a generic
            // message ("An error was encountered during the
            // on_headers/progress event") and stashes our original
            // exception as the "previous" exception -- one level deep.
            //
            // But the depth isn't guaranteed: which underlying transport
            // Guzzle picks depends on what's available in the PHP install
            // (CurlHandler when the curl extension is loaded, StreamHandler
            // otherwise), and StreamHandler's progress notifications fire
            // from inside GuzzleHttp\Psr7\Stream::read(), which itself
            // catches any \Exception escaping the notification (our
            // GithubDownloadException is one) and re-wraps it as a plain
            // RuntimeException('Unable to read from stream', 0, $e) before
            // Guzzle's own RequestException wraps THAT -- two levels deep.
            // Walk the full previous-exception chain rather than checking
            // only one level, so callers see our specific, clear message
            // regardless of which transport handled the request.
            $previous = $e;
            while ($previous !== null) {
                if ($previous instanceof GithubDownloadException) {
                    throw $previous;
                }

                $previous = $previous->getPrevious();
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

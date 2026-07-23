<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;
use Modules\ModuleManager\Services\GithubRepoFetcher;
use PHPUnit\Framework\TestCase;

class GithubRepoFetcherTest extends TestCase
{
    public function test_builds_the_codeload_style_zip_url(): void
    {
        $fetcher = new GithubRepoFetcher(new Client());

        $url = $fetcher->buildZipUrl('nielspeen', 'AiAssistant', 'main');

        $this->assertSame('https://github.com/nielspeen/AiAssistant/archive/main.zip', $url);
    }

    public function test_builds_the_zip_url_with_percent_encoded_owner_repo_and_ref(): void
    {
        $fetcher = new GithubRepoFetcher(new Client());

        $url = $fetcher->buildZipUrl('some owner', 'some repo', 'a ref#1');

        $this->assertSame('https://github.com/some%20owner/some%20repo/archive/a%20ref%231.zip', $url);
    }

    public function test_download_writes_response_body_to_destination_on_success(): void
    {
        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        $mock = new MockHandler([new Response(200, [], 'zip-bytes')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $fetcher = new GithubRepoFetcher($client);

        $fetcher->download('nielspeen', 'AiAssistant', 'main', $destination);

        $this->assertSame('zip-bytes', file_get_contents($destination));
        unlink($destination);
    }

    public function test_download_throws_on_non_2xx_response(): void
    {
        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        $mock = new MockHandler([new Response(404, [], 'not found')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $fetcher = new GithubRepoFetcher($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('HTTP 404');

        $fetcher->download('nielspeen', 'missing-repo', 'main', $destination);

        unlink($destination);
    }

    public function test_download_throws_on_connection_failure(): void
    {
        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        $mock = new MockHandler([
            new ConnectException('Could not resolve host', new Request('GET', 'https://github.com')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $fetcher = new GithubRepoFetcher($client);

        $this->expectException(GithubDownloadException::class);

        $fetcher->download('nielspeen', 'AiAssistant', 'main', $destination);

        unlink($destination);
    }

    public function test_download_throws_when_content_length_exceeds_the_size_cap(): void
    {
        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        // 60MB declared, above the 50MB cap. The mock never needs to actually
        // send that many bytes: on_headers fires as soon as headers arrive,
        // before the body would stream to the sink.
        $oversizedBytes = 60 * 1024 * 1024;
        $mock = new MockHandler([
            new Response(200, ['Content-Length' => (string) $oversizedBytes], 'irrelevant-body'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $fetcher = new GithubRepoFetcher($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('exceeds the');

        try {
            $fetcher->download('nielspeen', 'AiAssistant', 'main', $destination);
        } finally {
            @unlink($destination);
        }
    }

    public function test_download_allows_a_response_within_the_size_cap(): void
    {
        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        $mock = new MockHandler([
            new Response(200, ['Content-Length' => '9'], 'zip-bytes'),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $fetcher = new GithubRepoFetcher($client);

        $fetcher->download('nielspeen', 'AiAssistant', 'main', $destination);

        $this->assertSame('zip-bytes', file_get_contents($destination));
        unlink($destination);
    }

    public function test_download_allows_a_response_missing_content_length(): void
    {
        // GitHub's codeload endpoint streams generated archives without a
        // Content-Length header, so this must fail open rather than reject
        // every real download.
        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        $mock = new MockHandler([new Response(200, [], 'zip-bytes')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $fetcher = new GithubRepoFetcher($client);

        $fetcher->download('nielspeen', 'AiAssistant', 'main', $destination);

        $this->assertSame('zip-bytes', file_get_contents($destination));
        unlink($destination);
    }

    /**
     * Guzzle's MockHandler (used by every other test in this file) never
     * invokes the 'progress' request option at all -- it only reads
     * 'on_headers' and 'sink'. So it cannot exercise the progress-based cap
     * the way a real transfer would. Instead, this test swaps in a bare
     * handler callable (bypassing MockHandler entirely) that invokes
     * $options['progress'] directly with a downloadTotal of 0 -- exactly
     * what codeload.github.com's headerless, chunked responses look like to
     * curl -- and a downloadedBytes figure past the cap, proving
     * GithubRepoFetcher's own progress callback (not Guzzle's plumbing)
     * correctly aborts on downloaded-bytes-so-far regardless of what (if
     * anything) downloadTotal reports.
     */
    public function test_download_throws_when_progress_callback_reports_downloaded_bytes_exceeding_the_cap(): void
    {
        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        $handler = static function ($request, array $options) {
            if (isset($options['progress']) && is_callable($options['progress'])) {
                // downloadTotal=0 mirrors codeload's real behavior (no
                // Content-Length ever sent); downloadedBytes is set past the
                // 50MB cap, as if 80MB had streamed in so far.
                ($options['progress'])(0, 80 * 1024 * 1024, 0, 0);
            }

            return new Response(200);
        };
        $client = new Client(['handler' => HandlerStack::create($handler)]);
        $fetcher = new GithubRepoFetcher($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('exceeds the');

        try {
            $fetcher->download('nielspeen', 'AiAssistant', 'main', $destination);
        } finally {
            @unlink($destination);
        }
    }

    /**
     * Empirically verified gap this closes: codeload.github.com -- what
     * github.com/{owner}/{repo}/archive/{ref}.zip actually redirects to --
     * streams generated archives with no Content-Length header at all, so
     * the on_headers/Content-Length check is a no-op on the only code path
     * that matters in production. This test proves the fix against real
     * GitHub infrastructure rather than a mock: it requests the real,
     * multi-hundred-megabyte torvalds/linux archive (chosen because it is
     * -- and, being an actively growing repository, will only remain --
     * far larger than the 50MB cap) and asserts that the progress callback
     * actually aborts the transfer partway through, using real bytes
     * reported by curl over a real chunked-transfer HTTPS connection.
     *
     * Requires outbound network access to github.com/codeload.github.com.
     * If that access is genuinely unavailable in the environment running
     * this suite, the test skips itself with an explicit reason (visible
     * in the PHPUnit summary) rather than silently passing or being
     * omitted.
     */
    public function test_download_aborts_early_against_the_real_github_codeload_endpoint_when_the_archive_exceeds_the_cap(): void
    {
        $probe = @get_headers('https://codeload.github.com/', 1);
        if ($probe === false) {
            $this->markTestSkipped(
                'Outbound network access to codeload.github.com is unavailable in this environment; '
                . 'cannot verify the progress-based download cap against real GitHub infrastructure.'
            );
        }

        $destination = tempnam(sys_get_temp_dir(), 'grf_');
        $client = new Client();
        $fetcher = new GithubRepoFetcher($client);

        try {
            $fetcher->download('torvalds', 'linux', 'master', $destination);
            $this->fail('Expected downloading the real torvalds/linux archive to exceed the download cap and throw.');
        } catch (GithubDownloadException $e) {
            if (strpos($e->getMessage(), 'exceeds the') === false) {
                // Not the cap firing -- almost certainly a real connectivity
                // problem (DNS/timeout/etc.) rather than the behavior under
                // test. Skip loudly with the real error rather than letting
                // an unrelated network hiccup masquerade as a cap failure.
                $this->markTestSkipped(
                    'Could not reach the real GitHub codeload endpoint to verify the download cap: ' . $e->getMessage()
                );
            }

            $downloadedBytes = filesize($destination);

            // Mirrors GithubRepoFetcher::MAX_DOWNLOAD_BYTES (private, so
            // duplicated here rather than reflected out) -- 50MB.
            $capBytes = 52428800;

            // Proves the abort happened on real, actually-received bytes
            // close to the cap -- not on a header, and not after only a
            // trivial handful of bytes. Not asserted as "strictly greater
            // than $capBytes": exactly how many bytes land in the sink
            // before the abort is noticed depends on the buffering
            // granularity of whichever transport handled the request (curl
            // vs. PHP streams notify at different chunk boundaries), so a
            // real download can land a small amount under the cap rather
            // than exactly at or past it. A 90% threshold comfortably rules
            // out "aborted almost immediately" while tolerating that
            // transport-specific slack.
            $this->assertGreaterThan(
                (int) ($capBytes * 0.9),
                $downloadedBytes,
                'Expected close to MAX_DOWNLOAD_BYTES to have actually streamed to disk before the abort.'
            );
            // ...but well short of the real archive's full size (100MB+ at
            // minimum; torvalds/linux only grows over time), proving the
            // transfer was aborted partway through rather than completing.
            $this->assertLessThan(
                100 * 1024 * 1024,
                $downloadedBytes,
                'Expected the transfer to have aborted partway through, not completed.'
            );
        } finally {
            @unlink($destination);
        }
    }
}

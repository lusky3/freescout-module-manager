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
}

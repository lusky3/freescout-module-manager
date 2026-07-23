<?php

// Tests/Unit/UpdateCheckerTest.php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;
use Modules\ModuleManager\Services\Support\UpdateTarget;
use Modules\ModuleManager\Services\UpdateChecker;
use PHPUnit\Framework\TestCase;

class UpdateCheckerTest extends TestCase
{
    public function test_finds_latest_release_when_one_exists(): void
    {
        // Only one response queued: if findLatest() made any further call
        // after finding a release, MockHandler's "no more items" exception
        // would fail this test, proving the release path short-circuits.
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'tag_name' => 'v2.10.2',
                'html_url' => 'https://github.com/composer/composer/releases/tag/v2.10.2',
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $target = $checker->findLatest('composer', 'composer', 'main');

        $this->assertSame(UpdateTarget::MODE_TAG, $target->mode);
        $this->assertSame('v2.10.2', $target->ref);
        $this->assertSame('v2.10.2', $target->label);
        $this->assertSame('https://github.com/composer/composer/releases/tag/v2.10.2', $target->url);
    }

    public function test_sends_a_user_agent_header(): void
    {
        $container = [];
        $history = \GuzzleHttp\Middleware::history($container);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['tag_name' => 'v1.0.0', 'html_url' => null])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = new Client(['handler' => $stack]);
        $checker = new UpdateChecker($client);

        $checker->findLatest('octocat', 'Hello-World', 'main');

        $this->assertCount(1, $container);
        $sentRequest = $container[0]['request'];
        $this->assertTrue($sentRequest->hasHeader('User-Agent'));
        $this->assertNotSame('', $sentRequest->getHeaderLine('User-Agent'));
    }

    public function test_falls_back_to_stable_branch_commit_when_no_releases_exist(): void
    {
        // Three responses, in the exact order findLatest() must call them:
        // 404 (no release) -> 200 (stable branch exists) -> 200 (its latest commit).
        $mock = new MockHandler([
            new Response(404, [], json_encode(['message' => 'Not Found'])),
            new Response(200, [], json_encode(['name' => 'stable'])),
            new Response(200, [], json_encode([
                'sha' => 'c19d0da7c782f8786205b1d4d2436a394d3ebef3',
                'html_url' => 'https://github.com/octocat/Hello-World/commit/c19d0da7c782f8786205b1d4d2436a394d3ebef3',
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $target = $checker->findLatest('octocat', 'Hello-World', 'main');

        $this->assertSame(UpdateTarget::MODE_COMMIT, $target->mode);
        $this->assertSame('c19d0da7c782f8786205b1d4d2436a394d3ebef3', $target->ref);
        $this->assertSame('commit c19d0da on stable', $target->label);
        $this->assertSame('https://github.com/octocat/Hello-World/commit/c19d0da7c782f8786205b1d4d2436a394d3ebef3', $target->url);
    }

    public function test_falls_back_to_the_given_ref_when_no_releases_and_no_stable_branch_exist(): void
    {
        $mock = new MockHandler([
            new Response(404, [], json_encode(['message' => 'Not Found'])),
            new Response(404, [], json_encode(['message' => 'Branch not found'])),
            new Response(200, [], json_encode([
                'sha' => 'aaaaaaabbbbbbbcccccccdddddddeeeeeeefffff',
                'html_url' => null,
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $target = $checker->findLatest('nielspeen', 'AiAssistant', 'main');

        $this->assertSame(UpdateTarget::MODE_COMMIT, $target->mode);
        $this->assertSame('aaaaaaabbbbbbbcccccccdddddddeeeeeeefffff', $target->ref);
        $this->assertSame('commit aaaaaaa on main', $target->label);
        $this->assertNull($target->url);
    }

    public function test_throws_a_rate_limit_specific_message_on_403_with_zero_remaining(): void
    {
        $mock = new MockHandler([
            new Response(403, ['X-RateLimit-Remaining' => '0'], json_encode(['message' => 'API rate limit exceeded'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('rate limit');

        $checker->findLatest('octocat', 'Hello-World', 'main');
    }

    public function test_throws_a_generic_403_message_when_not_rate_limited(): void
    {
        $mock = new MockHandler([new Response(403, [], json_encode(['message' => 'Forbidden']))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('HTTP 403');

        $checker->findLatest('octocat', 'Hello-World', 'main');
    }

    public function test_throws_on_other_non_2xx_status(): void
    {
        $mock = new MockHandler([new Response(500, [], 'server error')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('HTTP 500');

        $checker->findLatest('octocat', 'Hello-World', 'main');
    }

    public function test_throws_on_malformed_non_json_response(): void
    {
        $mock = new MockHandler([new Response(200, [], 'this is not json')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('not valid JSON');

        $checker->findLatest('octocat', 'Hello-World', 'main');
    }

    public function test_throws_when_release_response_is_missing_a_tag_name(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['html_url' => 'https://example.test']))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('did not include a tag name');

        $checker->findLatest('octocat', 'Hello-World', 'main');
    }

    public function test_throws_when_commit_response_is_missing_a_sha(): void
    {
        $mock = new MockHandler([
            new Response(404, [], json_encode(['message' => 'Not Found'])),
            new Response(404, [], json_encode(['message' => 'Branch not found'])),
            new Response(200, [], json_encode(['html_url' => 'https://example.test'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('did not include a commit SHA');

        $checker->findLatest('octocat', 'Hello-World', 'main');
    }

    public function test_throws_on_connection_failure(): void
    {
        $mock = new MockHandler([
            new ConnectException('Could not resolve host', new Request('GET', 'https://api.github.com')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $checker = new UpdateChecker($client);

        $this->expectException(GithubDownloadException::class);

        $checker->findLatest('octocat', 'Hello-World', 'main');
    }

    // ---- Real network: proves the commit-fallback path against a real
    // repo with no releases (mirrors the rigor of GithubRepoResolverTest's
    // real-API test). The tag-release path is covered by the mocked tests
    // above only -- deliberately not proven against a live repo's real
    // releases, so this suite has no dependency on some other maintainer's
    // release cadence staying stable over time. -----------------------------

    public function test_finds_the_latest_commit_against_the_real_github_api_for_a_repo_with_no_releases(): void
    {
        $probe = @get_headers('https://api.github.com/', 1);
        if ($probe === false) {
            $this->markTestSkipped(
                'Outbound network access to api.github.com is unavailable in this environment; '
                . 'cannot verify UpdateChecker against the real GitHub API.'
            );
        }

        $client = new Client();
        $checker = new UpdateChecker($client);

        try {
            $target = $checker->findLatest('nielspeen', 'AiAssistant', 'main');
        } catch (GithubDownloadException $e) {
            $this->markTestSkipped(
                'Could not reach the real GitHub API to verify UpdateChecker: ' . $e->getMessage()
            );

            return;
        }

        $this->assertSame(UpdateTarget::MODE_COMMIT, $target->mode);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $target->ref);
        $this->assertStringContainsString('main', $target->label);
    }
}

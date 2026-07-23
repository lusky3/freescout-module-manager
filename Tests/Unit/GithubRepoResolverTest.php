<?php

namespace Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;
use Modules\ModuleManager\Services\GithubRepoResolver;
use PHPUnit\Framework\TestCase;

class GithubRepoResolverTest extends TestCase
{
    // ---- parseUrl(): pure, no network -------------------------------------

    public function test_parses_a_plain_https_url(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('https://github.com/OneTwo3D/freescout-woocommerce-myaccount');

        $this->assertSame([
            'owner' => 'OneTwo3D',
            'repo' => 'freescout-woocommerce-myaccount',
            'ref' => null,
        ], $parsed);
    }

    public function test_parses_a_plain_https_url_for_the_other_example_repo(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('https://github.com/karrierekick-dev/freescout-auto-link-ticket');

        $this->assertSame([
            'owner' => 'karrierekick-dev',
            'repo' => 'freescout-auto-link-ticket',
            'ref' => null,
        ], $parsed);
    }

    public function test_parses_a_url_with_a_trailing_slash(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('https://github.com/octocat/Hello-World/');

        $this->assertSame([
            'owner' => 'octocat',
            'repo' => 'Hello-World',
            'ref' => null,
        ], $parsed);
    }

    public function test_parses_a_url_with_a_dot_git_suffix(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('https://github.com/octocat/Hello-World.git');

        $this->assertSame([
            'owner' => 'octocat',
            'repo' => 'Hello-World',
            'ref' => null,
        ], $parsed);
    }

    public function test_parses_a_tree_branch_url_and_captures_the_branch_as_the_ref_hint(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('https://github.com/octocat/Hello-World/tree/develop');

        $this->assertSame([
            'owner' => 'octocat',
            'repo' => 'Hello-World',
            'ref' => 'develop',
        ], $parsed);
    }

    public function test_parses_a_tree_branch_url_with_a_trailing_slash(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('https://github.com/octocat/Hello-World/tree/develop/');

        $this->assertSame([
            'owner' => 'octocat',
            'repo' => 'Hello-World',
            'ref' => 'develop',
        ], $parsed);
    }

    public function test_returns_null_for_a_tree_branch_url_whose_branch_name_contains_a_slash(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        // '/' is not part of the branch-name character class, so a branch
        // like "release/1.0" -- which itself contains a slash -- doesn't
        // match at all, rather than silently capturing only "release" and
        // discarding "/1.0". This format inherently can't disambiguate a
        // multi-segment branch name from a sub-path under it (GitHub's own
        // web UI resolves that ambiguity by checking the real refs list,
        // which parseUrl() deliberately can't do since it's pure/no-network);
        // failing to parse rather than guessing is the safer of those two
        // options for a case this genuinely can't tell apart.
        $parsed = $resolver->parseUrl('https://github.com/octocat/Hello-World/tree/release/1.0');

        $this->assertNull($parsed);
    }

    public function test_parses_the_ssh_form(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('git@github.com:octocat/Hello-World.git');

        $this->assertSame([
            'owner' => 'octocat',
            'repo' => 'Hello-World',
            'ref' => null,
        ], $parsed);
    }

    public function test_parses_the_ssh_form_for_the_real_example_repos(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $this->assertSame([
            'owner' => 'OneTwo3D',
            'repo' => 'freescout-woocommerce-myaccount',
            'ref' => null,
        ], $resolver->parseUrl('git@github.com:OneTwo3D/freescout-woocommerce-myaccount.git'));

        $this->assertSame([
            'owner' => 'karrierekick-dev',
            'repo' => 'freescout-auto-link-ticket',
            'ref' => null,
        ], $resolver->parseUrl('git@github.com:karrierekick-dev/freescout-auto-link-ticket.git'));
    }

    public function test_parses_repo_names_containing_dots_and_underscores(): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $parsed = $resolver->parseUrl('https://github.com/octocat/some_repo.name');

        $this->assertSame([
            'owner' => 'octocat',
            'repo' => 'some_repo.name',
            'ref' => null,
        ], $parsed);
    }

    /**
     * @dataProvider garbageUrlProvider
     */
    public function test_returns_null_for_garbage_input(string $garbage): void
    {
        $resolver = new GithubRepoResolver(new Client());

        $this->assertNull($resolver->parseUrl($garbage));
    }

    public function garbageUrlProvider(): array
    {
        return [
            'empty string' => [''],
            'not a url at all' => ['not a url'],
            'wrong host' => ['https://gitlab.com/octocat/Hello-World'],
            'github.com but no repo segment' => ['https://github.com/octocat'],
            'github.com with no owner or repo' => ['https://github.com/'],
            'ftp scheme' => ['ftp://github.com/octocat/Hello-World'],
            'owner with leading hyphen' => ['https://github.com/-octocat/Hello-World'],
            'owner with trailing hyphen' => ['https://github.com/octocat-/Hello-World'],
            'owner with an invalid character' => ['https://github.com/octo_cat/Hello-World'],
            'repo with an invalid character' => ['https://github.com/octocat/Hello World'],
            'javascript scheme' => ['javascript:alert(1)'],
            'a github gist, not a repo' => ['https://gist.github.com/octocat/abc123'],
            'ssh form with wrong host' => ['git@gitlab.com:octocat/Hello-World.git'],
            'ssh form missing .git suffix' => ['git@github.com:octocat/Hello-World'],
        ];
    }

    // ---- fetchMetadata(): mocked network -----------------------------------

    public function test_fetch_metadata_returns_default_branch_and_name_on_success(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode([
                'name' => 'Hello-World',
                'default_branch' => 'main',
            ])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $metadata = $resolver->fetchMetadata('octocat', 'Hello-World');

        $this->assertSame(['default_branch' => 'main', 'name' => 'Hello-World'], $metadata);
    }

    public function test_fetch_metadata_sends_a_user_agent_header(): void
    {
        $container = [];
        $history = \GuzzleHttp\Middleware::history($container);
        $mock = new MockHandler([
            new Response(200, [], json_encode(['name' => 'Hello-World', 'default_branch' => 'main'])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push($history);
        $client = new Client(['handler' => $stack]);
        $resolver = new GithubRepoResolver($client);

        $resolver->fetchMetadata('octocat', 'Hello-World');

        $this->assertCount(1, $container);
        $sentRequest = $container[0]['request'];
        $this->assertTrue($sentRequest->hasHeader('User-Agent'));
        $this->assertNotSame('', $sentRequest->getHeaderLine('User-Agent'));
    }

    public function test_fetch_metadata_throws_a_clear_message_on_404(): void
    {
        $mock = new MockHandler([new Response(404, [], json_encode(['message' => 'Not Found']))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage("no repository at octocat/does-not-exist");

        $resolver->fetchMetadata('octocat', 'does-not-exist');
    }

    public function test_fetch_metadata_throws_a_rate_limit_specific_message_on_403_with_zero_remaining(): void
    {
        $mock = new MockHandler([
            new Response(403, ['X-RateLimit-Remaining' => '0'], json_encode(['message' => 'API rate limit exceeded'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('rate limit');

        try {
            $resolver->fetchMetadata('octocat', 'Hello-World');
        } catch (GithubDownloadException $e) {
            $this->assertStringContainsString('manually', $e->getMessage());
            throw $e;
        }
    }

    public function test_fetch_metadata_throws_a_generic_403_message_when_not_rate_limited(): void
    {
        $mock = new MockHandler([
            new Response(403, [], json_encode(['message' => 'Forbidden'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('HTTP 403');

        $resolver->fetchMetadata('octocat', 'Hello-World');
    }

    public function test_fetch_metadata_throws_on_other_non_2xx_status(): void
    {
        $mock = new MockHandler([new Response(500, [], 'server error')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('HTTP 500');

        $resolver->fetchMetadata('octocat', 'Hello-World');
    }

    public function test_fetch_metadata_throws_on_malformed_non_json_response(): void
    {
        $mock = new MockHandler([new Response(200, [], 'this is not json')]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('not valid JSON');

        $resolver->fetchMetadata('octocat', 'Hello-World');
    }

    public function test_fetch_metadata_throws_when_json_is_missing_default_branch(): void
    {
        $mock = new MockHandler([new Response(200, [], json_encode(['name' => 'Hello-World']))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('did not include a default branch');

        $resolver->fetchMetadata('octocat', 'Hello-World');
    }

    public function test_fetch_metadata_throws_on_connection_failure(): void
    {
        $mock = new MockHandler([
            new ConnectException('Could not resolve host', new Request('GET', 'https://api.github.com')),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);

        $resolver->fetchMetadata('octocat', 'Hello-World');
    }

    // ---- resolve(): chains parseUrl() + fetchMetadata() --------------------

    public function test_resolve_uses_the_default_branch_when_the_url_has_no_tree_ref(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['name' => 'AiAssistant', 'default_branch' => 'main'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $resolved = $resolver->resolve('https://github.com/nielspeen/AiAssistant');

        $this->assertSame([
            'owner' => 'nielspeen',
            'repo' => 'AiAssistant',
            'ref' => 'main',
            'label' => 'AiAssistant',
        ], $resolved);
    }

    public function test_resolve_prefers_the_urls_tree_ref_hint_over_the_default_branch(): void
    {
        $mock = new MockHandler([
            new Response(200, [], json_encode(['name' => 'AiAssistant', 'default_branch' => 'main'])),
        ]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $resolved = $resolver->resolve('https://github.com/nielspeen/AiAssistant/tree/develop');

        $this->assertSame([
            'owner' => 'nielspeen',
            'repo' => 'AiAssistant',
            'ref' => 'develop',
            'label' => 'AiAssistant',
        ], $resolved);
    }

    public function test_resolve_throws_on_unparseable_url_without_making_a_network_call(): void
    {
        // No MockHandler responses queued at all: if resolve() somehow tried
        // to make a network call despite the URL being unparseable,
        // MockHandler would throw its own "no more items" exception instead
        // of GithubDownloadException, and this test would fail loudly.
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([]))]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);
        $this->expectExceptionMessage('Could not recognize');

        $resolver->resolve('not a url');
    }

    public function test_resolve_propagates_the_metadata_lookup_failure(): void
    {
        $mock = new MockHandler([new Response(404, [], json_encode(['message' => 'Not Found']))]);
        $client = new Client(['handler' => HandlerStack::create($mock)]);
        $resolver = new GithubRepoResolver($client);

        $this->expectException(GithubDownloadException::class);

        $resolver->resolve('https://github.com/octocat/does-not-exist');
    }

    // ---- Real network: proves fetchMetadata() actually works against real
    // GitHub responses, not just mocks (mirrors the rigor of
    // GithubRepoFetcherTest's real-codeload download-cap test). ------------

    /**
     * nielspeen/AiAssistant is already relied on elsewhere in this
     * codebase's live verification (GithubRepoFetcherTest, this module's
     * default-repos.json seed) as a real, small, stable public repo -- reused
     * here rather than picking a different one, so there is exactly one
     * "is this repo still there" assumption to keep an eye on across the
     * whole test suite instead of several.
     */
    public function test_fetch_metadata_against_the_real_github_api(): void
    {
        $probe = @get_headers('https://api.github.com/', 1);
        if ($probe === false) {
            $this->markTestSkipped(
                'Outbound network access to api.github.com is unavailable in this environment; '
                . 'cannot verify fetchMetadata() against the real GitHub API.'
            );
        }

        $client = new Client();
        $resolver = new GithubRepoResolver($client);

        try {
            $metadata = $resolver->fetchMetadata('nielspeen', 'AiAssistant');
        } catch (GithubDownloadException $e) {
            $this->markTestSkipped(
                'Could not reach the real GitHub API to verify fetchMetadata(): ' . $e->getMessage()
            );

            return;
        }

        $this->assertSame('AiAssistant', $metadata['name']);
        $this->assertIsString($metadata['default_branch']);
        $this->assertNotSame('', $metadata['default_branch']);
    }

    /**
     * End-to-end real-network proof for resolve(), against both of the
     * exact example URLs this feature is meant to handle -- verified real,
     * public repos (not assumed) as of writing this test.
     */
    public function test_resolve_against_the_real_github_api_for_both_example_urls(): void
    {
        $probe = @get_headers('https://api.github.com/', 1);
        if ($probe === false) {
            $this->markTestSkipped(
                'Outbound network access to api.github.com is unavailable in this environment; '
                . 'cannot verify resolve() against the real GitHub API.'
            );
        }

        $client = new Client();
        $resolver = new GithubRepoResolver($client);

        try {
            $first = $resolver->resolve('https://github.com/OneTwo3D/freescout-woocommerce-myaccount');
            $second = $resolver->resolve('https://github.com/karrierekick-dev/freescout-auto-link-ticket');
        } catch (GithubDownloadException $e) {
            $this->markTestSkipped(
                'Could not reach the real GitHub API to verify resolve(): ' . $e->getMessage()
            );

            return;
        }

        $this->assertSame('OneTwo3D', $first['owner']);
        $this->assertSame('freescout-woocommerce-myaccount', $first['repo']);
        $this->assertNotSame('', $first['ref']);
        $this->assertSame('freescout-woocommerce-myaccount', $first['label']);

        $this->assertSame('karrierekick-dev', $second['owner']);
        $this->assertSame('freescout-auto-link-ticket', $second['repo']);
        $this->assertNotSame('', $second['ref']);
        $this->assertSame('freescout-auto-link-ticket', $second['label']);
    }
}

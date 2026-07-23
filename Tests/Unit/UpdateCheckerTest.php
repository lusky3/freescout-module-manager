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
}

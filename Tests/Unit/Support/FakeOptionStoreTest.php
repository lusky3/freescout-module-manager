<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FakeOptionStore;

class FakeOptionStoreTest extends TestCase
{
    public function test_returns_default_when_key_is_missing(): void
    {
        $store = new FakeOptionStore();

        $this->assertSame('fallback', $store->get('missing.key', 'fallback'));
    }

    public function test_set_then_get_returns_stored_value(): void
    {
        $store = new FakeOptionStore();

        $store->set('modulemanager.saved_repos', ['a', 'b']);

        $this->assertSame(['a', 'b'], $store->get('modulemanager.saved_repos'));
    }
}

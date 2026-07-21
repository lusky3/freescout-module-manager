<?php

namespace Tests\Unit;

use Modules\ModuleManager\Services\SavedRepoStore;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FakeOptionStore;

class SavedRepoStoreTest extends TestCase
{
    public function test_all_returns_empty_array_when_nothing_saved(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());

        $this->assertSame([], $store->all());
    }

    public function test_add_persists_a_new_entry_with_generated_id(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());

        $entry = $store->add('nielspeen', 'AiAssistant', 'main', 'AI Assistant');

        $this->assertNotEmpty($entry['id']);
        $this->assertSame('nielspeen', $entry['owner']);
        $this->assertSame('AiAssistant', $entry['repo']);
        $this->assertSame('main', $entry['ref']);
        $this->assertSame('AI Assistant', $entry['label']);
        $this->assertCount(1, $store->all());
    }

    public function test_remove_deletes_matching_entry_and_returns_true(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());
        $entry = $store->add('nielspeen', 'AiAssistant', 'main', 'AI Assistant');

        $this->assertTrue($store->remove($entry['id']));
        $this->assertSame([], $store->all());
    }

    public function test_remove_returns_false_when_id_not_found(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());

        $this->assertFalse($store->remove('does-not-exist'));
    }

    public function test_find_returns_matching_entry_or_null(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());
        $entry = $store->add('nielspeen', 'AiAssistant', 'main', 'AI Assistant');

        $this->assertSame($entry, $store->find($entry['id']));
        $this->assertNull($store->find('does-not-exist'));
    }
}

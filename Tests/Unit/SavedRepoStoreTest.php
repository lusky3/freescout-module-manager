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

        $this->assertNotEmpty($entry->id);
        $this->assertSame('nielspeen', $entry->owner);
        $this->assertSame('AiAssistant', $entry->repo);
        $this->assertSame('main', $entry->ref);
        $this->assertSame('AI Assistant', $entry->label);
        $this->assertCount(1, $store->all());
    }

    public function test_remove_deletes_matching_entry_and_returns_true(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());
        $entry = $store->add('nielspeen', 'AiAssistant', 'main', 'AI Assistant');

        $this->assertTrue($store->remove($entry->id));
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

        $found = $store->find($entry->id);
        $this->assertNotNull($found);
        $this->assertSame($entry->id, $found->id);
        $this->assertSame($entry->owner, $found->owner);
        $this->assertSame($entry->repo, $found->repo);
        $this->assertSame($entry->ref, $found->ref);
        $this->assertSame($entry->label, $found->label);
        $this->assertNull($store->find('does-not-exist'));
    }

    public function test_mark_installed_sets_alias_and_folder_and_returns_true(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());
        $entry = $store->add('nielspeen', 'AiAssistant', 'main', 'AI Assistant');

        $this->assertTrue($store->markInstalled($entry->id, 'aiassistant', 'AiAssistant-main'));

        $updated = $store->find($entry->id);
        $this->assertSame('aiassistant', $updated->installedAlias);
        $this->assertSame('AiAssistant-main', $updated->installedFolder);
    }

    public function test_mark_installed_returns_false_when_id_not_found(): void
    {
        $store = new SavedRepoStore(new FakeOptionStore());
        $store->add('nielspeen', 'AiAssistant', 'main', 'AI Assistant');

        $this->assertFalse($store->markInstalled('does-not-exist', 'aiassistant', 'AiAssistant-main'));
    }

    public function test_add_and_remove_serialize_via_file_lock_and_final_state_reflects_both(): void
    {
        // This doesn't prove cross-process concurrency by itself (that's
        // covered by a live two-request test against the running Docker
        // instance -- see item 10 verification), but it does exercise
        // withLock() actually wrapping every mutating call: two sequential
        // add()s and a remove() all complete without deadlocking on the
        // same lock file, and the final state reflects all three calls.
        $lockFilePath = sys_get_temp_dir() . '/modulemanager_test_lock_' . bin2hex(random_bytes(4)) . '.lock';
        $store = new SavedRepoStore(new FakeOptionStore(), $lockFilePath);

        $first = $store->add('nielspeen', 'AiAssistant', 'main', 'AI Assistant');
        $second = $store->add('someoneelse', 'AnotherModule', 'main', 'Another Module');

        $this->assertCount(2, $store->all());

        $this->assertTrue($store->remove($first->id));

        $remaining = $store->all();
        $this->assertCount(1, $remaining);
        $this->assertSame($second->id, $remaining[0]->id);

        @unlink($lockFilePath);
    }

    public function test_all_skips_malformed_stored_entries_without_throwing(): void
    {
        $options = new FakeOptionStore();
        $options->set(SavedRepoStore::OPTION_KEY, [
            ['id' => 'a1', 'owner' => 'nielspeen', 'repo' => 'AiAssistant', 'ref' => 'main', 'label' => 'AI Assistant'],
            // Missing required keys entirely.
            ['id' => 'a2'],
            // Not even an array.
            'not-an-array',
        ]);
        $store = new SavedRepoStore($options);

        $all = $store->all();
        $this->assertCount(1, $all);
        $this->assertSame('a1', $all[0]->id);
    }
}

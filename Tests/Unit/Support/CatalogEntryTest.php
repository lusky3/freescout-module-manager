<?php

namespace Tests\Unit\Support;

use Modules\ModuleManager\Services\Support\CatalogEntry;
use Modules\ModuleManager\Services\Support\SavedRepo;
use PHPUnit\Framework\TestCase;

class CatalogEntryTest extends TestCase
{
    public function test_constructor_rejects_empty_required_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CatalogEntry('', 'repo', 'main', 'Name', 'Description', null, 0, null, null, null, null, null);
    }

    public function test_url_builds_a_github_link(): void
    {
        $entry = new CatalogEntry(
            'avenjamin',
            'freescout-Following-Module',
            'main',
            'Following',
            "Adds a Following folder.",
            'Ben Perry',
            8,
            '2025-05-15T04:43:00Z',
            'AGPL-3.0',
            null,
            '2026-07-23',
            'Reviewed provider code, no outbound calls, no obfuscation.'
        );

        $this->assertSame('https://github.com/avenjamin/freescout-Following-Module', $entry->url());
    }

    public function test_from_array_builds_a_valid_entry(): void
    {
        $entry = CatalogEntry::fromArray([
            'owner' => 'avenjamin',
            'repo' => 'freescout-Following-Module',
            'ref' => 'main',
            'name' => 'Following',
            'description' => 'Adds a Following folder.',
            'author_name' => 'Ben Perry',
            'stars' => 8,
            'last_pushed_at' => '2025-05-15T04:43:00Z',
            'license' => 'AGPL-3.0',
            'screenshot_url' => null,
            'reviewed_at' => '2026-07-23',
            'review_notes' => 'Reviewed provider code, no outbound calls, no obfuscation.',
        ]);

        $this->assertNotNull($entry);
        $this->assertSame('Following', $entry->name);
        $this->assertSame(8, $entry->stars);
        $this->assertSame('Ben Perry', $entry->authorName);
    }

    public function test_from_array_returns_null_for_missing_required_field(): void
    {
        $entry = CatalogEntry::fromArray([
            'owner' => 'avenjamin',
            'repo' => 'freescout-Following-Module',
            'ref' => 'main',
            'name' => 'Following',
        ]);

        $this->assertNull($entry);
    }

    public function test_from_array_returns_null_for_non_string_owner(): void
    {
        $entry = CatalogEntry::fromArray([
            'owner' => 12345,
            'repo' => 'freescout-Following-Module',
            'ref' => 'main',
            'name' => 'Following',
            'description' => 'Adds a Following folder.',
        ]);

        $this->assertNull($entry);
    }

    public function test_to_array_round_trips_through_from_array(): void
    {
        $original = CatalogEntry::fromArray([
            'owner' => 'avenjamin',
            'repo' => 'freescout-Following-Module',
            'ref' => 'main',
            'name' => 'Following',
            'description' => 'Adds a Following folder.',
            'author_name' => 'Ben Perry',
            'stars' => 8,
            'last_pushed_at' => '2025-05-15T04:43:00Z',
            'license' => 'AGPL-3.0',
            'screenshot_url' => null,
            'reviewed_at' => '2026-07-23',
            'review_notes' => 'Reviewed provider code.',
        ]);

        $roundTripped = CatalogEntry::fromArray($original->toArray());

        $this->assertEquals($original->toArray(), $roundTripped->toArray());
    }

    public function test_matches_saved_repo_is_case_insensitive(): void
    {
        $entry = CatalogEntry::fromArray([
            'owner' => 'avenjamin',
            'repo' => 'freescout-Following-Module',
            'ref' => 'main',
            'name' => 'Following',
            'description' => 'Adds a Following folder.',
        ]);

        $savedRepo = SavedRepo::fromArray([
            'id' => 'abc123',
            'owner' => 'AvenJamin',
            'repo' => 'FREESCOUT-FOLLOWING-MODULE',
            'ref' => 'main',
            'label' => 'Following',
        ]);

        $this->assertNotNull($entry);
        $this->assertNotNull($savedRepo);
        $this->assertTrue($entry->matchesSavedRepo($savedRepo));
    }

    public function test_matches_saved_repo_returns_false_for_a_different_repo(): void
    {
        $entry = CatalogEntry::fromArray([
            'owner' => 'avenjamin',
            'repo' => 'freescout-Following-Module',
            'ref' => 'main',
            'name' => 'Following',
            'description' => 'Adds a Following folder.',
        ]);

        $savedRepo = SavedRepo::fromArray([
            'id' => 'abc123',
            'owner' => 'avenjamin',
            'repo' => 'freescout-Themes-Module',
            'ref' => 'main',
            'label' => 'Themes',
        ]);

        $this->assertFalse($entry->matchesSavedRepo($savedRepo));
    }
}

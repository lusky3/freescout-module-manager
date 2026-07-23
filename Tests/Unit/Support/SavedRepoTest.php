<?php

namespace Tests\Unit\Support;

use Modules\ModuleManager\Services\Support\SavedRepo;
use PHPUnit\Framework\TestCase;

class SavedRepoTest extends TestCase
{
    public function test_to_array_round_trips_through_from_array(): void
    {
        $repo = new SavedRepo('abc123', 'nielspeen', 'AiAssistant', 'main', 'AI Assistant', 'aiassistant', 'AiAssistant-main');

        $array = $repo->toArray();

        $this->assertSame([
            'id' => 'abc123',
            'owner' => 'nielspeen',
            'repo' => 'AiAssistant',
            'ref' => 'main',
            'label' => 'AI Assistant',
            'installed_alias' => 'aiassistant',
            'installed_folder' => 'AiAssistant-main',
            'installed_commit_sha' => null,
            'latest_known_ref' => null,
            'latest_known_label' => null,
            'latest_known_url' => null,
            'latest_checked_at' => null,
        ], $array);

        $rebuilt = SavedRepo::fromArray($array);
        $this->assertNotNull($rebuilt);
        $this->assertSame($repo->id, $rebuilt->id);
        $this->assertSame($repo->owner, $rebuilt->owner);
        $this->assertSame($repo->repo, $rebuilt->repo);
        $this->assertSame($repo->ref, $rebuilt->ref);
        $this->assertSame($repo->label, $rebuilt->label);
        $this->assertSame($repo->installedAlias, $rebuilt->installedAlias);
        $this->assertSame($repo->installedFolder, $rebuilt->installedFolder);
    }

    public function test_from_array_defaults_installed_fields_to_null_when_absent(): void
    {
        $repo = SavedRepo::fromArray([
            'id' => 'abc123',
            'owner' => 'nielspeen',
            'repo' => 'AiAssistant',
            'ref' => 'main',
            'label' => 'AI Assistant',
        ]);

        $this->assertNotNull($repo);
        $this->assertNull($repo->installedAlias);
        $this->assertNull($repo->installedFolder);
    }

    /**
     * @dataProvider malformedEntryProvider
     */
    public function test_from_array_rejects_malformed_entries(array $data): void
    {
        $this->assertNull(SavedRepo::fromArray($data));
    }

    public function malformedEntryProvider(): array
    {
        return [
            'missing id' => [['owner' => 'a', 'repo' => 'b', 'ref' => 'c', 'label' => 'd']],
            'missing owner' => [['id' => 'x', 'repo' => 'b', 'ref' => 'c', 'label' => 'd']],
            'missing repo' => [['id' => 'x', 'owner' => 'a', 'ref' => 'c', 'label' => 'd']],
            'missing ref' => [['id' => 'x', 'owner' => 'a', 'repo' => 'b', 'label' => 'd']],
            'missing label' => [['id' => 'x', 'owner' => 'a', 'repo' => 'b', 'ref' => 'c']],
            'empty string ref' => [['id' => 'x', 'owner' => 'a', 'repo' => 'b', 'ref' => '', 'label' => 'd']],
            'non-string owner' => [['id' => 'x', 'owner' => 123, 'repo' => 'b', 'ref' => 'c', 'label' => 'd']],
            'non-string installed_alias' => [['id' => 'x', 'owner' => 'a', 'repo' => 'b', 'ref' => 'c', 'label' => 'd', 'installed_alias' => 42]],
            'non-string installed_folder' => [['id' => 'x', 'owner' => 'a', 'repo' => 'b', 'ref' => 'c', 'label' => 'd', 'installed_folder' => 42]],
        ];
    }

    /**
     * The constructor enforces its own invariant directly -- it must not be
     * possible to build an "empty" SavedRepo just by calling `new
     * SavedRepo(...)` and skipping fromArray() (which is exactly what
     * SavedRepoStore::add() does).
     *
     * @dataProvider emptyRequiredFieldProvider
     */
    public function test_constructor_rejects_empty_required_fields(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SavedRepo(...$args);
    }

    public function emptyRequiredFieldProvider(): array
    {
        return [
            'empty id' => [['', 'owner', 'repo', 'ref', 'label']],
            'empty owner' => [['id', '', 'repo', 'ref', 'label']],
            'empty repo' => [['id', 'owner', '', 'ref', 'label']],
            'empty ref' => [['id', 'owner', 'repo', '', 'label']],
            'empty label' => [['id', 'owner', 'repo', 'ref', '']],
            'all empty' => [['', '', '', '', '']],
        ];
    }

    public function test_constructor_accepts_all_non_empty_required_fields(): void
    {
        $repo = new SavedRepo('id', 'owner', 'repo', 'ref', 'label');

        $this->assertSame('id', $repo->id);
        $this->assertSame('owner', $repo->owner);
        $this->assertSame('repo', $repo->repo);
        $this->assertSame('ref', $repo->ref);
        $this->assertSame('label', $repo->label);
        $this->assertNull($repo->installedAlias);
        $this->assertNull($repo->installedFolder);
    }

    public function test_from_array_returns_null_instead_of_throwing_when_construction_would_fail(): void
    {
        // Sanity check that fromArray()'s "return null on malformed data"
        // contract holds even for the case the constructor itself now
        // guards: fromArray()'s own pre-checks already reject this before
        // ever reaching `new SavedRepo(...)`, but this proves the
        // try/catch translation layer around the constructor call doesn't
        // let an InvalidArgumentException escape if that ever changes.
        $this->assertNull(SavedRepo::fromArray([
            'id' => 'x',
            'owner' => '',
            'repo' => 'b',
            'ref' => 'c',
            'label' => 'd',
        ]));
    }

    public function test_to_array_round_trips_update_tracking_fields(): void
    {
        $repo = new SavedRepo(
            'abc123',
            'nielspeen',
            'AiAssistant',
            'main',
            'AI Assistant',
            'aiassistant',
            'AiAssistant-main',
            'c19d0da7c782f8786205b1d4d2436a394d3ebef3',
            'c19d0da7c782f8786205b1d4d2436a394d3ebef3',
            'commit c19d0da on main',
            'https://github.com/nielspeen/AiAssistant/commit/c19d0da7c782f8786205b1d4d2436a394d3ebef3',
            '2026-07-23T12:00:00+00:00'
        );

        $array = $repo->toArray();

        $this->assertSame('c19d0da7c782f8786205b1d4d2436a394d3ebef3', $array['installed_commit_sha']);
        $this->assertSame('c19d0da7c782f8786205b1d4d2436a394d3ebef3', $array['latest_known_ref']);
        $this->assertSame('commit c19d0da on main', $array['latest_known_label']);
        $this->assertSame('https://github.com/nielspeen/AiAssistant/commit/c19d0da7c782f8786205b1d4d2436a394d3ebef3', $array['latest_known_url']);
        $this->assertSame('2026-07-23T12:00:00+00:00', $array['latest_checked_at']);

        $rebuilt = SavedRepo::fromArray($array);
        $this->assertNotNull($rebuilt);
        $this->assertSame($repo->installedCommitSha, $rebuilt->installedCommitSha);
        $this->assertSame($repo->latestKnownRef, $rebuilt->latestKnownRef);
        $this->assertSame($repo->latestKnownLabel, $rebuilt->latestKnownLabel);
        $this->assertSame($repo->latestKnownUrl, $rebuilt->latestKnownUrl);
        $this->assertSame($repo->latestCheckedAt, $rebuilt->latestCheckedAt);
    }

    public function test_from_array_defaults_update_tracking_fields_to_null_when_absent(): void
    {
        $repo = SavedRepo::fromArray([
            'id' => 'abc123',
            'owner' => 'nielspeen',
            'repo' => 'AiAssistant',
            'ref' => 'main',
            'label' => 'AI Assistant',
        ]);

        $this->assertNotNull($repo);
        $this->assertNull($repo->installedCommitSha);
        $this->assertNull($repo->latestKnownRef);
        $this->assertNull($repo->latestKnownLabel);
        $this->assertNull($repo->latestKnownUrl);
        $this->assertNull($repo->latestCheckedAt);
    }

    public function test_from_array_rejects_non_string_update_tracking_fields(): void
    {
        $base = ['id' => 'x', 'owner' => 'a', 'repo' => 'b', 'ref' => 'c', 'label' => 'd'];

        $this->assertNull(SavedRepo::fromArray($base + ['installed_commit_sha' => 42]));
        $this->assertNull(SavedRepo::fromArray($base + ['latest_known_ref' => 42]));
        $this->assertNull(SavedRepo::fromArray($base + ['latest_known_label' => 42]));
        $this->assertNull(SavedRepo::fromArray($base + ['latest_known_url' => 42]));
        $this->assertNull(SavedRepo::fromArray($base + ['latest_checked_at' => 42]));
    }

    public function test_is_update_available_returns_null_when_never_checked(): void
    {
        $repo = new SavedRepo('id', 'owner', 'repo', 'main', 'label');

        $this->assertNull($repo->isUpdateAvailable());
    }

    public function test_is_update_available_compares_tag_ref_when_installed_commit_sha_is_null(): void
    {
        // Tag-tracked repo: $ref itself is the version marker (set to the
        // tag actually installed by SavedRepoStore::markUpdated()), and
        // installedCommitSha stays null.
        $upToDate = new SavedRepo('id', 'owner', 'repo', 'v1.0.0', 'label', null, null, null, 'v1.0.0');
        $this->assertFalse($upToDate->isUpdateAvailable());

        $behind = new SavedRepo('id', 'owner', 'repo', 'v1.0.0', 'label', null, null, null, 'v1.1.0');
        $this->assertTrue($behind->isUpdateAvailable());
    }

    public function test_is_update_available_compares_installed_commit_sha_when_present(): void
    {
        // Commit-tracked repo: $ref is just the branch *name* and never
        // changes, so installedCommitSha (what was actually installed) is
        // the real comparison key, not $ref.
        $upToDate = new SavedRepo('id', 'owner', 'repo', 'main', 'label', null, null, 'sha-a', 'sha-a');
        $this->assertFalse($upToDate->isUpdateAvailable());

        $behind = new SavedRepo('id', 'owner', 'repo', 'main', 'label', null, null, 'sha-a', 'sha-b');
        $this->assertTrue($behind->isUpdateAvailable());
    }
}

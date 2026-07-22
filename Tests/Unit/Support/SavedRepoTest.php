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
}

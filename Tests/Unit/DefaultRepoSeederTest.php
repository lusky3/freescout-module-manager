<?php

namespace Tests\Unit;

use Modules\ModuleManager\Services\DefaultRepoSeeder;
use Modules\ModuleManager\Services\SavedRepoStore;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FakeOptionStore;

class DefaultRepoSeederTest extends TestCase
{
    private string $defaultsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultsPath = sys_get_temp_dir().'/default_repos_'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($this->defaultsPath, json_encode([
            ['owner' => 'nielspeen', 'repo' => 'AiAssistant', 'ref' => 'main', 'label' => 'AI Assistant'],
        ]));
    }

    protected function tearDown(): void
    {
        @unlink($this->defaultsPath);
        parent::tearDown();
    }

    public function test_seeds_saved_repos_from_defaults_file_on_first_run(): void
    {
        $options = new FakeOptionStore();
        $repoStore = new SavedRepoStore($options);
        $seeder = new DefaultRepoSeeder($repoStore, $options, $this->defaultsPath);

        $seeder->seedIfNeeded();

        $this->assertCount(1, $repoStore->all());
        $this->assertSame('nielspeen', $repoStore->all()[0]['owner']);
    }

    public function test_does_not_reseed_after_first_run(): void
    {
        $options = new FakeOptionStore();
        $repoStore = new SavedRepoStore($options);
        $seeder = new DefaultRepoSeeder($repoStore, $options, $this->defaultsPath);

        $seeder->seedIfNeeded();
        $repoStore->remove($repoStore->all()[0]['id']);
        $seeder->seedIfNeeded();

        $this->assertCount(0, $repoStore->all());
    }
}

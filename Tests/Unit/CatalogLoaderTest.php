<?php

namespace Tests\Unit;

use Modules\ModuleManager\Services\CatalogLoader;
use PHPUnit\Framework\TestCase;

class CatalogLoaderTest extends TestCase
{
    private string $catalogPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogPath = sys_get_temp_dir() . '/catalog_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->catalogPath);
        parent::tearDown();
    }

    public function test_returns_empty_array_when_file_is_missing(): void
    {
        $loader = new CatalogLoader($this->catalogPath);

        $this->assertSame([], $loader->all());
    }

    public function test_loads_valid_entries(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            [
                'owner' => 'avenjamin',
                'repo' => 'freescout-Following-Module',
                'ref' => 'main',
                'name' => 'Following',
                'description' => 'Adds a Following folder.',
                'stars' => 8,
            ],
        ]));

        $loader = new CatalogLoader($this->catalogPath);
        $entries = $loader->all();

        $this->assertCount(1, $entries);
        $this->assertSame('Following', $entries[0]->name);
    }

    public function test_skips_malformed_entries_without_throwing(): void
    {
        file_put_contents($this->catalogPath, json_encode([
            [
                'owner' => 'avenjamin',
                'repo' => 'freescout-Following-Module',
                'ref' => 'main',
                'name' => 'Following',
                'description' => 'Adds a Following folder.',
            ],
            [
                'owner' => 'someone',
            ],
            'not even an object',
        ]));

        $loader = new CatalogLoader($this->catalogPath);
        $entries = $loader->all();

        $this->assertCount(1, $entries);
    }

    public function test_returns_empty_array_for_non_array_json(): void
    {
        file_put_contents($this->catalogPath, json_encode('just a string'));

        $loader = new CatalogLoader($this->catalogPath);

        $this->assertSame([], $loader->all());
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ModuleJsonTest extends TestCase
{
    private function moduleJson(): array
    {
        $path = __DIR__ . '/../../module.json';
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, 'module.json must decode to a JSON object.');

        return $data;
    }

    public function test_version_is_a_dotted_triple(): void
    {
        $data = $this->moduleJson();

        $this->assertArrayHasKey('version', $data);
        $this->assertIsString($data['version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $data['version']);
    }

    /**
     * authorUrl's host must never match config('app.freescout_url')'s host
     * on a real FreeScout install -- that's how FreeScout core's
     * App\Module::isOfficial() decides "official" (skip the third-party
     * update-check path entirely) vs. "third-party" (use
     * latestVersionUrl/latestVersionZipUrl below). A github.com URL can
     * never collide with an admin's own installed domain.
     */
    public function test_declares_a_github_author_url(): void
    {
        $data = $this->moduleJson();

        $this->assertSame('https://github.com/lusky3/freescout-module-manager', $data['authorUrl'] ?? null);
    }

    /**
     * Both URLs use GitHub's "latest release" static download alias
     * (releases/latest/download/{asset}), which always resolves to
     * whichever release is currently marked "Latest" -- confirmed GitHub
     * behavior for uploaded release assets (not the same as the
     * auto-generated source-archive URLs, which require an exact tag).
     * .github/workflows/release-assets.yml uploads assets with exactly
     * these two filenames to every published release.
     */
    public function test_latest_version_url_is_the_stable_release_asset_url(): void
    {
        $data = $this->moduleJson();

        $this->assertSame(
            'https://github.com/lusky3/freescout-module-manager/releases/latest/download/version.json',
            $data['latestVersionUrl'] ?? null
        );
    }

    public function test_latest_version_zip_url_is_the_stable_release_asset_url(): void
    {
        $data = $this->moduleJson();

        $this->assertSame(
            'https://github.com/lusky3/freescout-module-manager/releases/latest/download/freescout-module-manager.zip',
            $data['latestVersionZipUrl'] ?? null
        );
    }
}

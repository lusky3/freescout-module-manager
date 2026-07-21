<?php

namespace Tests\Unit;

use Modules\ModuleManager\Services\ZipModuleExtractor;
use PHPUnit\Framework\TestCase;

class ZipModuleExtractorTest extends TestCase
{
    private string $workDir;
    private string $modulesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/zme_test_'.bin2hex(random_bytes(6));
        $this->modulesDir = $this->workDir.'/Modules';
        mkdir($this->modulesDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->workDir);
        parent::tearDown();
    }

    public function test_extracts_a_valid_module_zip_and_returns_success(): void
    {
        $zipPath = $this->buildZip([
            'themes/module.json' => json_encode(['name' => 'Themes', 'alias' => 'themes']),
            'themes/Providers/ThemesServiceProvider.php' => '<?php // stub',
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertTrue($result->success);
        $this->assertSame('themes', $result->alias);
        $this->assertSame('Themes', $result->name);
        $this->assertFileExists($this->modulesDir.'/themes/module.json');
        $this->assertFileExists($this->modulesDir.'/themes/Providers/ThemesServiceProvider.php');
    }

    public function test_extraction_does_not_leave_a_staging_directory_behind(): void
    {
        $zipPath = $this->buildZip([
            'themes/module.json' => json_encode(['name' => 'Themes', 'alias' => 'themes']),
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertTrue($result->success);

        $leftovers = glob($this->modulesDir.'/.staging-*');
        $this->assertSame([], $leftovers, 'No .staging-* directory should remain after a successful extract.');
    }

    public function test_rejects_zip_with_path_traversal_entry(): void
    {
        $zipPath = $this->buildZip([
            'themes/module.json' => json_encode(['name' => 'Themes', 'alias' => 'themes']),
            'themes/../../evil.php' => '<?php echo "pwned"; ?>',
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unsafe path', $result->error);
        $this->assertFileDoesNotExist($this->workDir.'/evil.php');
    }

    /**
     * The leading-slash branch of isUnsafePath() is reachable via a real ZIP
     * built with ZipArchive::addFromString(): empirically (verified against
     * this project's PHP 7.4 / libzip 1.7.3), a leading '/' in an entry name
     * is preserved verbatim on write and on re-read, it is NOT stripped by
     * libzip. So this test exercises the real "leading slash" code path
     * rather than a synthetic one.
     */
    public function test_rejects_zip_with_leading_slash_entry(): void
    {
        $zipPath = $this->buildZip([
            'themes/module.json' => json_encode(['name' => 'Themes', 'alias' => 'themes']),
            '/etc/passwd' => 'evil',
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unsafe path', $result->error);
        $this->assertStringContainsString('/etc/passwd', $result->error);
        $this->assertFileDoesNotExist($this->modulesDir.'/themes');
        $this->assertFileDoesNotExist($this->modulesDir.'/etc');
    }

    public function test_rejects_zip_with_backslash_entry(): void
    {
        $zipPath = $this->buildZip([
            'themes/module.json' => json_encode(['name' => 'Themes', 'alias' => 'themes']),
            'themes/evil\\..\\..\\evil.php' => '<?php echo "pwned"; ?>',
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Unsafe path', $result->error);
        $this->assertFileDoesNotExist($this->workDir.'/evil.php');
    }

    /**
     * PHP's ZipArchive has no direct "add a symlink" API. To exercise the
     * real code path that inspects Unix external file attributes, this
     * fakes the Unix symlink mode bits (S_IFLNK == 0120000) on a regular
     * entry via ZipArchive::setExternalAttributesName(), then closes and
     * reopens the archive so the extractor reads the attributes back from
     * the ZIP's central directory exactly as it would for a real symlink
     * entry produced by another zip tool.
     */
    public function test_rejects_zip_with_a_symlink_entry(): void
    {
        $zipPath = $this->workDir.'/fixture_symlink.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('themes/module.json', json_encode(['name' => 'Themes', 'alias' => 'themes']));
        $zip->addFromString('themes/evil-link', '/etc');
        // S_IFLNK (0120000) | 0777 permissions, shifted into the high 16
        // bits the way Unix-produced ZIPs store external file attributes.
        $mode = 0120777;
        $zip->setExternalAttributesName('themes/evil-link', \ZipArchive::OPSYS_UNIX, $mode << 16);
        $zip->close();

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('symlink', $result->error);
        $this->assertStringContainsString('evil-link', $result->error);
        $this->assertFileDoesNotExist($this->modulesDir.'/themes');
    }

    public function test_rejects_zip_without_module_json(): void
    {
        $zipPath = $this->buildZip([
            'themes/Providers/ThemesServiceProvider.php' => '<?php // stub',
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('module.json', $result->error);
    }

    public function test_rejects_zip_with_multiple_top_level_folders(): void
    {
        $zipPath = $this->buildZip([
            'themes/module.json' => json_encode(['name' => 'Themes', 'alias' => 'themes']),
            'other/file.txt' => 'hello',
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('exactly one top-level folder', $result->error);
    }

    public function test_refuses_to_overwrite_an_existing_module_folder(): void
    {
        mkdir($this->modulesDir.'/themes', 0777, true);
        file_put_contents($this->modulesDir.'/themes/module.json', '{}');

        $zipPath = $this->buildZip([
            'themes/module.json' => json_encode(['name' => 'Themes', 'alias' => 'themes']),
        ]);

        $extractor = new ZipModuleExtractor($this->modulesDir);
        $result = $extractor->extract($zipPath);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('already exists', $result->error);
    }

    /**
     * @param array<string, string> $entries relative path => file contents
     */
    private function buildZip(array $entries): string
    {
        $zipPath = $this->workDir.'/fixture_'.bin2hex(random_bytes(4)).'.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $zipPath;
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}

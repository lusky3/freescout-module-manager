<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\InstallResult;

class ZipModuleExtractor
{
    private string $modulesDir;

    public function __construct(string $modulesDir)
    {
        $this->modulesDir = $modulesDir;
    }

    public function extract(string $zipPath): InstallResult
    {
        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return InstallResult::fail('Could not open ZIP archive.');
        }

        $topFolder = $this->findSingleTopLevelFolder($zip);
        if ($topFolder === null) {
            $zip->close();
            return InstallResult::fail('ZIP must contain exactly one top-level folder.');
        }

        $traversalEntry = $this->findPathTraversalEntry($zip);
        if ($traversalEntry !== null) {
            $zip->close();
            return InstallResult::fail("Unsafe path in ZIP entry: {$traversalEntry}");
        }

        $symlinkEntry = $this->findSymlinkEntry($zip);
        if ($symlinkEntry !== null) {
            $zip->close();
            return InstallResult::fail("Unsafe ZIP entry: '{$symlinkEntry}' is a symlink, which is not allowed.");
        }

        $moduleInfo = $this->readModuleJson($zip, $topFolder);
        if ($moduleInfo === null) {
            $zip->close();
            return InstallResult::fail("ZIP is missing a valid {$topFolder}/module.json with 'name' and 'alias'.");
        }

        $destination = $this->modulesDir.'/'.$topFolder;
        if (is_dir($destination)) {
            $zip->close();
            return InstallResult::fail("A module folder named '{$topFolder}' already exists.");
        }

        $stagingDir = $this->modulesDir.'/.staging-'.bin2hex(random_bytes(8));
        if (!mkdir($stagingDir, 0777, true)) {
            $zip->close();
            return InstallResult::fail('Could not create a staging directory for extraction.');
        }

        $extracted = $zip->extractTo($stagingDir);
        $zip->close();

        if (!$extracted) {
            $this->removeDirectory($stagingDir);
            return InstallResult::fail('Could not extract ZIP archive.');
        }

        $stagedModuleDir = $stagingDir.'/'.$topFolder;
        if (!is_dir($stagedModuleDir)) {
            $this->removeDirectory($stagingDir);
            return InstallResult::fail('Could not extract ZIP archive.');
        }

        // Atomically move the extracted module folder into place. rename() on
        // the same filesystem is atomic and fails if a same-named directory
        // is concurrently created at the destination first, turning a race
        // between two simultaneous installs into a clean failure instead of
        // silently corrupting either module folder.
        if (!@rename($stagedModuleDir, $destination)) {
            $this->removeDirectory($stagingDir);
            return InstallResult::fail("A module folder named '{$topFolder}' already exists.");
        }

        $this->removeDirectory($stagingDir);

        return InstallResult::ok($moduleInfo['alias'], $moduleInfo['name'], $topFolder);
    }

    private function findSingleTopLevelFolder(\ZipArchive $zip): ?string
    {
        $top = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || $name === '') {
                continue;
            }

            $firstSegment = explode('/', $name)[0];
            if ($firstSegment === '') {
                continue;
            }

            if ($top === null) {
                $top = $firstSegment;
            } elseif ($top !== $firstSegment) {
                return null;
            }
        }

        return $top;
    }

    private function findPathTraversalEntry(\ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false) {
                continue;
            }

            if ($this->isUnsafePath($name)) {
                return $name;
            }
        }

        return null;
    }

    private function isUnsafePath(string $name): bool
    {
        if (strpos($name, '/') === 0 || strpos($name, "\\") !== false) {
            return true;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * Detects ZIP entries that carry a Unix "symlink" file-type mode in
     * their external file attributes. ZipArchive::extractTo() extracts
     * entries by name, but a name that passes the traversal checks can
     * still be paired with symlink attributes; if that symlink were ever
     * materialized on disk (directly by extraction, or by a different
     * unzip implementation reading this same archive) and pointed outside
     * modulesDir, a later entry written "through" it could escape the
     * intended destination even though every entry NAME was safe. Reject
     * any such entry outright.
     */
    private function findSymlinkEntry(\ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $opsys = 0;
            $attr = 0;

            if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
                continue;
            }

            if ($opsys !== \ZipArchive::OPSYS_UNIX) {
                continue;
            }

            $mode = ($attr >> 16) & 0xFFFF;
            $fileType = $mode & 0170000;

            if ($fileType === 0120000) {
                $name = $zip->getNameIndex($i);
                return $name !== false ? $name : "entry #{$i}";
            }
        }

        return null;
    }

    private function readModuleJson(\ZipArchive $zip, string $topFolder): ?array
    {
        $contents = $zip->getFromName($topFolder.'/module.json');
        if ($contents === false) {
            return null;
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || empty($data['name']) || empty($data['alias'])) {
            return null;
        }

        return [
            'name' => (string) $data['name'],
            'alias' => (string) $data['alias'],
        ];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}

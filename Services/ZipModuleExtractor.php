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

        if (!$zip->extractTo($this->modulesDir)) {
            $zip->close();
            return InstallResult::fail('Could not extract ZIP archive.');
        }

        $zip->close();

        return InstallResult::ok($moduleInfo['alias'], $moduleInfo['name']);
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
}

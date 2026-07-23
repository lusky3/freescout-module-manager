<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\CatalogEntry;

class CatalogLoader
{
    private string $catalogPath;

    public function __construct(string $catalogPath)
    {
        $this->catalogPath = $catalogPath;
    }

    /**
     * @return CatalogEntry[]
     */
    public function all(): array
    {
        if (!is_file($this->catalogPath)) {
            return [];
        }

        $contents = file_get_contents($this->catalogPath);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $index => $rawEntry) {
            if (!is_array($rawEntry)) {
                error_log("ModuleManager: skipping malformed catalog entry at index {$index}: not an object");
                continue;
            }

            $entry = CatalogEntry::fromArray($rawEntry);
            if ($entry === null) {
                error_log('ModuleManager: skipping malformed catalog entry: ' . json_encode($rawEntry));
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }
}

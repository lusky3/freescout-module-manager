<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\OptionStoreInterface;

class DefaultRepoSeeder
{
    public const SEEDED_OPTION_KEY = 'modulemanager.default_repos_seeded';

    private SavedRepoStore $repoStore;
    private OptionStoreInterface $options;
    private string $defaultsPath;

    public function __construct(SavedRepoStore $repoStore, OptionStoreInterface $options, string $defaultsPath)
    {
        $this->repoStore = $repoStore;
        $this->options = $options;
        $this->defaultsPath = $defaultsPath;
    }

    public function seedIfNeeded(): void
    {
        if ($this->options->get(self::SEEDED_OPTION_KEY, false)) {
            return;
        }

        foreach ($this->loadDefaults() as $entry) {
            $this->repoStore->add($entry['owner'], $entry['repo'], $entry['ref'], $entry['label']);
        }

        $this->options->set(self::SEEDED_OPTION_KEY, true);
    }

    private function loadDefaults(): array
    {
        if (!is_file($this->defaultsPath)) {
            return [];
        }

        $data = json_decode(file_get_contents($this->defaultsPath), true);

        return is_array($data) ? $data : [];
    }
}

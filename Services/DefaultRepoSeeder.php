<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\OptionStoreInterface;

class DefaultRepoSeeder
{
    public const SEEDED_OPTION_KEY = 'modulemanager.default_repos_seeded';

    private const REQUIRED_KEYS = ['owner', 'repo', 'ref', 'label'];

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
            if (!$this->isValidEntry($entry)) {
                // This class is deliberately framework-independent (no Laravel
                // facades, testable via plain PHPUnit), so we can't reach for
                // Illuminate\Support\Facades\Log here. error_log() is the
                // dependency-free way to leave a trace for whoever hand-edits
                // default-repos.json (see README) and gets a malformed entry.
                error_log(sprintf(
                    'ModuleManager: skipping malformed default-repos.json entry: %s',
                    json_encode($entry)
                ));
                continue;
            }

            $this->repoStore->add($entry['owner'], $entry['repo'], $entry['ref'], $entry['label']);
        }

        $this->options->set(self::SEEDED_OPTION_KEY, true);
    }

    /**
     * @param mixed $entry
     */
    private function isValidEntry($entry): bool
    {
        if (!is_array($entry)) {
            return false;
        }

        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($entry[$key]) || !is_string($entry[$key]) || $entry[$key] === '') {
                return false;
            }
        }

        return true;
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

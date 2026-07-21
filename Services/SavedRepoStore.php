<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\OptionStoreInterface;

class SavedRepoStore
{
    public const OPTION_KEY = 'modulemanager.saved_repos';

    private OptionStoreInterface $options;

    public function __construct(OptionStoreInterface $options)
    {
        $this->options = $options;
    }

    public function all(): array
    {
        $raw = $this->options->get(self::OPTION_KEY, []);

        return is_array($raw) ? $raw : [];
    }

    public function add(string $owner, string $repo, string $ref, string $label): array
    {
        $entries = $this->all();

        $entry = [
            'id' => bin2hex(random_bytes(8)),
            'owner' => $owner,
            'repo' => $repo,
            'ref' => $ref,
            'label' => $label,
        ];

        $entries[] = $entry;
        $this->options->set(self::OPTION_KEY, $entries);

        return $entry;
    }

    public function remove(string $id): bool
    {
        $entries = $this->all();
        $filtered = array_values(array_filter($entries, fn (array $entry) => $entry['id'] !== $id));

        if (count($filtered) === count($entries)) {
            return false;
        }

        $this->options->set(self::OPTION_KEY, $filtered);

        return true;
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }
}

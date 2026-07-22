<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\OptionStoreInterface;
use Modules\ModuleManager\Services\Support\SavedRepo;

class SavedRepoStore
{
    public const OPTION_KEY = 'modulemanager.saved_repos';

    private OptionStoreInterface $options;

    private string $lockFilePath;

    /**
     * $lockFilePath is accepted (rather than hardcoded) because this class
     * is deliberately framework-independent -- no Laravel helpers like
     * storage_path() -- so it stays constructible/testable standalone. The
     * default under sys_get_temp_dir() is only meant for that standalone/test
     * use; real usage should pass an explicit path (the ServiceProvider's
     * container binding passes storage_path('app/modulemanager/saved_repos.lock')).
     */
    public function __construct(OptionStoreInterface $options, ?string $lockFilePath = null)
    {
        $this->options = $options;
        $this->lockFilePath = $lockFilePath ?? sys_get_temp_dir() . '/modulemanager_saved_repos.lock';
    }

    /** @return SavedRepo[] */
    public function all(): array
    {
        $repos = [];

        foreach ($this->allRaw() as $entryData) {
            if (!is_array($entryData)) {
                continue;
            }

            $repo = SavedRepo::fromArray($entryData);
            if ($repo !== null) {
                $repos[] = $repo;
            }
        }

        return $repos;
    }

    public function add(string $owner, string $repo, string $ref, string $label): SavedRepo
    {
        return $this->withLock(function () use ($owner, $repo, $ref, $label) {
            $entries = $this->allRaw();

            $newRepo = new SavedRepo(bin2hex(random_bytes(8)), $owner, $repo, $ref, $label);

            $entries[] = $newRepo->toArray();
            $this->options->set(self::OPTION_KEY, $entries);

            return $newRepo;
        });
    }

    public function remove(string $id): bool
    {
        return $this->withLock(function () use ($id) {
            $entries = $this->allRaw();

            $filtered = array_values(array_filter($entries, function ($entry) use ($id) {
                return !(is_array($entry) && ($entry['id'] ?? null) === $id);
            }));

            if (count($filtered) === count($entries)) {
                return false;
            }

            $this->options->set(self::OPTION_KEY, $filtered);

            return true;
        });
    }

    public function find(string $id): ?SavedRepo
    {
        foreach ($this->all() as $repo) {
            if ($repo->id === $id) {
                return $repo;
            }
        }

        return null;
    }

    public function markInstalled(string $id, string $alias, string $folder): bool
    {
        return $this->withLock(function () use ($id, $alias, $folder) {
            $entries = $this->allRaw();
            $found = false;

            foreach ($entries as &$entry) {
                if (is_array($entry) && ($entry['id'] ?? null) === $id) {
                    $entry['installed_alias'] = $alias;
                    $entry['installed_folder'] = $folder;
                    $found = true;
                    break;
                }
            }
            unset($entry);

            if (!$found) {
                return false;
            }

            $this->options->set(self::OPTION_KEY, $entries);

            return true;
        });
    }

    private function allRaw(): array
    {
        $raw = $this->options->get(self::OPTION_KEY, []);

        return is_array($raw) ? $raw : [];
    }

    /**
     * Serializes add()/remove()/markInstalled() against each other with an
     * application-level file lock. Option::get()/set() (FreeScout core) does
     * an unlocked read-modify-write of the *entire* saved_repos array with no
     * locking of its own, so two near-simultaneous requests -- necessarily
     * separate PHP-FPM worker processes, not just separate threads of one
     * process -- can each read the array, then write back their own modified
     * copy, and the second write silently clobbers the first's change. An
     * in-process mutex wouldn't help across worker processes; flock() over a
     * shared lock file does.
     *
     * @return mixed
     */
    private function withLock(callable $callback)
    {
        $lockHandle = @fopen($this->lockFilePath, 'c');

        if ($lockHandle === false) {
            // Fall back to running unlocked rather than fatally erroring the
            // whole request if the lock file's directory isn't writable.
            // This preserves the pre-existing (unlocked) behavior in that
            // edge case instead of introducing a brand new failure mode.
            return $callback();
        }

        try {
            flock($lockHandle, LOCK_EX);

            return $callback();
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}

<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\FileLockable;
use Modules\ModuleManager\Services\Support\OptionStoreInterface;
use Modules\ModuleManager\Services\Support\SavedRepo;
use Modules\ModuleManager\Services\Support\UpdateTarget;

class SavedRepoStore
{
    use FileLockable;

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
        return $this->withLock($this->lockFilePath, function () use ($owner, $repo, $ref, $label) {
            $entries = $this->allRaw();

            $newRepo = new SavedRepo(bin2hex(random_bytes(8)), $owner, $repo, $ref, $label);

            $entries[] = $newRepo->toArray();
            $this->options->set(self::OPTION_KEY, $entries);

            return $newRepo;
        });
    }

    public function remove(string $id): bool
    {
        return $this->withLock($this->lockFilePath, function () use ($id) {
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
        return $this->withLock($this->lockFilePath, function () use ($id, $alias, $folder) {
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

    /**
     * Caches the result of an UpdateChecker::findLatest() call against $id,
     * without touching what's actually installed -- used by the "Check for
     * Updates" button, which only refreshes the badge shown in the UI.
     */
    public function recordUpdateCheck(string $id, UpdateTarget $target, string $checkedAt): bool
    {
        return $this->withLock($this->lockFilePath, function () use ($id, $target, $checkedAt) {
            $entries = $this->allRaw();
            $found = false;

            foreach ($entries as &$entry) {
                if (is_array($entry) && ($entry['id'] ?? null) === $id) {
                    $entry['latest_known_ref'] = $target->ref;
                    $entry['latest_known_label'] = $target->label;
                    $entry['latest_known_url'] = $target->url;
                    $entry['latest_checked_at'] = $checkedAt;
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

    /**
     * Records that $id was just successfully re-installed at $target --
     * used after a successful "Update" install. Unlike recordUpdateCheck(),
     * this also updates what's actually installed: in tag mode, 'ref'
     * becomes the newly-installed tag (the version marker for that mode)
     * and 'installed_commit_sha' is cleared; in commit mode, 'ref' (the
     * tracked branch *name*) is left alone and 'installed_commit_sha'
     * becomes the newly-installed commit. Either way, latest_known_* is
     * also refreshed to match $target, so SavedRepo::isUpdateAvailable()
     * immediately reports "up to date" rather than "not checked" right
     * after the update.
     */
    public function markUpdated(string $id, UpdateTarget $target, string $checkedAt): bool
    {
        return $this->withLock($this->lockFilePath, function () use ($id, $target, $checkedAt) {
            $entries = $this->allRaw();
            $found = false;

            foreach ($entries as &$entry) {
                if (is_array($entry) && ($entry['id'] ?? null) === $id) {
                    if ($target->mode === UpdateTarget::MODE_TAG) {
                        $entry['ref'] = $target->ref;
                        $entry['installed_commit_sha'] = null;
                    } else {
                        $entry['installed_commit_sha'] = $target->ref;
                    }

                    $entry['latest_known_ref'] = $target->ref;
                    $entry['latest_known_label'] = $target->label;
                    $entry['latest_known_url'] = $target->url;
                    $entry['latest_checked_at'] = $checkedAt;
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
     * application-level file lock (see FileLockable::withLock()).
     * Option::get()/set() (FreeScout core) does an unlocked read-modify-write
     * of the *entire* saved_repos array with no locking of its own, so two
     * near-simultaneous requests -- necessarily separate PHP-FPM worker
     * processes, not just separate threads of one process -- can each read
     * the array, then write back their own modified copy, and the second
     * write silently clobbers the first's change. An in-process mutex
     * wouldn't help across worker processes; flock() over a shared lock file
     * does.
     */
}

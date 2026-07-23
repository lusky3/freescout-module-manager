<?php

namespace Modules\ModuleManager\Services;

use Modules\ModuleManager\Services\Support\FileLockable;
use Modules\ModuleManager\Services\Support\OptionStoreInterface;

class DefaultRepoSeeder
{
    use FileLockable;

    public const SEEDED_OPTION_KEY = 'modulemanager.default_repos_seeded';

    private const REQUIRED_KEYS = ['owner', 'repo', 'ref', 'label'];

    private SavedRepoStore $repoStore;
    private OptionStoreInterface $options;
    private string $defaultsPath;
    private string $lockFilePath;

    /**
     * $lockFilePath is accepted (rather than hardcoded) for the same reason
     * as SavedRepoStore's: this class is deliberately framework-independent
     * -- no Laravel helpers like storage_path() -- so it stays
     * constructible/testable standalone. The default under
     * sys_get_temp_dir() is only meant for that standalone/test use; real
     * usage should pass an explicit path (the ServiceProvider's container
     * binding passes storage_path('app/modulemanager/default_repos_seed.lock')).
     *
     * This is deliberately its own lock file, separate from
     * SavedRepoStore's saved_repos.lock -- seeding and the store's own
     * add()/remove()/markInstalled() mutations are unrelated concerns, and
     * sharing a lock file would create pointless contention between them.
     */
    public function __construct(
        SavedRepoStore $repoStore,
        OptionStoreInterface $options,
        string $defaultsPath,
        ?string $lockFilePath = null
    ) {
        $this->repoStore = $repoStore;
        $this->options = $options;
        $this->defaultsPath = $defaultsPath;
        $this->lockFilePath = $lockFilePath ?? sys_get_temp_dir() . '/modulemanager_default_repos_seed.lock';
    }

    /**
     * Checking the seeded flag and (if unset) seeding + setting it is a
     * check-then-act sequence. This is invoked from the settings-page view
     * composer on every render, so two near-simultaneous first page loads
     * (two admins, two browser tabs) are two separate PHP-FPM worker
     * processes both reaching this method around the same time. Without a
     * lock, both can read the flag as false before either writes it back as
     * true, and both then seed every entry from default-repos.json,
     * producing duplicate saved-repo rows. Wrapping the whole
     * check-then-act in a file lock (see FileLockable::withLock()) makes it
     * atomic across processes, the same way SavedRepoStore serializes its
     * own mutations.
     */
    public function seedIfNeeded(): void
    {
        $this->withLock($this->lockFilePath, function () {
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
        });
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

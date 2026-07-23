<?php

namespace Modules\ModuleManager\Services\Support;

/**
 * Shared flock()-based critical section helper for the framework-independent
 * Services classes (SavedRepoStore, DefaultRepoSeeder) that need to
 * serialize a check-then-act sequence across separate PHP-FPM worker
 * processes. Deliberately has no Laravel dependency -- these classes stay
 * constructible/testable with plain PHPUnit.
 */
trait FileLockable
{
    /**
     * Runs $callback with an exclusive flock() held on $lockFilePath.
     *
     * Best-effort creates the lock file's parent directory first (mirroring
     * ModuleManagerController::ensureStorageDir()'s mkdir pattern) rather
     * than silently degrading to unlocked just because the directory
     * doesn't exist yet -- the ServiceProvider also creates it eagerly at
     * boot, but this is a defensive second line in case that hasn't run
     * (e.g. a directly-constructed instance in a non-Laravel context, or
     * the directory having been removed after boot).
     *
     * If the lock file still can't be opened (e.g. a genuine permissions
     * problem), falls back to running $callback unlocked -- rather than
     * fatally erroring the whole request -- but leaves a trace via
     * error_log() so the degraded-locking condition is observable instead
     * of purely silent. error_log() (not the Log facade) because this trait
     * is used by classes that are deliberately framework-independent.
     *
     * @return mixed
     */
    private function withLock(string $lockFilePath, callable $callback)
    {
        $lockDir = dirname($lockFilePath);

        if (!is_dir($lockDir)) {
            @mkdir($lockDir, 0775, true);
        }

        $lockHandle = @fopen($lockFilePath, 'c');

        if ($lockHandle === false) {
            error_log(sprintf(
                'ModuleManager: could not open lock file "%s"; proceeding unlocked (degraded concurrency safety).',
                $lockFilePath
            ));

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

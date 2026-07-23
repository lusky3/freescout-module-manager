<?php

/**
 * CLI worker used only by
 * DefaultRepoSeederTest::test_two_seeders_sharing_an_option_store_do_not_both_seed_when_racing_across_processes().
 *
 * Constructs a DefaultRepoSeeder wired to a JSON-file-backed OptionStore
 * shared (by path) with a sibling process, and calls seedIfNeeded(). A
 * deliberate delay is injected right after the seeded-flag is read but
 * before DefaultRepoSeeder acts on it, forcing a wide, deterministic race
 * window between the two worker processes -- reproducing (without relying
 * on incidental OS scheduling luck, which would make the test flaky) the
 * exact "two near-simultaneous processes both observe seeded=false" race
 * described in the HIGH finding this test guards against.
 *
 * argv: <projectRoot> <optionsFilePath> <defaultsFilePath> <seederLockPath> <repoLockPath> <sleepMicroseconds>
 */

[, $projectRoot, $optionsPath, $defaultsPath, $seederLockPath, $repoLockPath, $sleepMicroseconds] = $argv;

require $projectRoot . '/vendor/autoload.php';

use Modules\ModuleManager\Services\DefaultRepoSeeder;
use Modules\ModuleManager\Services\SavedRepoStore;
use Modules\ModuleManager\Services\Support\OptionStoreInterface;
use Tests\Fixtures\FileBackedOptionStore;

$fileBackedOptions = new FileBackedOptionStore($optionsPath);
$sleepMicroseconds = (int) $sleepMicroseconds;

$racingOptions = new class ($fileBackedOptions, $sleepMicroseconds) implements OptionStoreInterface {
    private OptionStoreInterface $inner;
    private int $sleepMicroseconds;

    public function __construct(OptionStoreInterface $inner, int $sleepMicroseconds)
    {
        $this->inner = $inner;
        $this->sleepMicroseconds = $sleepMicroseconds;
    }

    public function get(string $key, $default = null)
    {
        $value = $this->inner->get($key, $default);

        if ($key === DefaultRepoSeeder::SEEDED_OPTION_KEY) {
            usleep($this->sleepMicroseconds);
        }

        return $value;
    }

    public function set(string $key, $value): void
    {
        $this->inner->set($key, $value);
    }
};

$repoStore = new SavedRepoStore($racingOptions, $repoLockPath);
$seeder = new DefaultRepoSeeder($repoStore, $racingOptions, $defaultsPath, $seederLockPath);

$seeder->seedIfNeeded();

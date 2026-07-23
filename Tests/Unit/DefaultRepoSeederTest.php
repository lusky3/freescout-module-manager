<?php

namespace Tests\Unit;

use Modules\ModuleManager\Services\DefaultRepoSeeder;
use Modules\ModuleManager\Services\SavedRepoStore;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\FakeOptionStore;

class DefaultRepoSeederTest extends TestCase
{
    private string $defaultsPath;
    private string $malformedDefaultsPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultsPath = sys_get_temp_dir() . '/default_repos_' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($this->defaultsPath, json_encode([
            ['owner' => 'nielspeen', 'repo' => 'AiAssistant', 'ref' => 'main', 'label' => 'AI Assistant'],
        ]));

        $this->malformedDefaultsPath = sys_get_temp_dir() . '/default_repos_malformed_' . bin2hex(random_bytes(4)) . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->defaultsPath);
        @unlink($this->malformedDefaultsPath);
        parent::tearDown();
    }

    public function test_seeds_saved_repos_from_defaults_file_on_first_run(): void
    {
        $options = new FakeOptionStore();
        $repoStore = new SavedRepoStore($options);
        $seeder = new DefaultRepoSeeder($repoStore, $options, $this->defaultsPath);

        $seeder->seedIfNeeded();

        $this->assertCount(1, $repoStore->all());
        $this->assertSame('nielspeen', $repoStore->all()[0]->owner);
    }

    public function test_does_not_reseed_after_first_run(): void
    {
        $options = new FakeOptionStore();
        $repoStore = new SavedRepoStore($options);
        $seeder = new DefaultRepoSeeder($repoStore, $options, $this->defaultsPath);

        $seeder->seedIfNeeded();
        $repoStore->remove($repoStore->all()[0]->id);
        $seeder->seedIfNeeded();

        $this->assertCount(0, $repoStore->all());
    }

    public function test_skips_malformed_entries_without_throwing(): void
    {
        file_put_contents($this->malformedDefaultsPath, json_encode([
            ['owner' => 'nielspeen', 'repo' => 'AiAssistant', 'ref' => 'main', 'label' => 'AI Assistant'],
            // Missing 'label' entirely -- would previously trigger an
            // uncaught TypeError in SavedRepoStore::add() (non-nullable
            // string params) and 500 the settings page on every render.
            ['owner' => 'someoneelse', 'repo' => 'BadModule', 'ref' => 'main'],
            // Present but empty-string 'ref' should also be rejected.
            ['owner' => 'another', 'repo' => 'AlsoBad', 'ref' => '', 'label' => 'Also Bad'],
        ]));

        $options = new FakeOptionStore();
        $repoStore = new SavedRepoStore($options);
        $seeder = new DefaultRepoSeeder($repoStore, $options, $this->malformedDefaultsPath);

        $seeder->seedIfNeeded();

        $all = $repoStore->all();
        $this->assertCount(1, $all);
        $this->assertSame('nielspeen', $all[0]->owner);
        $this->assertTrue($options->get(DefaultRepoSeeder::SEEDED_OPTION_KEY, false));
    }

    /**
     * Regression test for the HIGH finding: two near-simultaneous first
     * page loads (two admins, two browser tabs) are two separate PHP-FPM
     * worker processes, not just two objects in one process. A single
     * PHPUnit process can't reproduce that with two plain PHP objects --
     * they'd share the same in-memory FakeOptionStore and the same
     * call stack, so the check-then-act gap this bug lives in never
     * actually opens up. This spins up two *real* separate PHP processes
     * (Tests/Fixtures/seed_race_worker.php) sharing one JSON-file-backed
     * OptionStore (Tests/Fixtures/FileBackedOptionStore) and one seeder
     * lock file path, with a deliberate delay injected right after the
     * seeded-flag read (so the race window is wide and deterministic
     * rather than dependent on incidental OS scheduling luck) and proves
     * only one of them actually seeds.
     */
    public function test_two_seeders_sharing_an_option_store_do_not_both_seed_when_racing_across_processes(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $workerScript = __DIR__ . '/../Fixtures/seed_race_worker.php';

        $optionsPath = sys_get_temp_dir() . '/default_repos_race_options_' . bin2hex(random_bytes(4)) . '.json';
        $seederLockPath = sys_get_temp_dir() . '/default_repos_race_seed_' . bin2hex(random_bytes(4)) . '.lock';
        $repoLockPath = sys_get_temp_dir() . '/default_repos_race_repo_' . bin2hex(random_bytes(4)) . '.lock';
        $sleepMicroseconds = 200000; // 200ms: wide enough to reliably straddle two process starts.

        $command = [
            PHP_BINARY,
            $workerScript,
            $projectRoot,
            $optionsPath,
            $this->defaultsPath,
            $seederLockPath,
            $repoLockPath,
            (string) $sleepMicroseconds,
        ];

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        // Launched back-to-back, without waiting on either in between, so
        // both worker processes are genuinely racing each other through
        // DefaultRepoSeeder::seedIfNeeded() at roughly the same time.
        $processA = proc_open($command, $descriptors, $pipesA);
        $processB = proc_open($command, $descriptors, $pipesB);

        $this->assertIsResource($processA);
        $this->assertIsResource($processB);

        fclose($pipesA[0]);
        fclose($pipesB[0]);
        $stderrA = stream_get_contents($pipesA[2]);
        $stderrB = stream_get_contents($pipesB[2]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);

        $exitA = proc_close($processA);
        $exitB = proc_close($processB);

        $this->assertSame(0, $exitA, "seed_race_worker.php (process A) failed: {$stderrA}");
        $this->assertSame(0, $exitB, "seed_race_worker.php (process B) failed: {$stderrB}");

        $finalOptions = new \Tests\Fixtures\FileBackedOptionStore($optionsPath);
        $finalRepoStore = new SavedRepoStore($finalOptions, $repoLockPath);

        $all = $finalRepoStore->all();
        $this->assertCount(1, $all, 'Two racing seeders both seeded -- the seeder-level lock did not serialize them.');
        $this->assertSame('nielspeen', $all[0]->owner);
        $this->assertTrue($finalOptions->get(DefaultRepoSeeder::SEEDED_OPTION_KEY, false));

        @unlink($optionsPath);
        @unlink($seederLockPath);
        @unlink($repoLockPath);
    }
}

<?php

namespace Modules\ModuleManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;
use Modules\ModuleManager\Services\GithubRepoFetcher;
use Modules\ModuleManager\Services\GithubRepoResolver;
use Modules\ModuleManager\Services\SavedRepoStore;
use Modules\ModuleManager\Services\Support\InstallResult;
use Modules\ModuleManager\Services\Support\SavedRepo;
use Modules\ModuleManager\Services\Support\UpdateTarget;
use Modules\ModuleManager\Services\UpdateChecker;
use Modules\ModuleManager\Services\ZipModuleExtractor;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class ModuleManagerController extends Controller
{
    /** Maximum accepted upload size for a module ZIP, in kilobytes (50MB). Bump here if larger modules are legitimate. */
    private const MAX_UPLOAD_KB = 51200;

    private SavedRepoStore $repoStore;

    private GithubRepoFetcher $githubFetcher;

    private GithubRepoResolver $githubResolver;

    private ZipModuleExtractor $extractor;

    private UpdateChecker $updateChecker;

    public function __construct(
        SavedRepoStore $repoStore,
        GithubRepoFetcher $githubFetcher,
        GithubRepoResolver $githubResolver,
        ZipModuleExtractor $extractor,
        UpdateChecker $updateChecker
    ) {
        $this->repoStore = $repoStore;
        $this->githubFetcher = $githubFetcher;
        $this->githubResolver = $githubResolver;
        $this->extractor = $extractor;
        $this->updateChecker = $updateChecker;

        // Structural admin gate: every action on this controller requires
        // it, so there is no method (present or future) that can forget to
        // call it -- unlike a manual $this->authorizeAdmin() call repeated
        // in each action.
        $this->middleware(function ($request, $next) {
            if (!Auth::user() || !Auth::user()->isAdmin()) {
                abort(403);
            }

            return $next($request);
        });
    }

    public function addRepo(Request $request)
    {
        $request->validate([
            'owner' => 'required|string|max:100',
            'repo' => 'required|string|max:100',
            'ref' => 'required|string|max:100',
            'label' => 'required|string|max:150',
        ]);

        $this->repoStore->add(
            $request->input('owner'),
            $request->input('repo'),
            $request->input('ref'),
            $request->input('label'),
        );

        return redirect()->back()->with('success', __('Repository added.'));
    }

    /**
     * Second, easier way to add a saved repo: paste a GitHub URL instead of
     * filling in owner/repo/ref/label by hand. Resolves the URL against the
     * real GitHub API (GithubRepoResolver::resolve()) and saves whatever it
     * finds -- same storage, same success flash, same redirect target as
     * addRepo() above, just a different (and for the common case, easier)
     * way to arrive at the same four values.
     */
    public function addRepoFromUrl(Request $request)
    {
        $request->validate([
            'github_url' => 'required|string|max:500',
        ]);

        try {
            $resolved = $this->githubResolver->resolve($request->input('github_url'));
        } catch (GithubDownloadException $e) {
            // withInput(): unlike the $request->validate() failure above (which
            // Laravel's own exception handler auto-flashes input for), this is
            // a manually-caught exception -- redirect()->back() alone would
            // otherwise silently drop the URL the admin just pasted, forcing
            // them to retype it just to see/fix the error.
            return redirect()->back()->withInput()->withErrors(['github_url' => $e->getMessage()]);
        }

        $this->repoStore->add(
            $resolved['owner'],
            $resolved['repo'],
            $resolved['ref'],
            $resolved['label'],
        );

        return redirect()->back()->with('success', __('Repository added.'));
    }

    public function removeRepo(string $id)
    {
        $this->repoStore->remove($id);

        return redirect()->back()->with('success', __('Repository removed.'));
    }

    public function installFromRepo(string $id)
    {
        $entry = $this->repoStore->find($id);

        if (!$entry) {
            return redirect()->back()->withErrors(['install' => __('Saved repository not found.')]);
        }

        $storageDir = $this->ensureStorageDir();
        // Random suffix (same pattern as installFromUpload()'s upload_*
        // filename) rather than a fixed name derived from $entry->id: two
        // concurrent installs of the same saved repo (double-click, two
        // admins) would otherwise write to and read from the identical
        // file, and the unconditional cleanup unlink() below could delete
        // one request's file out from under the other's still-in-flight
        // download or extraction.
        $zipName = 'repo_' . $entry->id . '_' . bin2hex(random_bytes(6)) . '.zip';
        $zipPath = $storageDir . '/' . $zipName;

        try {
            $this->githubFetcher->download($entry->owner, $entry->repo, $entry->ref, $zipPath);
        } catch (GithubDownloadException $e) {
            @unlink($zipPath);
            Log::warning('ModuleManager GitHub download failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['install' => $e->getMessage()]);
        }

        return $this->installFromZip($zipPath, function (InstallResult $result) use ($entry) {
            $this->afterSuccessfulInstall($result, $entry->id);

            try {
                $target = $this->updateChecker->findLatest($entry->owner, $entry->repo, $entry->ref);
                $this->repoStore->markUpdated($entry->id, $target, now()->toIso8601String());
            } catch (GithubDownloadException $e) {
                // Best-effort: failing to resolve the installed version shouldn't fail
                // the install itself. The saved repo will just show "Not checked yet"
                // until the next explicit "Check for Updates" click.
                Log::warning('ModuleManager could not resolve installed version after install: ' . $e->getMessage());
            }

            return redirect()->back()
                ->with('success', __('Installed module: :name', ['name' => $result->name]))
                ->with('warning', __('Please enable the module from the Modules page.'));
        });
    }

    public function installFromUpload(Request $request)
    {
        $request->validate([
            // 'max' for file rules is in kilobytes; 51200 KB = 50MB. Adjust MAX_UPLOAD_KB above if this needs to change.
            'module_zip' => 'required|file|mimes:zip|max:' . self::MAX_UPLOAD_KB,
        ]);

        $storageDir = $this->ensureStorageDir();

        $uploaded = $request->file('module_zip');
        $zipName = 'upload_' . bin2hex(random_bytes(6)) . '.zip';

        try {
            $uploaded->move($storageDir, $zipName);
        } catch (FileException $e) {
            Log::warning('ModuleManager upload move failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['module_zip' => __('Could not save the uploaded file.')]);
        }

        return $this->installFromZip($storageDir . '/' . $zipName, function (InstallResult $result) {
            $this->afterSuccessfulInstall($result, null);

            return redirect()->back()
                ->with('success', __('Installed module: :name', ['name' => $result->name]))
                ->with('warning', __('Please enable the module from the Modules page.'));
        });
    }

    public function checkForUpdate(string $id)
    {
        $entry = $this->repoStore->find($id);

        if (!$entry) {
            return redirect()->back()->withErrors(['update' => __('Saved repository not found.')]);
        }

        try {
            $target = $this->updateChecker->findLatest($entry->owner, $entry->repo, $entry->ref);
        } catch (GithubDownloadException $e) {
            Log::warning('ModuleManager update check failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['update' => $e->getMessage()]);
        }

        $this->repoStore->recordUpdateCheck($entry->id, $target, now()->toIso8601String());

        return redirect()->back()->with('success', __('Checked :label for updates.', ['label' => $entry->label]));
    }

    public function updateRepo(string $id)
    {
        $entry = $this->repoStore->find($id);

        if (!$entry) {
            return redirect()->back()->withErrors(['update' => __('Saved repository not found.')]);
        }

        // Always re-checks fresh rather than trusting a possibly-stale
        // cached badge: this is a mutating action (re-downloads and
        // extracts over the currently-installed module), so it installs
        // whatever GitHub reports as current *right now*, not whatever was
        // true the last time someone clicked "Check for Updates".
        try {
            $target = $this->updateChecker->findLatest($entry->owner, $entry->repo, $entry->ref);
        } catch (GithubDownloadException $e) {
            Log::warning('ModuleManager update check failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['update' => $e->getMessage()]);
        }

        $storageDir = $this->ensureStorageDir();
        $zipName = 'update_' . $entry->id . '_' . bin2hex(random_bytes(6)) . '.zip';
        $zipPath = $storageDir . '/' . $zipName;

        try {
            // $target->ref works here whether it's a tag name or a full
            // commit SHA -- GithubRepoFetcher::buildZipUrl() just
            // rawurlencode()s whatever ref string it's given, and GitHub's
            // archive endpoint accepts a commit SHA the same way it
            // accepts a branch or tag name.
            $this->githubFetcher->download($entry->owner, $entry->repo, $target->ref, $zipPath);
        } catch (GithubDownloadException $e) {
            @unlink($zipPath);
            Log::warning('ModuleManager update download failed: ' . $e->getMessage());
            return redirect()->back()->withErrors(['update' => $e->getMessage()]);
        }

        return $this->installFromZip($zipPath, function (InstallResult $result) use ($entry, $target) {
            $this->afterSuccessfulUpdate($result, $entry, $target);

            return redirect()->back()->with('success', __('Updated :name to :label.', ['name' => $entry->label, 'label' => $target->label]));
        });
    }

    private function afterSuccessfulUpdate(InstallResult $result, SavedRepo $entry, UpdateTarget $target): void
    {
        if ($result->folder !== null) {
            $this->repoStore->markInstalled($entry->id, $result->alias, $result->folder);
        }

        $this->repoStore->markUpdated($entry->id, $target, now()->toIso8601String());

        $this->clearCaches();

        Log::info('ModuleManager updated module: ' . $result->alias . ' to ' . $target->ref);
    }

    /**
     * Extracts the ZIP at $zipPath and hands the resulting InstallResult to
     * $onSuccess, which builds the final response -- what "success" means
     * (which saved-repo bookkeeping to update, which flash message to
     * show) differs per caller. Extraction failure and cleanup are handled
     * once, here, regardless of which caller is asking.
     */
    private function installFromZip(string $zipPath, callable $onSuccess)
    {
        $result = $this->extractor->extract($zipPath);

        @unlink($zipPath);

        if (!$result->success) {
            return redirect()->back()->withErrors(['module' => $result->error]);
        }

        return $onSuccess($result);
    }

    private function afterSuccessfulInstall(InstallResult $result, ?string $savedRepoId): void
    {
        if ($savedRepoId !== null && $result->folder !== null) {
            $this->repoStore->markInstalled($savedRepoId, $result->alias, $result->folder);
        }

        $this->clearCaches();

        Log::info('ModuleManager installed module: ' . $result->alias);
    }

    private function ensureStorageDir(): string
    {
        $storageDir = storage_path('app/modulemanager');

        if (!File::isDirectory($storageDir)) {
            if (!File::makeDirectory($storageDir, 0775, true) && !File::isDirectory($storageDir)) {
                throw new \RuntimeException("Could not create storage directory: {$storageDir}");
            }
        }

        return $storageDir;
    }

    private function clearCaches(): void
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('view:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
        } catch (\Throwable $e) {
            Log::warning('ModuleManager cache clear failed: ' . $e->getMessage());
        }
    }
}

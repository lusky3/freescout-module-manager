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
                // Deliberately resolves $entry->ref's own current commit
                // (what was actually just downloaded and extracted), not
                // findLatest()'s "what's newest" answer -- those are
                // different questions, and conflating them here previously
                // caused a plain Install to silently rewrite a tag-tracked
                // repo's saved ref to whatever release happened to be
                // latest, and to record a commit-tracked repo's SHA against
                // the wrong branch (whichever findLatest() preferred, not
                // $entry->ref). Always recorded as MODE_COMMIT: markUpdated()
                // in that mode only ever touches installed_commit_sha, never
                // $entry->ref, so the ref the admin configured is never
                // touched by an Install click.
                $installedSha = $this->updateChecker->resolveCommit($entry->owner, $entry->repo, $entry->ref);
                $target = new UpdateTarget(
                    UpdateTarget::MODE_COMMIT,
                    $installedSha,
                    'commit ' . substr($installedSha, 0, 7) . " on {$entry->ref}",
                    null
                );
                $this->repoStore->markUpdated($entry->id, $target, now()->toIso8601String());
            } catch (GithubDownloadException $e) {
                // Best-effort: failing to resolve the installed commit shouldn't fail
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

        $target = $this->normalizeTargetForEntry($entry, $target);

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

        $target = $this->normalizeTargetForEntry($entry, $target);

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
        // Captured before markInstalled() below overwrites installed_folder
        // with the new one -- this is the only place that still knows what
        // folder the previous version lived in.
        $previousFolder = $entry->installedFolder;

        if ($result->folder !== null) {
            $this->repoStore->markInstalled($entry->id, $result->alias, $result->folder);
        }

        $this->repoStore->markUpdated($entry->id, $target, now()->toIso8601String());

        // ZipModuleExtractor derives its destination folder from the ZIP's
        // own top-level folder name ("{repo}-{ref}" for a GitHub archive),
        // so updating to a different ref extracts into a DIFFERENT folder
        // than the one already installed rather than overwriting it. Left
        // alone, the old folder becomes an orphan that FreeScout's own
        // module scanner -- which keys modules by module.json's "name"
        // field and lets the last directory glob() happens to return win --
        // can silently keep loading instead of the new one, while this
        // module's own UI reports the update as successful. Runs only after
        // the new folder has already been extracted and the store updated,
        // so a failure removing the old folder never leaves the admin with
        // no working module at all.
        if ($previousFolder !== null && $previousFolder !== $result->folder) {
            $this->removeOldModuleFolder($previousFolder);
        }

        $this->clearCaches();

        Log::info('ModuleManager updated module: ' . $result->alias . ' to ' . $target->ref);
    }

    /**
     * If this entry is tracked by commit (installedCommitSha is set) but
     * $target came back as a tagged release, resolves the tag to its
     * commit SHA before returning -- otherwise SavedRepo::
     * isUpdateAvailable() would compare a raw commit SHA against a tag
     * *name* and always report "different", even when the tag IS the
     * commit already installed. This only arises for a repo an admin
     * manually pinned to a specific ref that also happens to publish
     * releases (GithubRepoResolver::resolve() and the catalog always yield
     * a branch, never a tag, so a normally-added repo never reaches this);
     * without it, such a pin could show a permanent "Update available"
     * that clicking Update can never clear, since re-downloading the same
     * tag just re-extracts into the folder that's already there.
     *
     * $target->label/url are preserved from the original tag response so
     * the UI still shows a human-readable tag name and release link --
     * only $target->mode/$target->ref change, for comparison and download
     * purposes.
     */
    private function normalizeTargetForEntry(SavedRepo $entry, UpdateTarget $target): UpdateTarget
    {
        if ($target->mode !== UpdateTarget::MODE_TAG || $entry->installedCommitSha === null) {
            return $target;
        }

        try {
            $sha = $this->updateChecker->resolveCommit($entry->owner, $entry->repo, $target->ref);
        } catch (GithubDownloadException $e) {
            Log::warning('ModuleManager could not resolve tag to a commit for comparison: ' . $e->getMessage());
            return $target;
        }

        return new UpdateTarget(UpdateTarget::MODE_COMMIT, $sha, $target->label, $target->url);
    }

    /**
     * Defense in depth: $folder is always a single path segment (the ZIP's
     * own top-level folder name, which ZipModuleExtractor::
     * findSingleTopLevelFolder() already guarantees contains no "/"), but
     * refuse to build a filesystem path from anything that looks like it
     * could escape Modules/ before ever touching disk.
     */
    private function removeOldModuleFolder(string $folder): void
    {
        if ($folder === '' || strpos($folder, '/') !== false || strpos($folder, '..') !== false) {
            Log::warning("ModuleManager refused to remove suspicious old module folder: {$folder}");
            return;
        }

        $path = base_path('Modules/' . $folder);

        try {
            if (!File::isDirectory($path)) {
                return;
            }

            if (!File::deleteDirectory($path)) {
                Log::warning("ModuleManager could not remove old module folder: {$path}");
            }
        } catch (\Throwable $e) {
            // Best-effort, same as clearCaches() below: a failure here means
            // a leftover orphan folder on disk, not a broken update -- the
            // extraction and store bookkeeping this runs after have already
            // fully succeeded by this point, so this must never escalate
            // into a 500 for what the admin already saw reported as success.
            Log::warning('ModuleManager failed to remove old module folder: ' . $e->getMessage());
        }
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
        // Looked up fresh (rather than threading a SavedRepo through from
        // the caller) so this stays correct for installFromUpload(), which
        // has no saved repo at all -- $existing is simply null there, and
        // no cleanup is attempted, matching the fact that an uploaded ZIP
        // was never tracked against a previous folder in the first place.
        $previousFolder = null;
        if ($savedRepoId !== null) {
            $existing = $this->repoStore->find($savedRepoId);
            $previousFolder = $existing !== null ? $existing->installedFolder : null;
        }

        if ($savedRepoId !== null && $result->folder !== null) {
            $this->repoStore->markInstalled($savedRepoId, $result->alias, $result->folder);
        }

        // Same reasoning as afterSuccessfulUpdate()'s cleanup: without this,
        // clicking "Install" again for a saved repo whose folder name
        // depends on $entry->ref (e.g. after an Update already moved it to
        // a different ref-named folder) re-creates the exact orphaned-
        // duplicate-folder state Update itself was just fixed to avoid --
        // this is the plain Install button, always visible, not a one-off
        // edge case.
        if ($previousFolder !== null && $previousFolder !== $result->folder) {
            $this->removeOldModuleFolder($previousFolder);
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

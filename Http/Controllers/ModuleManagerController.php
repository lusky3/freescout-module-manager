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
use Modules\ModuleManager\Services\SavedRepoStore;
use Modules\ModuleManager\Services\ZipModuleExtractor;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class ModuleManagerController extends Controller
{
    /** Maximum accepted upload size for a module ZIP, in kilobytes (50MB). Bump here if larger modules are legitimate. */
    private const MAX_UPLOAD_KB = 51200;

    private SavedRepoStore $repoStore;

    private GithubRepoFetcher $githubFetcher;

    private ZipModuleExtractor $extractor;

    public function __construct(SavedRepoStore $repoStore, GithubRepoFetcher $githubFetcher, ZipModuleExtractor $extractor)
    {
        $this->repoStore = $repoStore;
        $this->githubFetcher = $githubFetcher;
        $this->extractor = $extractor;
    }

    public function addRepo(Request $request)
    {
        $this->authorizeAdmin();

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

    public function removeRepo(string $id)
    {
        $this->authorizeAdmin();

        $this->repoStore->remove($id);

        return redirect()->back()->with('success', __('Repository removed.'));
    }

    public function installFromRepo(string $id)
    {
        $this->authorizeAdmin();

        $entry = $this->repoStore->find($id);

        if (!$entry) {
            return redirect()->back()->withErrors(['repo' => __('Saved repository not found.')]);
        }

        $storageDir = $this->ensureStorageDir();
        $zipPath = $storageDir.'/'.$entry['id'].'.zip';

        try {
            $this->githubFetcher->download($entry['owner'], $entry['repo'], $entry['ref'], $zipPath);
        } catch (GithubDownloadException $e) {
            @unlink($zipPath);
            Log::warning('ModuleManager GitHub download failed: '.$e->getMessage());
            return redirect()->back()->withErrors(['repo' => $e->getMessage()]);
        }

        return $this->extractAndRespond($zipPath);
    }

    public function installFromUpload(Request $request)
    {
        $this->authorizeAdmin();

        $request->validate([
            // 'max' for file rules is in kilobytes; 51200 KB = 50MB. Adjust MAX_UPLOAD_KB above if this needs to change.
            'module_zip' => 'required|file|mimes:zip|max:'.self::MAX_UPLOAD_KB,
        ]);

        $storageDir = $this->ensureStorageDir();

        $uploaded = $request->file('module_zip');
        $zipName = 'upload_'.bin2hex(random_bytes(6)).'.zip';

        try {
            $uploaded->move($storageDir, $zipName);
        } catch (FileException $e) {
            Log::warning('ModuleManager upload move failed: '.$e->getMessage());
            return redirect()->back()->withErrors(['module_zip' => __('Could not save the uploaded file.')]);
        }

        return $this->extractAndRespond($storageDir.'/'.$zipName);
    }

    private function extractAndRespond(string $zipPath)
    {
        $result = $this->extractor->extract($zipPath);

        @unlink($zipPath);

        if (!$result->success) {
            return redirect()->back()->withErrors(['module' => $result->error]);
        }

        $this->clearCaches();

        Log::info('ModuleManager installed module: '.$result->alias);

        return redirect()->back()
            ->with('success', __('Installed module: :name', ['name' => $result->name]))
            ->with('warning', __('Please enable the module from the Modules page.'));
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
            Log::warning('ModuleManager cache clear failed: '.$e->getMessage());
        }
    }

    private function authorizeAdmin(): void
    {
        if (!Auth::user() || !Auth::user()->isAdmin()) {
            abort(403);
        }
    }
}

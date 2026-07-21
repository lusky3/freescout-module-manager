<?php

namespace Modules\ModuleManager\Http\Controllers;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\ModuleManager\Services\Exceptions\GithubDownloadException;
use Modules\ModuleManager\Services\GithubRepoFetcher;
use Modules\ModuleManager\Services\SavedRepoStore;
use Modules\ModuleManager\Services\Support\LaravelOptionStore;
use Modules\ModuleManager\Services\ZipModuleExtractor;

class ModuleManagerController extends Controller
{
    public function addRepo(Request $request)
    {
        $this->authorizeAdmin();

        $request->validate([
            'owner' => 'required|string|max:100',
            'repo' => 'required|string|max:100',
            'ref' => 'required|string|max:100',
            'label' => 'required|string|max:150',
        ]);

        $store = new SavedRepoStore(new LaravelOptionStore());
        $store->add(
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

        $store = new SavedRepoStore(new LaravelOptionStore());
        $store->remove($id);

        return redirect()->back()->with('success', __('Repository removed.'));
    }

    public function installFromRepo(string $id)
    {
        $this->authorizeAdmin();

        $store = new SavedRepoStore(new LaravelOptionStore());
        $entry = $store->find($id);

        if (!$entry) {
            return redirect()->back()->withErrors(['repo' => __('Saved repository not found.')]);
        }

        $storageDir = storage_path('app/modulemanager');
        if (!File::isDirectory($storageDir)) {
            File::makeDirectory($storageDir, 0775, true);
        }
        $zipPath = $storageDir.'/'.$entry['id'].'.zip';

        $fetcher = new GithubRepoFetcher(new Client());

        try {
            $fetcher->download($entry['owner'], $entry['repo'], $entry['ref'], $zipPath);
        } catch (GithubDownloadException $e) {
            Log::warning('ModuleManager GitHub download failed: '.$e->getMessage());
            return redirect()->back()->withErrors(['repo' => $e->getMessage()]);
        }

        return $this->extractAndRespond($zipPath);
    }

    public function installFromUpload(Request $request)
    {
        $this->authorizeAdmin();

        $request->validate([
            'module_zip' => 'required|file|mimes:zip',
        ]);

        $storageDir = storage_path('app/modulemanager');
        if (!File::isDirectory($storageDir)) {
            File::makeDirectory($storageDir, 0775, true);
        }

        $uploaded = $request->file('module_zip');
        $zipName = 'upload_'.bin2hex(random_bytes(6)).'.zip';
        $uploaded->move($storageDir, $zipName);

        return $this->extractAndRespond($storageDir.'/'.$zipName);
    }

    private function extractAndRespond(string $zipPath)
    {
        $extractor = new ZipModuleExtractor(base_path('Modules'));
        $result = $extractor->extract($zipPath);

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

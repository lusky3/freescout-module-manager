<?php

namespace Modules\ModuleManager\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\ViewErrorBag;
use Modules\ModuleManager\Services\DefaultRepoSeeder;
use Modules\ModuleManager\Services\GithubRepoFetcher;
use Modules\ModuleManager\Services\SavedRepoStore;
use Modules\ModuleManager\Services\Support\LaravelOptionStore;
use Modules\ModuleManager\Services\Support\OptionStoreInterface;
use Modules\ModuleManager\Services\ZipModuleExtractor;

class ModuleManagerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(OptionStoreInterface::class, LaravelOptionStore::class);

        $this->app->bind(SavedRepoStore::class, function ($app) {
            return new SavedRepoStore(
                $app->make(OptionStoreInterface::class),
                storage_path('app/modulemanager/saved_repos.lock')
            );
        });

        $this->app->bind(DefaultRepoSeeder::class, function ($app) {
            return new DefaultRepoSeeder(
                $app->make(SavedRepoStore::class),
                $app->make(OptionStoreInterface::class),
                __DIR__.'/../Resources/default-repos.json'
            );
        });

        $this->app->bind(GithubRepoFetcher::class, function () {
            return new GithubRepoFetcher(new Client());
        });

        $this->app->bind(ZipModuleExtractor::class, function () {
            return new ZipModuleExtractor(base_path('Modules'));
        });
    }

    public function boot()
    {
        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path.'/modules/modulemanager';
        }, \Config::get('view.paths')), [__DIR__.'/../Resources/views']), 'modulemanager');

        $this->loadRoutesFrom(__DIR__.'/../Http/routes.php');

        $this->registerSettingsSection();
        $this->registerViewComposer();
    }

    protected function registerSettingsSection()
    {
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['modulemanager'] = [
                'title' => __('Module Manager'),
                'icon' => 'download',
                'view' => 'modulemanager::settings',
                'order' => 200,
            ];

            return $sections;
        });

        // Ensure settings are not empty so the core controller does not
        // abort: FreeScout's SettingsController@view 404s when the
        // 'settings.section_settings' filter returns an empty array for the
        // section. Our own custom view (modulemanager::settings.index) never
        // reads this value directly, which makes it *look* like dead code,
        // but removing it breaks GET /app-settings/modulemanager (verified:
        // returns a 404 instead of 200 once this filter is removed).
        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section !== 'modulemanager') {
                return $settings;
            }

            $settings['modulemanager.enabled'] = app(OptionStoreInterface::class)->get('modulemanager.enabled', true);

            return $settings;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            if ($section !== 'modulemanager') {
                return $view;
            }

            return 'modulemanager::settings.index';
        }, 20, 2);
    }

    protected function registerViewComposer()
    {
        \View::composer('modulemanager::settings.index', function ($view) {
            $seeder = app(DefaultRepoSeeder::class);
            $seeder->seedIfNeeded();

            $store = app(SavedRepoStore::class);

            // NOTE: $view->errors (or $view['errors']) is *not* usable here.
            // Laravel's ShareErrorsFromSession middleware (part of the 'web'
            // group in Http/Kernel.php, confirmed to run ahead of this
            // route) does `View::share('errors', ...)`, but
            // Illuminate\View\View only merges the Factory's shared data
            // into its own $data inside gatherData() -- called from
            // renderContents() *after* Factory::callComposer() runs (see
            // vendor/laravel/framework .../View/View.php). So at the point
            // this composer closure executes, $view's own data does not yet
            // contain 'errors'. Pulling it straight from the Factory's
            // shared pool via View::shared() (already populated by the
            // middleware, which always runs before the controller/view for
            // this route) is what actually works reliably; verified against
            // the live Docker instance by triggering validation errors on
            // both the add-repo and upload forms.
            $errors = \View::shared('errors', new ViewErrorBag());

            $handledErrorFields = ['owner', 'repo', 'ref', 'label', 'module_zip'];
            $generalErrorKeys = collect($errors->keys())->diff($handledErrorFields);

            $firstInvalidRepoField = null;
            foreach (['owner', 'repo', 'ref', 'label'] as $repoField) {
                if ($errors->has($repoField)) {
                    $firstInvalidRepoField = $repoField;
                    break;
                }
            }

            // After a redirect-with-errors, the page reloads and BS3's tab
            // plugin has no client-side memory of which tab was open.
            // Compute the correct tab server-side so an upload-specific
            // error is never left stranded inside a hidden inactive
            // tab-pane.
            $activeInstallTab = $errors->has('module_zip') ? 'upload' : 'github';

            $view->with([
                'repos' => $store->all(),
                'generalErrorKeys' => $generalErrorKeys,
                'firstInvalidRepoField' => $firstInvalidRepoField,
                'activeInstallTab' => $activeInstallTab,
            ]);
        });
    }
}

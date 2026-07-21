<?php

namespace Modules\ModuleManager\Providers;

use GuzzleHttp\Client;
use Illuminate\Support\ServiceProvider;
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
            return new SavedRepoStore($app->make(OptionStoreInterface::class));
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

            $settings['modulemanager.enabled'] = \Option::get('modulemanager.enabled', true);

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
            $options = new LaravelOptionStore();
            $store = new SavedRepoStore($options);

            $seeder = new DefaultRepoSeeder(
                $store,
                $options,
                __DIR__.'/../Resources/default-repos.json'
            );
            $seeder->seedIfNeeded();

            $view->with('repos', $store->all());
        });
    }
}

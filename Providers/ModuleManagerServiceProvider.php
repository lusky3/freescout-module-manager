<?php

namespace Modules\ModuleManager\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\ModuleManager\Services\DefaultRepoSeeder;
use Modules\ModuleManager\Services\SavedRepoStore;
use Modules\ModuleManager\Services\Support\LaravelOptionStore;

class ModuleManagerServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'modulemanager');
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

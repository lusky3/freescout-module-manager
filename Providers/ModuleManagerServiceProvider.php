<?php

namespace Modules\ModuleManager\Providers;

use Illuminate\Support\ServiceProvider;

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
    }
}

<?php

Route::group([
    // 'roles' => ['admin'] alongside the 'roles' middleware alias is the
    // same convention FreeScout core uses for its own admin-only routes
    // (see e.g. routes/web.php: '/app-settings/{section?}', '/modules/list',
    // '/system/status' -- all ['middleware' => ['auth', 'roles'], 'roles' =>
    // ['admin']]). Verified against this app's actual
    // App\Http\Middleware\CheckRole (registered as the 'roles' route
    // middleware alias in Http/Kernel.php): it reads $route->getAction()['roles'].
    // Illuminate\Routing\RouteGroup::merge() folds group-level array keys
    // (other than namespace/prefix/where/as) into each route's own action
    // array via array_merge_recursive(), so setting 'roles' once here at the
    // group level applies to every route below -- confirmed by reading
    // RouteGroup::merge() in this app's vendor/laravel/framework checkout.
    // This is defense-in-depth on top of (not instead of) the controller
    // constructor's own admin-only middleware.
    'middleware' => ['web', 'auth', 'roles'],
    'roles' => ['admin'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\ModuleManager\\Http\\Controllers',
], function () {
    Route::post('/app-settings/modulemanager/repos', [
        'uses' => 'ModuleManagerController@addRepo',
    ])->name('modulemanager_add_repo');

    Route::post('/app-settings/modulemanager/repos/from-url', [
        'uses' => 'ModuleManagerController@addRepoFromUrl',
    ])->name('modulemanager_add_repo_from_url');

    Route::delete('/app-settings/modulemanager/repos/{id}', [
        'uses' => 'ModuleManagerController@removeRepo',
    ])->name('modulemanager_remove_repo')->where('id', '[0-9a-f]+');

    Route::post('/app-settings/modulemanager/repos/{id}/install', [
        'uses' => 'ModuleManagerController@installFromRepo',
    ])->name('modulemanager_install_repo')->where('id', '[0-9a-f]+');

    Route::post('/app-settings/modulemanager/upload', [
        'uses' => 'ModuleManagerController@installFromUpload',
    ])->name('modulemanager_install_upload');
});

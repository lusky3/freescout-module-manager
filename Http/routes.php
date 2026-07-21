<?php

Route::group([
    'middleware' => ['web', 'auth'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\ModuleManager\\Http\\Controllers',
], function () {
    Route::post('/app-settings/modulemanager/repos', [
        'uses' => 'ModuleManagerController@addRepo',
    ])->name('modulemanager_add_repo');

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

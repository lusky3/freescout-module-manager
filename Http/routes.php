<?php

Route::group([
    'middleware' => ['web', 'auth'],
    'prefix' => \Helper::getSubdirectory(),
    'namespace' => 'Modules\\ModuleManager\\Http\\Controllers',
], function () {
    // Routes added in Task 8 and Task 9.
});

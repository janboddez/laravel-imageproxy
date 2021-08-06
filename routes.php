<?php

use Illuminate\Support\Facades\Route;

Route::namespace('\\janboddez\\ImageProxy')
    ->group(function () {
        Route::get('imageproxy/{hash}/{url}', 'ImageProxyController@proxy')
            ->where('url', '.*')
            ->name('imageproxy');
    });

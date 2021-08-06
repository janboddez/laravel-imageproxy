<?php

namespace janboddez\ImageProxy;

use Illuminate\Support\ServiceProvider;

class ImageProxyServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config.php' => config_path('imageproxy.php'),
        ]);

        $this->loadRoutesFrom(__DIR__.'/../routes.php');
    }
}

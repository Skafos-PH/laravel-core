<?php

namespace Skafos\Providers;

use Illuminate\Support\ServiceProvider;
use Skafos\Commands\SkafosScoutImportCommand;

class CoreServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     *
     * @return void
     */
    public function boot()
    {
        $this->commands([
            SkafosScoutImportCommand::class,
        ]);
        
        $this->loadViewsFrom(__DIR__ . '/../../views', 'skafos');
    }
}

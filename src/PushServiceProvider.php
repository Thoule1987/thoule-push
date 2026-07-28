<?php

namespace Thoule\Push;

use Illuminate\Support\ServiceProvider;
use Thoule\Push\Console\PushAbosAufraeumenCommand;
use Thoule\Push\Contracts\PushTransport;
use Thoule\Push\Transport\MinishlinkTransport;

class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/push.php', 'push');

        // Voreinstellung, nicht Festlegung: Im Test bindet die App den TestTransport.
        $this->app->bind(PushTransport::class, MinishlinkTransport::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/push.php' => config_path('push.php'),
        ], 'push-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'push-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([PushAbosAufraeumenCommand::class]);
        }
    }
}

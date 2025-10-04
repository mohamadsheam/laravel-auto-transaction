<?php

namespace Sheum\AutoTransaction;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Sheum\AutoTransaction\Middleware\TransactionMiddleware;

class AutoTransactionServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/auto-transaction.php',
            'auto-transaction'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/auto-transaction.php' => config_path('auto-transaction.php'),
            ], 'auto-transaction-config');
        }

        // Register middleware
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('transaction', TransactionMiddleware::class);
        $router->aliasMiddleware('auto.transaction', TransactionMiddleware::class);
    }
}

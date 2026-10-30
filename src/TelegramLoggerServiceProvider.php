<?php

declare(strict_types=1);

namespace Xden\TelegramLogger;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;
use Xden\TelegramLogger\Console\TestCommand;
use Xden\TelegramLogger\Support\FailureReporter;
use Xden\TelegramLogger\Transport\TelegramClient;

final class TelegramLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telegram-logger.php', 'telegram-logger');

        $this->app->singleton(FailureReporter::class);
        $this->app->singleton(TelegramNotifier::class);
        // resolved on every send, so Http::fake() swapped in later is picked up
        $this->app->bind(TelegramClient::class, static fn (Application $app): TelegramClient => new TelegramClient($app->make(HttpFactory::class)));

        $this->callAfterResolving('log', static function (LogManager $log): void {
            $log->extend('telegram', function ($app, array $config) {
                return (new TelegramLoggerFactory)($config);
            });
        });
    }

    public function boot(): void
    {
        $views = __DIR__.'/../resources/views';

        $this->loadViewsFrom($views, 'telegram-logger');
        // template names of grkamil/laravel-telegram-logging keep working
        $this->loadViewsFrom($views, 'laravel-telegram-logging');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/telegram-logger.php' => $this->app->configPath('telegram-logger.php'),
            ], 'telegram-logger-config');

            $this->publishes([
                $views => $this->app->resourcePath('views/vendor/telegram-logger'),
            ], 'telegram-logger-views');

            $this->commands([TestCommand::class]);
        }
    }
}

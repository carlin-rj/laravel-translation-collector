<?php

namespace Carlin\LaravelTranslationCollector;

use Illuminate\Support\ServiceProvider;
use Carlin\LaravelTranslationCollector\Console\Commands\CollectTranslationsCommand;
use Carlin\LaravelTranslationCollector\Console\Commands\SyncTranslationsCommand;
use Carlin\LaravelTranslationCollector\Console\Commands\TranslationReportCommand;
use Carlin\LaravelTranslationCollector\Console\Commands\InitTranslationsCommand;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Services\TranslationCollectorService;
use Carlin\LaravelTranslationCollector\Services\ExternalApiClient;

class TranslationCollectorServiceProvider extends ServiceProvider
{
    /**
     * 是否延迟加载服务提供者
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * 启动服务
     *
     * @return void
     */
    public function boot()
    {
        // 发布配置文件
        $this->publishes([
            __DIR__ . '/../config/translation-collector.php' => config_path('translation-collector.php'),
        ], 'translation-collector-config');

        // 发布资源文件
        $this->publishes([
            __DIR__ . '/../resources' => resource_path('vendor/translation-collector'),
        ], 'translation-collector-resources');

        // 注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                CollectTranslationsCommand::class,
                SyncTranslationsCommand::class,
                TranslationReportCommand::class,
                InitTranslationsCommand::class,
            ]);
        }

        // 加载配置文件
        $this->mergeConfigFrom(
            __DIR__ . '/../config/translation-collector.php',
            'translation-collector'
        );
    }

    /**
     * 注册服务
     *
     * @return void
     */
    public function register()
    {
        // 注册核心服务
        $this->app->singleton(TranslationCollectorInterface::class, function ($app) {
            return new TranslationCollectorService($app);
        });

        // 注册外部API客户端
        $this->app->singleton(ExternalApiClientInterface::class, function ($app) {
            return new ExternalApiClient(
                config('translation-collector.external_api')
            );
        });

        // 注册门面别名
        $this->app->alias(TranslationCollectorInterface::class, 'translation-collector');
    }

    /**
     * 获取服务提供者提供的服务
     *
     * @return array
     */
    public function provides()
    {
        return [
            TranslationCollectorInterface::class,
            ExternalApiClientInterface::class,
            'translation-collector',
        ];
    }
}

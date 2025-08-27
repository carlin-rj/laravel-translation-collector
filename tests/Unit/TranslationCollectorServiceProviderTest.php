<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Carlin\LaravelTranslationCollector\TranslationCollectorServiceProvider;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Services\TranslationCollectorService;
use Carlin\LaravelTranslationCollector\Services\ExternalApiClient;
use Carlin\LaravelTranslationCollector\Console\Commands\CollectTranslationsCommand;
use Carlin\LaravelTranslationCollector\Console\Commands\SyncTranslationsCommand;
use Carlin\LaravelTranslationCollector\Console\Commands\TranslationReportCommand;

class TranslationCollectorServiceProviderTest extends TestCase
{
    /**
     * 测试服务提供者注册服务
     */
    public function test_service_provider_registers_services()
    {
        // 测试翻译收集器接口绑定
        $this->assertTrue($this->app->bound(TranslationCollectorInterface::class));
        $this->assertInstanceOf(
            TranslationCollectorService::class,
            $this->app->make(TranslationCollectorInterface::class)
        );

        // 测试外部API客户端接口绑定
        $this->assertTrue($this->app->bound(ExternalApiClientInterface::class));
        $this->assertInstanceOf(
            ExternalApiClient::class,
            $this->app->make(ExternalApiClientInterface::class)
        );

        // 测试门面别名
        $this->assertTrue($this->app->bound('translation-collector'));
        $this->assertInstanceOf(
            TranslationCollectorService::class,
            $this->app->make('translation-collector')
        );
    }

    /**
     * 测试服务提供者注册命令
     */
    public function test_service_provider_registers_commands()
    {
        // 测试命令是否注册
        $commands = $this->app->make('Illuminate\Contracts\Console\Kernel')->all();

        $this->assertArrayHasKey('translation:collect', $commands);
        $this->assertArrayHasKey('translation:sync', $commands);
        $this->assertArrayHasKey('translation:report', $commands);

        // 测试命令实例
        $this->assertInstanceOf(
            CollectTranslationsCommand::class,
            $commands['translation:collect']
        );
        $this->assertInstanceOf(
            SyncTranslationsCommand::class,
            $commands['translation:sync']
        );
        $this->assertInstanceOf(
            TranslationReportCommand::class,
            $commands['translation:report']
        );
    }

    /**
     * 测试配置文件加载
     */
    public function test_service_provider_loads_config()
    {
        // 测试配置是否加载
        $this->assertNotNull(config('translation-collector'));
        $this->assertIsArray(config('translation-collector'));

        // 测试关键配置项
        $this->assertArrayHasKey('external_api', config('translation-collector'));
        $this->assertArrayHasKey('scan_paths', config('translation-collector'));
        $this->assertArrayHasKey('supported_languages', config('translation-collector'));
        $this->assertArrayHasKey('translation_functions', config('translation-collector'));
    }

    /**
     * 测试服务提供者提供的服务列表
     */
    public function test_service_provider_provides_correct_services()
    {
        $provider = new TranslationCollectorServiceProvider($this->app);
        $provides = $provider->provides();

        $expectedServices = [
            TranslationCollectorInterface::class,
            ExternalApiClientInterface::class,
            'translation-collector',
        ];

        foreach ($expectedServices as $service) {
            $this->assertContains($service, $provides);
        }
    }

    /**
     * 测试单例绑定
     */
    public function test_services_are_singletons()
    {
        // 测试翻译收集器是单例
        $collector1 = $this->app->make(TranslationCollectorInterface::class);
        $collector2 = $this->app->make(TranslationCollectorInterface::class);
        $this->assertSame($collector1, $collector2);

        // 测试外部API客户端是单例
        $apiClient1 = $this->app->make(ExternalApiClientInterface::class);
        $apiClient2 = $this->app->make(ExternalApiClientInterface::class);
        $this->assertSame($apiClient1, $apiClient2);

        // 测试门面别名也是单例
        $facade1 = $this->app->make('translation-collector');
        $facade2 = $this->app->make('translation-collector');
        $this->assertSame($facade1, $facade2);
    }

    /**
     * 测试门面正常工作
     */
    public function test_facade_works_correctly()
    {
        // 测试门面是否正确解析到服务
        $facadeInstance = \Carlin\LaravelTranslationCollector\Facades\TranslationCollector::getFacadeRoot();
        $serviceInstance = $this->app->make(TranslationCollectorInterface::class);

        $this->assertSame($facadeInstance, $serviceInstance);
    }

    /**
     * 测试配置合并
     */
    public function test_config_merging()
    {
        // 测试默认配置是否正确合并
        $config = config('translation-collector');

        // 测试默认值
        $this->assertIsArray($config['scan_paths']);
        $this->assertIsArray($config['exclude_paths']);
        $this->assertIsArray($config['translation_functions']);
        $this->assertIsArray($config['supported_languages']);

        // 测试数组结构
        $this->assertArrayHasKey('external_api', $config);
        $this->assertArrayHasKey('endpoints', $config['external_api']);
        $this->assertArrayHasKey('modules_support', $config);
        $this->assertArrayHasKey('regex_patterns', $config);
    }

    /**
     * 测试延迟加载设置
     */
    public function test_service_provider_defer_setting()
    {
        $provider = new TranslationCollectorServiceProvider($this->app);

        // 测试不延迟加载（命令需要立即注册）
        $reflection = new \ReflectionClass($provider);
        $deferProperty = $reflection->getProperty('defer');
        $deferProperty->setAccessible(true);

        $this->assertFalse($deferProperty->getValue($provider));
    }
}

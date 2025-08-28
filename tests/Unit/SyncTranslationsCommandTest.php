<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Console\Commands\SyncTranslationsCommand;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;

class SyncTranslationsCommandTest extends TestCase
{
    protected ExternalApiClientInterface $mockApiClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockApiClient = Mockery::mock(ExternalApiClientInterface::class);
        
        // 绑定到容器
        $this->app->instance(ExternalApiClientInterface::class, $this->mockApiClient);

        // 设置配置
        $this->app['config']->set('translation-collector', [
            'supported_languages' => [
                'en' => 'English',
                'zh' => '中文',
                'fr' => 'Français',
            ],
            'lang_path' => resource_path('lang'),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 测试命令可以被实例化
     */
    public function test_command_can_be_instantiated()
    {
        $command = new SyncTranslationsCommand($this->mockApiClient);
        
        $this->assertInstanceOf(SyncTranslationsCommand::class, $command);
    }

    /**
     * 测试API连接失败
     */
    public function test_api_connection_failure()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        $this->artisan('translation:sync')
            ->assertExitCode(1)
            ->expectsOutput('❌ 无法连接到外部翻译系统');
    }

    /**
     * 测试基本的pull同步
     */
    public function test_basic_pull_sync()
    {
        $externalTranslations = [
            ['key' => 'user.login', 'value' => 'Login', 'language' => 'en'],
            ['key' => 'user.logout', 'value' => 'Logout', 'language' => 'en'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->times(3) // 对应3种语言
            ->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', ['--direction' => 'pull'])
            ->assertExitCode(0)
            ->expectsOutput('📥 从外部系统拉取翻译...')
            ->expectsOutput('✅ 翻译同步完成!');
    }

    /**
     * 测试push功能提示
     */
    public function test_push_functionality_guidance()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);

        $this->artisan('translation:sync', ['--direction' => 'push'])
            ->assertExitCode(0)
            ->expectsOutput('📤 推送翻译到外部系统...')
            ->expectsOutput('⚠️  注意：推送功能已在 translation:collect --upload 命令中实现')
            ->expectsOutput('💡 建议使用以下命令进行推送：');
    }

    /**
     * 测试双向同步
     */
    public function test_bidirectional_sync()
    {
        $externalTranslations = [
            ['key' => 'test.key', 'value' => 'Test Value', 'language' => 'en'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->times(3)
            ->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', ['--direction' => 'both'])
            ->assertExitCode(0)
            ->expectsOutput('🔄 执行双向同步...')
            ->expectsOutput('⚠️  注意：双向同步将先执行pull，然后建议使用 translation:collect --upload 执行push');
    }

    /**
     * 测试自动格式检测功能
     */
    public function test_auto_format_detection()
    {
        $externalTranslations = [
            ['key' => 'auth.login', 'value' => 'Login', 'file_type' => 'php'],
            ['key' => 'auth.logout', 'value' => 'Logout', 'file_type' => 'php'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->with(['language' => 'en'])
            ->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
            '--auto-detect-format' => true,
        ])
            ->assertExitCode(0);
    }

    /**
     * 测试合并模式 - merge
     */
    public function test_merge_mode()
    {
        $externalTranslations = [
            ['key' => 'new.key', 'value' => 'New Value'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        // Mock 存在本地文件
        File::shouldReceive('exists')->with(resource_path('lang/en'))->andReturn(false);
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
            '--merge-mode' => 'merge',
        ])
            ->assertExitCode(0);
    }

    /**
     * 测试覆盖模式 - overwrite
     */
    public function test_overwrite_mode()
    {
        $externalTranslations = [
            ['key' => 'test.key', 'value' => 'New Value'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
            '--merge-mode' => 'overwrite',
            '--force' => true,
        ])
            ->assertExitCode(0);
    }

    /**
     * 测试干跑模式
     */
    public function test_dry_run_mode()
    {
        $externalTranslations = [
            ['key' => 'test.key', 'value' => 'Test Value'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        // Mock 文件系统 - 干跑模式不应该创建文件
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldNotReceive('put'); // 干跑模式不应该写文件

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
            '--dry-run' => true,
        ])
            ->assertExitCode(0);
    }

    /**
     * 测试指定特定语言
     */
    public function test_specific_language_sync()
    {
        $externalTranslations = [
            ['key' => 'hello', 'value' => 'Bonjour'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->with(['language' => 'fr'])
            ->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['fr'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: fr');
    }

    /**
     * 测试空的外部翻译响应
     */
    public function test_empty_external_translations()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn([]);

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
        ])
            ->assertExitCode(0);
    }

    /**
     * 测试API异常处理
     */
    public function test_api_exception_handling()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andThrow(new \Exception('API异常'));

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
        ])
            ->assertExitCode(0);
    }

    /**
     * 测试无效的同步方向
     */
    public function test_invalid_sync_direction()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);

        $this->artisan('translation:sync', ['--direction' => 'invalid'])
            ->assertExitCode(1)
            ->expectsOutput('无效的同步方向: invalid');
    }

    /**
     * 测试配置显示功能
     */
    public function test_configuration_display()
    {
        $externalTranslations = [
            ['key' => 'test.key', 'value' => 'Test Value'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
            '--merge-mode' => 'merge',
            '--auto-detect-format' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('📊 同步配置:')
            ->expectsOutputToContain('同步方向')
            ->expectsOutputToContain('目标语言')
            ->expectsOutputToContain('合并模式')
            ->expectsOutputToContain('自动检测格式');
    }

    /**
     * 测试JSON格式检测（默认）
     */
    public function test_json_format_detection_default()
    {
        $externalTranslations = [
            ['key' => 'simple_key', 'value' => 'Simple Value'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
        ])
            ->assertExitCode(0);
    }
}
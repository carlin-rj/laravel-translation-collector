<?php

namespace Carlin\LaravelTranslationCollector\Tests\Feature;

use Mockery;
use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Carlin\LaravelTranslationCollector\Console\Commands\CollectTranslationsCommand;
use Carlin\LaravelTranslationCollector\Console\Commands\SyncTranslationsCommand;
use Carlin\LaravelTranslationCollector\Console\Commands\TranslationReportCommand;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;

class ConsoleCommandsTest extends TestCase
{
    /**
     * 测试翻译收集命令
     */
    public function test_collect_translations_command()
    {
        $tempDir = $this->getTempDirectory();
        
        // 配置扫描路径
        config([
            'translation-collector.scan_paths' => [$tempDir . '/app/'],
        ]);

        // 执行命令
        $this->artisan('translation:collect', ['--dry-run' => true])
            ->expectsOutput('🔍 开始收集翻译文本...')
            ->expectsOutput('✅ 翻译收集完成!')
            ->assertExitCode(0);
    }

    /**
     * 测试带参数的收集命令
     */
    public function test_collect_translations_command_with_options()
    {
        $tempDir = $this->getTempDirectory();
        
        config([
            'translation-collector.modules_support.enabled' => true,
            'translation-collector.modules_support.modules_path' => $tempDir . '/Modules',
        ]);

        // 测试指定模块
        $this->artisan('translation:collect', [
            '--module' => ['User'],
            '--dry-run' => true,
            '--format' => 'table'
        ])
        ->expectsOutput('🔍 开始收集翻译文本...')
        ->assertExitCode(0);
    }

    /**
     * 测试收集命令输出格式
     */
    public function test_collect_command_output_formats()
    {
        $tempDir = $this->getTempDirectory();
        $outputFile = $tempDir . '/output.json';
        
        config(['translation-collector.scan_paths' => [$tempDir . '/app/']]);

        // 测试JSON输出到文件
        $this->artisan('translation:collect', [
            '--dry-run' => true,
            '--format' => 'json',
            '--output' => $outputFile
        ])
        ->assertExitCode(0);

        // 验证文件是否创建
        if (file_exists($outputFile)) {
            $content = file_get_contents($outputFile);
            $this->assertJson($content);
            unlink($outputFile);
        }
    }

    /**
     * 测试同步命令
     */
    public function test_sync_translations_command()
    {
        // 模拟外部API客户端
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andReturn(true);
        $apiClient->shouldReceive('getTranslations')->andReturn([]);
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);

        $this->artisan('translation:sync', ['--dry-run' => true])
            ->expectsOutput('🔄 开始同步翻译文件...')
            ->expectsOutput('✅ 翻译同步完成!')
            ->assertExitCode(0);
    }

    /**
     * 测试同步命令 - API连接失败
     */
    public function test_sync_command_handles_api_connection_failure()
    {
        // 模拟API连接失败
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andReturn(false);
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);

        $this->artisan('translation:sync')
            ->expectsOutput('❌ 无法连接到外部翻译系统')
            ->assertExitCode(1);
    }

    /**
     * 测试不同方向的同步
     */
    public function test_sync_command_different_directions()
    {
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andReturn(true);
        $apiClient->shouldReceive('getTranslations')->andReturn([]);
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);

        // 测试仅拉取
        $this->artisan('translation:sync', [
            '--direction' => 'pull',
            '--language' => ['en'],
            '--dry-run' => true
        ])
        ->expectsOutput('📥 从外部系统拉取翻译...')
        ->assertExitCode(0);

        // 测试仅推送
        $this->artisan('translation:sync', [
            '--direction' => 'push',
            '--language' => ['en'],
            '--dry-run' => true
        ])
        ->expectsOutput('📤 推送翻译到外部系统...')
        ->assertExitCode(0);
    }

    /**
     * 测试报告生成命令
     */
    public function test_report_command()
    {
        $tempDir = $this->getTempDirectory();
        
        // 模拟API客户端
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andReturn(true);
        $apiClient->shouldReceive('getTranslations')->andReturn([]);
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);
        
        config(['translation-collector.scan_paths' => [$tempDir . '/app/']]);

        $this->artisan('translation:report')
            ->expectsOutput('📊 开始生成翻译报告...')
            ->expectsOutput('✅ 翻译报告生成完成!')
            ->assertExitCode(0);
    }

    /**
     * 测试报告命令的不同格式
     */
    public function test_report_command_different_formats()
    {
        $tempDir = $this->getTempDirectory();
        $outputFile = $tempDir . '/report.html';
        
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andReturn(true);
        $apiClient->shouldReceive('getTranslations')->andReturn([]);
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);
        
        config(['translation-collector.scan_paths' => [$tempDir . '/app/']]);

        // 测试HTML格式输出
        $this->artisan('translation:report', [
            '--format' => 'html',
            '--output' => $outputFile,
            '--include-statistics' => true,
            '--include-missing' => true
        ])
        ->assertExitCode(0);

        // 验证文件是否创建
        if (file_exists($outputFile)) {
            $content = file_get_contents($outputFile);
            $this->assertStringContainsString('<html>', $content);
            $this->assertStringContainsString('翻译状态报告', $content);
            unlink($outputFile);
        }
    }

    /**
     * 测试报告命令 - 指定语言
     */
    public function test_report_command_specific_languages()
    {
        $tempDir = $this->getTempDirectory();
        
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andReturn(true);
        $apiClient->shouldReceive('getTranslations')->andReturn([]);
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);
        
        config(['translation-collector.scan_paths' => [$tempDir . '/app/']]);

        $this->artisan('translation:report', [
            '--language' => ['en', 'zh_CN'],
            '--include-statistics' => true
        ])
        ->assertExitCode(0);
    }

    /**
     * 测试命令错误处理
     */
    public function test_commands_handle_errors_gracefully()
    {
        // 测试收集命令错误处理
        config(['translation-collector.scan_paths' => ['/nonexistent/path']]);

        $this->artisan('translation:collect')
            ->assertExitCode(0); // 应该优雅处理错误而不是崩溃

        // 测试同步命令错误处理
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andThrow(new \Exception('Connection error'));
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);

        $this->artisan('translation:sync')
            ->assertExitCode(1);
    }

    /**
     * 测试命令的帮助信息
     */
    public function test_commands_help_information()
    {
        // 测试收集命令帮助
        $this->artisan('help', ['command_name' => 'translation:collect'])
            ->expectsOutput('收集项目中的翻译文本')
            ->assertExitCode(0);

        // 测试同步命令帮助
        $this->artisan('help', ['command_name' => 'translation:sync'])
            ->expectsOutput('与外部翻译系统同步翻译文件')
            ->assertExitCode(0);

        // 测试报告命令帮助
        $this->artisan('help', ['command_name' => 'translation:report'])
            ->expectsOutput('生成翻译状态报告')
            ->assertExitCode(0);
    }

    /**
     * 测试带上传选项的收集命令
     */
    public function test_collect_command_with_upload()
    {
        $tempDir = $this->getTempDirectory();
        
        // 模拟API客户端
        $apiClient = Mockery::mock(ExternalApiClientInterface::class);
        $apiClient->shouldReceive('checkConnection')->andReturn(true);
        $apiClient->shouldReceive('getTranslations')->andReturn([]);
        $apiClient->shouldReceive('batchUpload')->andReturn([['success' => true]]);
        
        $this->app->instance(ExternalApiClientInterface::class, $apiClient);
        
        config(['translation-collector.scan_paths' => [$tempDir . '/app/']]);

        $this->artisan('translation:collect', ['--upload' => true])
            ->expectsOutput('🔍 开始收集翻译文本...')
            ->expectsOutput('🚀 开始上传到外部翻译系统...')
            ->assertExitCode(0);
    }

    /**
     * 清理测试
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

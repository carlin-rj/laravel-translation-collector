<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Console\Commands\TranslationReportCommand;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;

class TranslationReportCommandTest extends TestCase
{
    protected TranslationCollectorInterface $mockCollector;
    protected ExternalApiClientInterface $mockApiClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockCollector = Mockery::mock(TranslationCollectorInterface::class);
        $this->mockApiClient = Mockery::mock(ExternalApiClientInterface::class);
        
        // 绑定到容器
        $this->app->instance(TranslationCollectorInterface::class, $this->mockCollector);
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
        $command = new TranslationReportCommand(
            $this->mockCollector,
            $this->mockApiClient
        );
        
        $this->assertInstanceOf(TranslationReportCommand::class, $command);
    }

    /**
     * 测试基本报告生成功能
     */
    public function test_basic_report_generation()
    {
        $expectedTranslations = [
            [
                'key' => 'user.login.success',
                'default_text' => 'Login successful',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
                'module' => 'User',
                'file_type' => 'php',
            ],
            [
                'key' => 'user.logout.success',
                'default_text' => 'Logout successful',
                'source_file' => '/path/to/another.php',
                'line_number' => 20,
                'module' => 'User',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 5,
            'total_translations_found' => 2,
            'new_translations' => 1,
            'existing_translations' => 1,
            'scan_duration' => 0.5,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report')
            ->assertExitCode(0)
            ->expectsOutput('📊 开始生成翻译报告...')
            ->expectsOutput('✅ 翻译报告生成完成!');
    }

    /**
     * 测试JSON格式报告输出
     */
    public function test_json_format_output()
    {
        $expectedTranslations = [
            [
                'key' => 'app.title',
                'default_text' => 'Application Title',
                'source_file' => '/path/to/file.php',
                'module' => 'Core',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', ['--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"total_collected_keys": 1');
    }

    /**
     * 测试CSV格式报告输出
     */
    public function test_csv_format_output()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', ['--format' => 'csv'])
            ->assertExitCode(0);
    }

    /**
     * 测试HTML格式报告输出
     */
    public function test_html_format_output()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', ['--format' => 'html'])
            ->assertExitCode(0);
    }

    /**
     * 测试包含统计信息的报告
     */
    public function test_report_with_statistics()
    {
        $expectedTranslations = [
            [
                'key' => 'user.login',
                'default_text' => 'Login',
                'source_file' => '/path/to/user.php',
                'module' => 'User',
                'file_type' => 'php',
            ],
            [
                'key' => 'admin.dashboard',
                'default_text' => 'Dashboard',
                'source_file' => '/path/to/admin.js',
                'module' => 'Admin',
                'file_type' => 'js',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 2,
            'total_translations_found' => 2,
            'new_translations' => 2,
            'existing_translations' => 0,
            'scan_duration' => 0.2,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', ['--include-statistics' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('📊 翻译状态报告摘要:');
    }

    /**
     * 测试包含缺失翻译的报告
     */
    public function test_report_with_missing_translations()
    {
        $expectedTranslations = [
            [
                'key' => 'missing.key',
                'default_text' => 'Missing translation',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统 - 模拟存在一些本地翻译但缺少收集到的键
        File::shouldReceive('exists')->with(resource_path('lang/en'))->andReturn(true);
        File::shouldReceive('exists')->with(resource_path('lang/zh'))->andReturn(true);
        File::shouldReceive('exists')->with(resource_path('lang/fr'))->andReturn(true);
        File::shouldReceive('exists')->andReturn(false); // 其他路径返回false
        File::shouldReceive('isDirectory')->andReturn(true);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', ['--include-missing' => true])
            ->assertExitCode(0);
    }

    /**
     * 测试包含未使用翻译的报告
     */
    public function test_report_with_unused_translations()
    {
        $expectedTranslations = [
            [
                'key' => 'used.key',
                'default_text' => 'Used translation',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', ['--include-unused' => true])
            ->assertExitCode(0);
    }

    /**
     * 测试指定语言的报告
     */
    public function test_report_with_specific_languages()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统 - 只检查指定的语言
        File::shouldReceive('exists')->with(resource_path('lang/en'))->andReturn(false);
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', ['--language' => ['en']])
            ->assertExitCode(0);
    }

    /**
     * 测试报告保存到文件
     */
    public function test_save_report_to_file()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统 - 模拟文件保存
        File::shouldReceive('exists')->with('/tmp/reports')->andReturn(false);
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);
        File::shouldReceive('makeDirectory')->with('/tmp/reports', 0755, true)->once();
        File::shouldReceive('put')->with('/tmp/reports/translation-report.json', Mockery::type('string'))->once();

        $this->artisan('translation:report', ['--output' => '/tmp/reports/translation-report.json'])
            ->assertExitCode(0)
            ->expectsOutput('✅ 报告已保存到: /tmp/reports/translation-report.json');
    }

    /**
     * 测试外部API连接成功时的报告
     */
    public function test_report_with_external_api_connection()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $externalTranslations = [
            ['key' => 'test.key', 'value' => 'Test Translation'],
            ['key' => 'another.key', 'value' => 'Another Translation'],
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')->once()->andReturn($externalTranslations);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report')
            ->assertExitCode(0)
            ->expectsOutputToContain('📊 翻译状态报告摘要:');
    }

    /**
     * 测试外部API连接失败时的报告
     */
    public function test_report_with_external_api_failure()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'module' => 'Test',
                'file_type' => 'php',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')->once()->andThrow(new \Exception('API Error'));

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report')
            ->assertExitCode(0)
            ->expectsOutputToContain('无法获取外部系统翻译: API Error');
    }

    /**
     * 测试命令异常处理
     */
    public function test_command_exception_handling()
    {
        $this->mockCollector->shouldReceive('collect')->once()->andThrow(new \Exception('测试异常'));

        $this->artisan('translation:report')
            ->assertExitCode(1)
            ->expectsOutput('❌ 报告生成失败: 测试异常');
    }

    /**
     * 测试语言覆盖率状态判断
     */
    public function test_language_coverage_status()
    {
        $expectedTranslations = [];
        $expectedStats = [
            'total_files_scanned' => 0,
            'total_translations_found' => 0,
            'new_translations' => 0,
            'existing_translations' => 0,
            'scan_duration' => 0.0,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report')
            ->assertExitCode(0);
    }

    /**
     * 测试完整的报告生成流程
     */
    public function test_complete_report_generation_workflow()
    {
        $expectedTranslations = [
            [
                'key' => 'user.login.success',
                'default_text' => 'Login successful',
                'source_file' => '/path/to/user.php',
                'module' => 'User',
                'file_type' => 'php',
            ],
            [
                'key' => 'admin.dashboard.title',
                'default_text' => 'Admin Dashboard',
                'source_file' => '/path/to/admin.blade.php',
                'module' => 'Admin',
                'file_type' => 'blade',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 10,
            'total_translations_found' => 2,
            'new_translations' => 1,
            'existing_translations' => 1,
            'scan_duration' => 0.8,
        ];

        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        // Mock 文件系统
        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('isDirectory')->andReturn(false);
        File::shouldReceive('glob')->andReturn([]);

        $this->artisan('translation:report', [
            '--include-statistics' => true,
            '--include-missing' => true,
            '--include-unused' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('📊 开始生成翻译报告...')
            ->expectsOutput('🔍 收集翻译数据...')
            ->expectsOutput('📝 生成报告内容...')
            ->expectsOutput('✅ 翻译报告生成完成!');
    }
}
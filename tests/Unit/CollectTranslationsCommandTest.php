<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Console\Commands\CollectTranslationsCommand;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;

class CollectTranslationsCommandTest extends TestCase
{
    protected TranslationCollectorInterface $mockCollector;
    protected ExternalApiClientInterface $mockApiClient;
    protected CollectTranslationsCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockCollector = Mockery::mock(TranslationCollectorInterface::class);
        $this->mockApiClient = Mockery::mock(ExternalApiClientInterface::class);
        
        // 绑定到容器
        $this->app->instance(TranslationCollectorInterface::class, $this->mockCollector);
        $this->app->instance(ExternalApiClientInterface::class, $this->mockApiClient);
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
        $command = new CollectTranslationsCommand(
            $this->mockCollector,
            $this->mockApiClient
        );
        
        $this->assertInstanceOf(CollectTranslationsCommand::class, $command);
    }

    /**
     * 测试基本的翻译收集功能
     */
    public function test_basic_translation_collection()
    {
        $expectedTranslations = [
            [
                'key' => 'user.login.success',
                'default_text' => 'Login successful',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
                'module' => 'User',
            ],
            [
                'key' => '这是中文文本',
                'default_text' => '这是中文文本',
                'source_file' => '/path/to/another.php',
                'line_number' => 15,
                'module' => 'Main',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 10,
            'total_translations_found' => 2,
            'new_translations' => 1,
            'existing_translations' => 1,
            'scan_duration' => 0.5,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once()->with([]);
        $this->mockCollector->shouldReceive('collect')->once()->with([])->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->artisan('translation:collect')
            ->assertExitCode(0)
            ->expectsOutput('🔍 开始收集翻译文本...')
            ->expectsOutput('✅ 翻译收集完成!');
    }

    /**
     * 测试跳过不存在翻译键的逻辑
     */
    public function test_skips_nonexistent_translation_keys()
    {
        // 只返回本地翻译文件中存在的键
        $expectedTranslations = [
            [
                'key' => 'user.login.success',
                'default_text' => 'Login successful',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 5,
            'total_translations_found' => 1,
            'new_translations' => 0,
            'existing_translations' => 1,
            'scan_duration' => 0.3,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once();
        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->artisan('translation:collect')
            ->assertExitCode(0);
        
        // 验证不存在的键没有被包含在结果中
        foreach ($expectedTranslations as $translation) {
            $this->assertNotEquals('message.error', $translation['key'], '不存在的翻译键应该被跳过');
        }
    }

    /**
     * 测试模块指定功能
     */
    public function test_module_specific_collection()
    {
        $expectedTranslations = [
            [
                'key' => 'user.login.success',
                'default_text' => 'Login successful',
                'source_file' => '/modules/User/file.php',
                'line_number' => 10,
                'module' => 'User',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 3,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.2,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once()->with(['modules' => ['User']]);
        $this->mockCollector->shouldReceive('scanModules')->once()->with(['User'])->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->artisan('translation:collect', ['--module' => ['User']])
            ->assertExitCode(0);
    }

    /**
     * 测试路径指定功能
     */
    public function test_path_specific_collection()
    {
        $expectedTranslations = [
            [
                'key' => 'app.title',
                'default_text' => 'Application Title',
                'source_file' => '/custom/path/file.php',
                'line_number' => 5,
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 2,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once()->with(['paths' => ['/custom/path']]);
        $this->mockCollector->shouldReceive('collect')->once()->with(['paths' => ['/custom/path']])->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->artisan('translation:collect', ['--path' => ['/custom/path']])
            ->assertExitCode(0);
    }

    /**
     * 测试dry-run功能
     */
    public function test_dry_run_mode()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once();
        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        // 在dry-run模式下不应该上传
        $this->mockApiClient->shouldNotReceive('checkConnection');
        $this->mockApiClient->shouldNotReceive('batchUpload');

        $this->artisan('translation:collect', ['--dry-run' => true])
            ->assertExitCode(0);
    }

    /**
     * 测试JSON输出格式
     */
    public function test_json_output_format()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once();
        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->artisan('translation:collect', ['--format' => 'json'])
            ->assertExitCode(0)
            ->expectsOutputToContain('"key": "test.key"');
    }

    /**
     * 测试表格输出格式
     */
    public function test_table_output_format()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
                'module' => 'Test',
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once();
        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->artisan('translation:collect', ['--format' => 'table'])
            ->assertExitCode(0)
            ->expectsOutputToContain('test.key');
    }

    /**
     * 测试上传功能
     */
    public function test_upload_functionality()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $differences = [
            'new' => [$expectedTranslations[0]],
            'updated' => [],
        ];

        $this->mockCollector->shouldReceive('setOptions')->once();
        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);
        $this->mockCollector->shouldReceive('analyzeDifferences')->once()->andReturn($differences);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')->once()->andReturn([]);
        $this->mockApiClient->shouldReceive('batchUpload')->once()->andReturn([['success' => true]]);

        $this->artisan('translation:collect', ['--upload' => true])
            ->assertExitCode(0)
            ->expectsOutput('🚀 开始上传到外部翻译系统...');
    }

    /**
     * 测试上传连接失败
     */
    public function test_upload_connection_failure()
    {
        $expectedTranslations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test',
                'source_file' => '/path/to/file.php',
                'line_number' => 10,
            ],
        ];

        $expectedStats = [
            'total_files_scanned' => 1,
            'total_translations_found' => 1,
            'new_translations' => 1,
            'existing_translations' => 0,
            'scan_duration' => 0.1,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once();
        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        $this->artisan('translation:collect', ['--upload' => true])
            ->assertExitCode(0)
            ->expectsOutput('❌ 无法连接到外部翻译系统');
    }

    /**
     * 测试命令异常处理
     */
    public function test_command_exception_handling()
    {
        $this->mockCollector->shouldReceive('setOptions')->once();
        $this->mockCollector->shouldReceive('collect')->once()->andThrow(new \Exception('测试异常'));

        $this->artisan('translation:collect')
            ->assertExitCode(1)
            ->expectsOutput('❌ 翻译收集失败: 测试异常');
    }

    /**
     * 测试无缓存选项
     */
    public function test_no_cache_option()
    {
        $expectedTranslations = [];
        $expectedStats = [
            'total_files_scanned' => 0,
            'total_translations_found' => 0,
            'new_translations' => 0,
            'existing_translations' => 0,
            'scan_duration' => 0.0,
        ];

        $this->mockCollector->shouldReceive('setOptions')->once()->with(['use_cache' => false]);
        $this->mockCollector->shouldReceive('collect')->once()->andReturn($expectedTranslations);
        $this->mockCollector->shouldReceive('getStatistics')->once()->andReturn($expectedStats);

        $this->artisan('translation:collect', ['--no-cache' => true])
            ->assertExitCode(0);
    }
}
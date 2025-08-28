<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Console\Commands\SyncTranslationsCommand;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Illuminate\Support\Facades\File;
use Mockery;
use ReflectionClass;

class SyncTranslationsCommandTest extends TestCase
{
    protected ExternalApiClientInterface $mockApiClient;
    protected SyncTranslationsCommand $command;
    protected ReflectionClass $reflection;

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

        // 创建命令实例和反射对象用于测试私有方法
        $this->command = new SyncTranslationsCommand($this->mockApiClient);
        $this->reflection = new ReflectionClass($this->command);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 调用私有或受保护的方法
     */
    protected function invokeMethod(string $methodName, array $parameters = []): mixed
    {
        $method = $this->reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->command, $parameters);
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
     * 测试空的外部翻译响应
     */
    public function test_empty_external_translations()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn([]);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('  - 没有找到 en 的翻译');
    }

    /**
     * 测试翻译数据验证 - 有效数据
     */
    public function test_validate_translation_data_valid()
    {
        // 有效的 JSON 数据
        $validJsonData = [
            'key' => 'welcome',
            'value' => 'Welcome',
            'file_type' => 'json'
        ];
        $this->assertTrue($this->invokeMethod('validateTranslationData', [$validJsonData]));

        // 有效的 PHP 数据
        $validPhpData = [
            'key' => 'auth.login',
            'value' => 'Login', 
            'file_type' => 'php'
        ];
        $this->assertTrue($this->invokeMethod('validateTranslationData', [$validPhpData]));
    }

    /**
     * 测试翻译数据验证 - 无效数据
     */
    public function test_validate_translation_data_invalid()
    {
        // 缺少 key
        $missingKey = ['value' => 'Test Value'];
        $this->assertFalse($this->invokeMethod('validateTranslationData', [$missingKey]));

        // 缺少 value
        $missingValue = ['key' => 'test.key'];
        $this->assertFalse($this->invokeMethod('validateTranslationData', [$missingValue]));

        // PHP 类型但是无效的 key 格式
        $invalidPhpKey = [
            'key' => 'simple_key', // PHP类型应该有命名空间
            'value' => 'Simple Value',
            'file_type' => 'php'
        ];
        $this->assertFalse($this->invokeMethod('validateTranslationData', [$invalidPhpKey]));
    }

    /**
     * 测试获取翻译文件类型
     */
    public function test_get_translation_file_type()
    {
        // 默认为 JSON
        $defaultData = ['key' => 'test', 'value' => 'value'];
        $this->assertEquals('json', $this->invokeMethod('getTranslationFileType', [$defaultData]));

        // 指定为 PHP
        $phpData = ['key' => 'test', 'value' => 'value', 'file_type' => 'php'];
        $this->assertEquals('php', $this->invokeMethod('getTranslationFileType', [$phpData]));
    }

    /**
     * 测试从键中提取文件名
     */
    public function test_extract_file_name_from_key()
    {
        // 正常的命名空间键
        $this->assertEquals('auth', $this->invokeMethod('extractFileNameFromKey', ['auth.login']));
        $this->assertEquals('validation', $this->invokeMethod('extractFileNameFromKey', ['validation.required']));

        // 没有命名空间的键
        $this->assertNull($this->invokeMethod('extractFileNameFromKey', ['simple_key']));
    }

    /**
     * 测试构建文件信息
     */
    public function test_build_file_info()
    {
        // JSON 格式
        $jsonTranslation = ['key' => 'welcome', 'value' => 'Welcome', 'file_type' => 'json'];
        $language = 'en';
        $langPath = resource_path('lang');

        $fileInfo = $this->invokeMethod('buildFileInfo', [$jsonTranslation, $language, $langPath]);
        $this->assertEquals('json', $fileInfo['type']);
        $this->assertEquals(resource_path('lang/en.json'), $fileInfo['path']);

        // PHP 格式
        $phpTranslation = ['key' => 'auth.login', 'value' => 'Login', 'file_type' => 'php'];
        $fileInfo = $this->invokeMethod('buildFileInfo', [$phpTranslation, $language, $langPath]);
        $this->assertEquals('php', $fileInfo['type']);
        $this->assertEquals('auth', $fileInfo['fileName']);
    }

    /**
     * 测试按文件目标分组翻译数据（通过集成测试验证）
     */
    public function test_group_translations_by_file_target_integration()
    {
        $externalTranslations = [
            ['key' => 'welcome', 'value' => 'Welcome', 'file_type' => 'json'],
            ['key' => 'auth.login', 'value' => 'Login', 'file_type' => 'php'],
            ['key' => 'auth.logout', 'value' => 'Logout', 'file_type' => 'php'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->with(['language' => 'en'])
            ->andReturn($externalTranslations);

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
    }

    /**
     * 测试文件组处理（通过集成测试验证）
     */
    public function test_process_file_group_integration()
    {
        $externalTranslations = [
            ['key' => 'welcome', 'value' => 'Welcome', 'file_type' => 'json'],
            ['key' => 'goodbye', 'value' => 'Goodbye', 'file_type' => 'json']
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->with(['language' => 'en'])
            ->andReturn($externalTranslations);

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('✅ 已更新 en');
    }

    /**
     * 测试翻译数据格式化
     */
    public function test_format_translations_for_local()
    {
        $translations = [
            ['key' => 'welcome', 'value' => 'Welcome'],
            ['key' => 'goodbye', 'value' => 'Goodbye'],
            ['key' => '', 'value' => 'Invalid'], // 应该被忽略
        ];

        $result = $this->invokeMethod('formatTranslationsForLocal', [$translations]);

        $expected = [
            'welcome' => 'Welcome',
            'goodbye' => 'Goodbye'
        ];

        $this->assertEquals($expected, $result);
    }

    /**
     * 测试混合格式处理
     */
    public function test_mixed_format_processing()
    {
        $externalTranslations = [
            ['key' => 'welcome', 'value' => 'Welcome', 'file_type' => 'json'],
            ['key' => 'auth.login', 'value' => 'Login', 'file_type' => 'php'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->with(['language' => 'en'])
            ->andReturn($externalTranslations);

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
    }

    /**
     * 测试干跑模式
     */
    public function test_dry_run_mode()
    {
        $externalTranslations = [
            ['key' => 'test.key', 'value' => 'Test Value', 'file_type' => 'json'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldNotReceive('put'); // 干跑模式不应该写文件

        $this->artisan('translation:sync', [
            '--language' => ['en'],
            '--dry-run' => true,
        ])
            ->assertExitCode(0);
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

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
            '--merge-mode' => 'merge',
            '--auto-detect-format' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('📊 同步配置:');
    }

    /**
     * 测试合并模式
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

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
            '--merge-mode' => 'merge',
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
    }

    /**
     * 测试覆盖模式
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

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
            '--merge-mode' => 'overwrite',
            '--force' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
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

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['fr'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: fr');
    }

    /**
     * 测试自动格式检测
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

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
            '--auto-detect-format' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
    }

    /**
     * 测试强制模式
     */
    public function test_force_mode()
    {
        $externalTranslations = [
            ['key' => 'test.key', 'value' => 'Test Value'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        File::shouldReceive('exists')->andReturn(true); // 文件存在
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
            '--force' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
    }

    /**
     * 测试空的外部响应与多语言
     */
    public function test_empty_external_with_multiple_languages()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->times(2)
            ->andReturn([]);

        $this->artisan('translation:sync', [
            '--language' => ['en', 'fr'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('  - 没有找到 en 的翻译')
            ->expectsOutput('  - 没有找到 fr 的翻译');
    }

    /**
     * 测试完整的同步流程
     */
    public function test_complete_sync_workflow()
    {
        $externalTranslations = [
            ['key' => 'welcome', 'value' => 'Welcome', 'file_type' => 'json'],
            ['key' => 'auth.login', 'value' => 'Login', 'file_type' => 'php'],
            ['key' => 'auth.logout', 'value' => 'Logout', 'file_type' => 'php'],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->with(['language' => 'en'])
            ->andReturn($externalTranslations);

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('🔄 开始同步翻译文件...')
            ->expectsOutput('处理语言: en')
            ->expectsOutput('✅ 翻译同步完成!');
    }

    /**
     * 测试JSON格式默认检测
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

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
    }

    /**
     * 测试无效翻译数据过滤
     */
    public function test_invalid_translation_data_filtering()
    {
        $externalTranslations = [
            // 有效数据
            ['key' => 'valid', 'value' => 'Valid'],
            // 无效数据将被过滤
            ['key' => '', 'value' => 'Empty key'],
            ['key' => 'empty_value', 'value' => ''],
            ['value' => 'No key'],
            ['key' => 'invalid_php', 'value' => 'Value', 'file_type' => 'php'], // PHP但没有点号
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockApiClient->shouldReceive('getTranslations')
            ->once()
            ->andReturn($externalTranslations);

        File::shouldReceive('exists')->andReturn(false);
        File::shouldReceive('makeDirectory')->andReturn(true);
        File::shouldReceive('put')->andReturn(true);

        $this->artisan('translation:sync', [
            '--language' => ['en'],
        ])
            ->assertExitCode(0)
            ->expectsOutput('处理语言: en');
    }
}
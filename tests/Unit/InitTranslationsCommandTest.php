<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Console\Commands\InitTranslationsCommand;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Mockery;

class InitTranslationsCommandTest extends TestCase
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
                'zh_CN' => '简体中文',
                'fr' => 'Français',
            ],
            'external_api' => [
                'retry_sleep' => 100,
            ],
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
        $command = new InitTranslationsCommand($this->mockCollector, $this->mockApiClient);
        
        $this->assertInstanceOf(InitTranslationsCommand::class, $command);
    }

    /**
     * 测试API连接失败
     */
    public function test_api_connection_failure()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(false);

        $this->artisan('translation:init')
            ->assertExitCode(1)
            ->expectsOutput('❌ 无法连接到外部翻译系统');
    }

    /**
     * 测试基本的初始化功能
     */
    public function test_basic_initialization()
    {
        $translations = [
            [
                'key' => 'user.login',
                'default_text' => 'Login',
                'language' => 'en',
                'file_type' => 'json',
                'module' => 'User',
                'source_file' => '/lang/en.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
            [
                'key' => 'user.logout',
                'default_text' => 'Logout', 
                'language' => 'en',
                'file_type' => 'json',
                'module' => 'User',
                'source_file' => '/lang/en.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->times(3) // 对应3种语言
            ->andReturn($translations);
        $this->mockApiClient->shouldReceive('initTranslations')
            ->once()
            ->with(Mockery::type('array'))
            ->andReturn(['success' => true]);

        $this->artisan('translation:init', ['--force' => true])
            ->assertExitCode(0)
            ->expectsOutput('🚀 开始初始化项目翻译到外部系统...')
            ->expectsOutput('✅ 翻译初始化完成!');
    }

    /**
     * 测试指定特定语言
     */
    public function test_specific_language_initialization()
    {
        $translations = [
            [
                'key' => 'hello',
                'default_text' => 'Bonjour',
                'language' => 'fr',
                'file_type' => 'json',
                'module' => null,
                'source_file' => '/lang/fr.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->once()
            ->with('fr')
            ->andReturn($translations);
        $this->mockApiClient->shouldReceive('initTranslations')
            ->once()
            ->andReturn(['success' => true]);

        $this->artisan('translation:init', [
            '--language' => ['fr'],
            '--force' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('  - 目标语言: fr');
    }

    /**
     * 测试干跑模式
     */
    public function test_dry_run_mode()
    {
        $translations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test Value',
                'language' => 'en',
                'file_type' => 'json',
                'module' => null,
                'source_file' => '/lang/en.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->times(3)
            ->andReturn($translations);
        $this->mockApiClient->shouldNotReceive('initTranslations'); // 干跑模式不应该调用API

        $this->artisan('translation:init', ['--dry-run' => true])
            ->assertExitCode(0)
            ->expectsOutput('🔍 干跑模式：仅显示将要初始化的内容');
    }

    /**
     * 测试批量上传
     */
    public function test_batch_upload()
    {
        $translations = [];
        for ($i = 1; $i <= 150; $i++) {
            $translations[] = [
                'key' => "test.key{$i}",
                'default_text' => "Test Value {$i}",
                'language' => 'en',
                'file_type' => 'json',
                'module' => null,
                'source_file' => '/lang/en.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ];
        }

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        
        // 每种语言返50条数据，总共150条
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('en')
            ->once()
            ->andReturn(array_slice($translations, 0, 50));
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('zh_CN')
            ->once()
            ->andReturn(array_slice($translations, 50, 50));
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('fr')
            ->once()
            ->andReturn(array_slice($translations, 100, 50));
        
        // 应该分批调用API (150条数据，批量大小50，应该调用3次)
        $this->mockApiClient->shouldReceive('initTranslations')
            ->times(3)
            ->andReturn(['success' => true]);

        $this->artisan('translation:init', [
            '--batch-size' => 50,
            '--force' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutput('  - 处理批次 1/3 (50 条翻译)')
            ->expectsOutput('  - 处理批次 2/3 (50 条翻译)')
            ->expectsOutput('  - 处理批次 3/3 (50 条翻译)');
    }

    /**
     * 测试空的本地翻译
     */
    public function test_empty_local_translations()
    {
        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->times(3)
            ->andReturn([]);

        $this->artisan('translation:init')
            ->assertExitCode(0)
            ->expectsOutput('📝 没有找到本地翻译文件');
    }

    /**
     * 测试不支持的语言
     */
    public function test_unsupported_language()
    {
        $this->artisan('translation:init', ['--language' => ['invalid']])
            ->assertExitCode(1);
    }

    /**
     * 测试API异常处理
     */
    public function test_api_exception_handling()
    {
        $translations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test Value',
                'language' => 'en',
                'file_type' => 'json',
                'module' => null,
                'source_file' => '/lang/en.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('en')
            ->once()
            ->andReturn($translations);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('zh_CN')
            ->once()
            ->andReturn([]);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('fr')
            ->once()
            ->andReturn([]);
        $this->mockApiClient->shouldReceive('initTranslations')
            ->once()
            ->andThrow(new \Exception('API异常'));

        $this->artisan('translation:init', ['--force' => true])
            ->assertExitCode(0)
            ->expectsOutput('    ❌ 批次 1 上传失败: API异常')
            ->expectsOutput('✅ 翻译初始化完成!');
    }

    /**
     * 测试统计信息显示
     */
    public function test_statistics_display()
    {
        $enTranslations = [
            [
                'key' => 'test.json',
                'default_text' => 'JSON Test',
                'language' => 'en',
                'file_type' => 'json',
                'module' => 'Test',
                'source_file' => '/lang/en.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];
        
        $zhTranslations = [
            [
                'key' => 'test.php',
                'default_text' => 'PHP Test',
                'language' => 'zh_CN',
                'file_type' => 'php',
                'module' => 'Test',
                'source_file' => '/lang/zh_CN/test.php',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('en')
            ->once()
            ->andReturn($enTranslations);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('zh_CN')
            ->once()
            ->andReturn($zhTranslations);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->with('fr')
            ->once()
            ->andReturn([]);
        $this->mockApiClient->shouldReceive('initTranslations')
            ->once()
            ->andReturn(['success' => true]);

        $this->artisan('translation:init', ['--force' => true])
            ->assertExitCode(0)
            ->expectsOutput('📈 扫描统计:')
            ->expectsOutput('  - English (en): 1 条翻译')
            ->expectsOutput('  - 简体中文 (zh_CN): 1 条翻译')
            ->expectsOutput('📁 文件类型统计:')
            ->expectsOutput('  - json: 1 条翻译')
            ->expectsOutput('  - php: 1 条翻译')
            ->expectsOutput('📊 总计: 2 条翻译');
    }

    /**
     * 测试取消确认
     */
    public function test_cancel_confirmation()
    {
        $translations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test Value',
                'language' => 'en',
                'file_type' => 'json',
                'module' => null,
                'source_file' => '/lang/en.json',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];

        $this->mockApiClient->shouldReceive('checkConnection')->once()->andReturn(true);
        $this->mockCollector->shouldReceive('scanExistingTranslations')
            ->times(3)
            ->andReturn($translations);

        $this->artisan('translation:init')
            ->expectsQuestion('确定要初始化这些翻译到外部系统吗？', false)
            ->assertExitCode(0)
            ->expectsOutput('⏹️ 已取消初始化操作');
    }
}
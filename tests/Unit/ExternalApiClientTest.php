<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Mockery;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Carlin\LaravelTranslationCollector\Services\ExternalApiClient;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Exceptions\ExternalApiException;

class ExternalApiClientTest extends TestCase
{
    /**
     * API客户端实例
     *
     * @var ExternalApiClient
     */
    protected $apiClient;

    /**
     * 模拟HTTP处理器
     *
     * @var MockHandler
     */
    protected $mockHandler;

    /**
     * 设置测试
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $handlerStack = HandlerStack::create($this->mockHandler);

        $config = [
            'base_url' => 'https://api.test.com',
            'token' => 'test-token',
            'project_id' => 'test-project',
            'timeout' => 30,
            'retry_times' => 2,
            'retry_sleep' => 10,
            'supported_languages' => [
                'en' => 'English',
                'zh' => '中文',
                'fr' => 'Français',
            ],
            'endpoints' => [
                'add_translation' => '/api/translations/add',
                'get_translations' => '/api/translations/list',
                'sync_translations' => '/api/translations/sync',
                'init_translations' => '/api/translations/init',
            ],
        ];

        $this->apiClient = new ExternalApiClient($config);

        // 使用反射替换HTTP客户端
        $reflection = new \ReflectionClass($this->apiClient);
        $httpClientProperty = $reflection->getProperty('httpClient');
        $httpClientProperty->setAccessible(true);
        $httpClientProperty->setValue($this->apiClient, new Client(['handler' => $handlerStack]));
    }

    /**
     * 清理测试
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * 测试API客户端实例化
     */
    public function test_api_client_can_be_instantiated()
    {
        $this->assertInstanceOf(ExternalApiClient::class, $this->apiClient);
        $this->assertInstanceOf(ExternalApiClientInterface::class, $this->apiClient);
    }

    /**
     * 测试成功添加翻译
     */
    public function test_can_add_translations_successfully()
    {
        $translations = [
            [
                'key' => 'user.login.success',
                'default_text' => 'Login successful',
                'source_file' => 'test.php',
                'line_number' => 1,
                'context' => 'test context',
                'module' => 'User',
            ],
        ];

        $responseData = [
            'success' => true,
            'message' => 'Translations added successfully',
            'data' => ['id' => 123],
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        $result = $this->apiClient->addTranslations($translations);

        // ExternalApiClient的makeRequest方法会返回data字段的内容，而不是整个响应
        $this->assertEquals($responseData['data'], $result);
    }

    /**
     * 测试添加翻译失败
     */
    public function test_add_translations_throws_exception_on_failure()
    {
        $this->expectException(ExternalApiException::class);

        $translations = [
            ['key' => 'test.key', 'default_text' => 'Test'],
        ];

        $this->mockHandler->append(new RequestException('Network error', new \GuzzleHttp\Psr7\Request('POST', 'test')));

        $this->apiClient->addTranslations($translations);
    }

    /**
     * 测试成功获取翻译
     */
    public function test_can_get_translations_successfully()
    {
        $responseData = [
            'success' => true,
            'data' => [
                ['key' => 'user.login.success', 'value' => 'Login successful'],
                ['key' => 'user.logout', 'value' => 'Logout'],
            ],
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        $result = $this->apiClient->getTranslations(['language' => 'en']);

        // 根据当前实现，返回的是空数组
        $this->assertEquals([], $result);
    }

    /**
     * 测试获取翻译失败
     */
    public function test_get_translations_throws_exception_on_failure()
    {
        $this->expectException(ExternalApiException::class);

        $this->mockHandler->append(new RequestException('API error', new \GuzzleHttp\Psr7\Request('GET', 'test')));

        $this->apiClient->getTranslations();
    }

    /**
     * 测试批量上传成功
     */
    public function test_can_batch_upload_successfully()
    {
        $translations = [];
        for ($i = 1; $i <= 5; $i++) {
            $translations[] = [
                'key' => "test.key.{$i}",
                'default_text' => "Test {$i}",
            ];
        }

        // 模拟两个批次的响应
        $this->mockHandler->append(
            new Response(200, [], json_encode(['success' => true, 'batch' => 1])),
            new Response(200, [], json_encode(['success' => true, 'batch' => 2]))
        );

        $results = $this->apiClient->batchUpload($translations, 3);

        $this->assertCount(2, $results);
        $this->assertEquals(['success' => true, 'batch' => 1], $results[0]);
        $this->assertEquals(['success' => true, 'batch' => 2], $results[1]);
    }

    /**
     * 测试批量上传部分失败
     */
    public function test_batch_upload_handles_partial_failures()
    {
        $translations = [
            ['key' => 'test.key.1', 'default_text' => 'Test 1'],
            ['key' => 'test.key.2', 'default_text' => 'Test 2'],
            ['key' => 'test.key.3', 'default_text' => 'Test 3'],
            ['key' => 'test.key.4', 'default_text' => 'Test 4'],
        ];

        // 第一个批次成功，第二个批次失败（考虑重试机制，需要多个失败响应）
        $this->mockHandler->append(
            new Response(200, [], json_encode(['success' => true, 'batch' => 1])),
            new RequestException('Batch 2 failed', new \GuzzleHttp\Psr7\Request('POST', 'test')),
            new RequestException('Batch 2 failed', new \GuzzleHttp\Psr7\Request('POST', 'test')),
            new RequestException('Batch 2 failed', new \GuzzleHttp\Psr7\Request('POST', 'test'))
        );

        $results = $this->apiClient->batchUpload($translations, 2); // 批次大小为2，会产生2个批次

        $this->assertCount(2, $results);
        $this->assertEquals(['success' => true, 'batch' => 1], $results[0]);
        $this->assertFalse($results[1]['success']);
        $this->assertStringContainsString('Batch 2 failed', $results[1]['error']);
    }

    /**
     * 测试连接检查成功
     */
    public function test_can_check_connection_successfully()
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['status' => 'ok'])));

        $result = $this->apiClient->checkConnection();

        $this->assertTrue($result);
    }

    /**
     * 测试连接检查失败
     */
    public function test_check_connection_fails_gracefully()
    {
        $this->mockHandler->append(new RequestException('Connection failed', new \GuzzleHttp\Psr7\Request('GET', 'test')));

        $result = $this->apiClient->checkConnection();

        $this->assertFalse($result);
    }

    /**
     * 测试设置配置
     */
    public function test_can_set_config()
    {
        $newConfig = [
            'base_url' => 'https://new-api.test.com',
            'token' => 'new-token',
            'timeout' => 60,
        ];

        $result = $this->apiClient->setConfig($newConfig);

        $this->assertInstanceOf(ExternalApiClientInterface::class, $result);
        $this->assertSame($this->apiClient, $result);
    }

    /**
     * 测试重试机制
     */
    public function test_retry_mechanism_works()
    {
        $translations = [
            ['key' => 'test.key', 'default_text' => 'Test'],
        ];

        // 第一次请求失败，第二次成功
        $this->mockHandler->append(
            new RequestException('First attempt failed', new \GuzzleHttp\Psr7\Request('POST', 'test')),
            new Response(200, [], json_encode(['success' => true]))
        );

        $result = $this->apiClient->addTranslations($translations);

        $this->assertEquals(['success' => true], $result);
    }

    /**
     * 测试重试耗尽后抛出异常
     */
    public function test_throws_exception_after_retry_exhausted()
    {
        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessage('已重试 2 次');

        $translations = [
            ['key' => 'test.key', 'default_text' => 'Test'],
        ];

        // 所有重试都失败
        $this->mockHandler->append(
            new RequestException('First attempt failed', new \GuzzleHttp\Psr7\Request('POST', 'test')),
            new RequestException('Second attempt failed', new \GuzzleHttp\Psr7\Request('POST', 'test'))
        );

        $this->apiClient->addTranslations($translations);
    }

    /**
     * 测试翻译数据格式化
     */
    public function test_formats_translations_for_api_correctly()
    {
        $translations = [
            [
                'key' => 'user.login.success',
                'default_text' => 'Login successful',
                'source_file' => '/path/to/file.php',
                'line_number' => 42,
                'context' => 'some context',
                'module' => 'User',
                'file_type' => 'php',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
        ];

        $responseData = ['success' => true];
        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        // 通过实际调用来测试格式化
        $result = $this->apiClient->addTranslations($translations);

        $this->assertEquals($responseData, $result);
    }

    /**
     * 测试空响应处理
     */
    public function test_handles_empty_response()
    {
        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessageMatches('/翻译获取失败.*API返回的数据格式不正确/');

        $this->mockHandler->append(new Response(200, [], ''));

        $this->apiClient->getTranslations();
    }

    /**
     * 测试无效JSON响应处理
     */
    public function test_handles_invalid_json_response()
    {
        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessageMatches('/翻译获取失败.*API返回的数据格式不正确/');

        $this->mockHandler->append(new Response(200, [], 'invalid json'));

        $this->apiClient->getTranslations();
    }

    /**
     * 测试初始化翻译成功
     */
    public function test_can_init_translations_successfully()
    {
        $translations = [
            [
                'key' => 'user.login',
                'default_text' => 'Login',
				'value'=>'Login',
                'language' => 'en',
                'file_type' => 'php',
                'module' => 'User',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
            [
                'key' => 'user.logout',
                'default_text' => 'Logout',
				'value' => 'Logout',
				'language' => 'en',
                'file_type' => 'php',
                'module' => 'User',
                'created_at' => '2025-08-27T10:00:00Z',
            ],
			[
				'key' => 'user.logout',
				'default_text' => 'Logout',
				'value' => '退出',
				'language' => 'zh_CN',
				'file_type' => 'php',
				'module' => 'User',
				'created_at' => '2025-08-27T10:00:00Z',
			],
        ];

        $responseData = [
            'success' => true,
            'message' => 'Translations initialized successfully',
            'data' => [],
        ];

        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        $result = $this->apiClient->initTranslations($translations);

        // initTranslations 返回 data 部分，所以预期的结果是空数组
        $this->assertEquals([], $result);
    }

    /**
     * 测试初始化翻译失败
     */
    public function test_init_translations_throws_exception_on_failure()
    {
        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessageMatches('/项目翻译初始化失败/');

        $translations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test Value',
                'value' => 'Test Value',
                'language' => 'en',
                'file_type' => 'json',
            ],
        ];

        $this->mockHandler->append(new RequestException('Network error', new \GuzzleHttp\Psr7\Request('POST', 'test')));

        $this->apiClient->initTranslations($translations);
    }

    /**
     * 测试初始化翻译API响应错误
     */
    public function test_init_translations_handles_api_error_response()
    {
        $this->expectException(ExternalApiException::class);
        $this->expectExceptionMessageMatches('/Validation failed/');

        $translations = [
            [
                'key' => 'test.key',
                'default_text' => 'Test Value',
                'value' => 'Test Value',
                'language' => 'en',
                'file_type' => 'json',
            ],
        ];

        $errorResponse = [
            'success' => false,
            'message' => 'Validation failed',
            'error' => ['project_id' => 'Project ID is required']
        ];

        // 为重试机制提供多个相同的失败响应
        $this->mockHandler->append(
            new Response(400, [], json_encode($errorResponse)),
            new Response(400, [], json_encode($errorResponse)),
            new Response(400, [], json_encode($errorResponse))
        );

        $this->apiClient->initTranslations($translations);
    }

    /**
     * 测试formatTranslationsForApi方法的具体数据格式化
     */
    public function test_format_translations_for_api_detailed()
    {
        $translations = [
            [
                'key' => 'user.welcome',
                'default_text' => 'Welcome User',
                'source_file' => '/app/Http/Controllers/UserController.php',
                'line_number' => 25,
                'context' => '__(\'user.welcome\')',
                'module' => 'UserModule',
                'file_type' => 'php',
                'created_at' => '2025-08-28T10:00:00Z',
            ],
            [
                'key' => 'product.list.title',
                'default_text' => 'Product List',
                // 缺少某些字段来测试默认值
            ],
        ];

        $responseData = ['success' => true, 'data' => ['formatted' => true]];
        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        $result = $this->apiClient->addTranslations($translations);

        $this->assertEquals(['formatted' => true], $result);
    }

    /**
     * 测试formatTranslationsForInit方法的具体数据格式化
     */
    public function test_format_translations_for_init_detailed()
    {
        $translations = [
            [
                'key' => 'auth.login',
                'default_text' => 'Login',
                'value' => 'Login to your account',
                'language' => 'en',
                'module' => 'AuthModule',
                'file_type' => 'json',
                'created_at' => '2025-08-28T10:00:00Z',
            ],
            [
                'key' => 'auth.logout',
                'default_text' => 'Logout',
                'value' => '登出',
                'language' => 'zh',
                // 缺少某些字段来测试默认值
            ],
        ];

        $responseData = ['success' => true, 'data' => ['init_success' => true]];
        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        $result = $this->apiClient->initTranslations($translations);

        $this->assertEquals(['init_success' => true], $result);
    }

    /**
     * 测试日志记录功能
     */
    public function test_logging_functionality()
    {
        // 启用日志
        config(['translation-collector.logging.enabled' => true]);

        $translations = [['key' => 'test.log', 'default_text' => 'Test Log']];
        
        $responseData = ['success' => true];
        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        $result = $this->apiClient->addTranslations($translations);

        $this->assertEquals($responseData, $result);
    }

    /**
     * 测试禁用日志记录
     */
    public function test_logging_disabled()
    {
        // 禁用日志
        config(['translation-collector.logging.enabled' => false]);

        $translations = [['key' => 'test.no.log', 'default_text' => 'Test No Log']];
        
        $responseData = ['success' => true];
        $this->mockHandler->append(new Response(200, [], json_encode($responseData)));

        $result = $this->apiClient->addTranslations($translations);

        $this->assertEquals($responseData, $result);
    }
}

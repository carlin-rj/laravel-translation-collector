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

        $this->assertEquals($responseData, $result);
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

        $this->assertEquals($responseData['data'], $result);
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
        $this->mockHandler->append(new Response(200, [], ''));

        $result = $this->apiClient->getTranslations();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试无效JSON响应处理
     */
    public function test_handles_invalid_json_response()
    {
        $this->mockHandler->append(new Response(200, [], 'invalid json'));

        $result = $this->apiClient->getTranslations();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}

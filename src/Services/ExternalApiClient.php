<?php

namespace Carlin\LaravelTranslationCollector\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;
use Carlin\LaravelTranslationCollector\Exceptions\ExternalApiException;

class ExternalApiClient implements ExternalApiClientInterface
{
    /**
     * HTTP客户端
     *
     * @var Client
     */
    protected $httpClient;

    /**
     * API配置
     *
     * @var array
     */
    protected $config;

    /**
     * 构造函数
     *
     * @param array $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->httpClient = new Client([
            'base_uri' => $this->config['base_url'],
            'timeout' => $this->config['timeout'] ?? 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['token'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * 新增待翻译文本到外部系统
     *
     * @param array $translations 翻译数据
     * @return array
     */
    public function addTranslations(array $translations): array
    {
        try {
            $this->log('info', '开始向外部系统新增翻译', ['count' => count($translations)]);

            $endpoint = $this->config['endpoints']['add_translation'];
            $payload = [
                'project_id' => $this->config['project_id'],
                'languages' =>array_keys($this->config['supported_languages']),
                'translations' => $this->formatTranslationsForApi($translations),
            ];

            $response = $this->makeRequest('POST', $endpoint, $payload);

            $this->log('info', '翻译新增成功', ['response' => $response]);

            return $response;

        } catch (\Exception $e) {
            $this->log('error', '翻译新增失败: ' . $e->getMessage());
            throw new ExternalApiException('翻译新增失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 从外部系统获取所有翻译
     *
     * @param array $options 查询选项
     * @return array
     */
    public function getTranslations(array $options = []): array
    {
        try {
            $this->log('info', '开始从外部系统获取翻译');

            $endpoint = $this->config['endpoints']['get_translations'];
            $queryParams = array_merge([
                'project_id' => $this->config['project_id'],
            ], $options);

            $response = $this->makeRequest('GET', $endpoint, null, $queryParams);

            $this->log('info', '翻译获取成功', ['count' => count($response['data'] ?? [])]);

            return $response['data'] ?? [];

        } catch (\Exception $e) {
            $this->log('error', '翻译获取失败: ' . $e->getMessage());
            throw new ExternalApiException('翻译获取失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 同步翻译到外部系统
     *
     * @param array $translations 翻译数据
     * @return array
     */
    public function syncTranslations(array $translations): array
    {
        try {
            $this->log('info', '开始同步翻译到外部系统', ['count' => count($translations)]);

            $endpoint = $this->config['endpoints']['sync_translations'];
            $payload = [
                'project_id' => $this->config['project_id'],
                'translations' => $this->formatTranslationsForApi($translations),
                'sync_mode' => 'merge', // merge, replace, append
            ];

            $response = $this->makeRequest('POST', $endpoint, $payload);

            $this->log('info', '翻译同步成功', ['response' => $response]);

            return $response;

        } catch (\Exception $e) {
            $this->log('error', '翻译同步失败: ' . $e->getMessage());
            throw new ExternalApiException('翻译同步失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 批量上传翻译
     *
     * @param array $translations 翻译数据
     * @param int $batchSize 批量大小
     * @return array
     */
    public function batchUpload(array $translations, int $batchSize = 100): array
    {
        $results = [];
        $batches = array_chunk($translations, $batchSize);

        $this->log('info', '开始批量上传翻译', [
            'total_count' => count($translations),
            'batch_size' => $batchSize,
            'total_batches' => count($batches),
        ]);

        foreach ($batches as $index => $batch) {
            try {
                $this->log('info', "处理批次 " . ($index + 1) . "/" . count($batches));

                $result = $this->addTranslations($batch);
                $results[] = $result;

                // 添加延迟避免API限流
                if ($index < count($batches) - 1) {
                    usleep(($this->config['retry_sleep'] ?? 100) * 1000);
                }

            } catch (\Exception $e) {
                $this->log('error', "批次 " . ($index + 1) . " 上传失败: " . $e->getMessage());
                $results[] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                    'batch_index' => $index + 1,
                ];
            }
        }

        return $results;
    }

    /**
     * 检查外部API连接状态
     *
     * @return bool
     */
    public function checkConnection(): bool
    {
        try {
            $response = $this->makeRequest('GET', '/api/health');
            return isset($response['status']) && $response['status'] === 'ok';

        } catch (\Exception $e) {
            $this->log('warning', 'API连接检查失败: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 获取外部系统支持的语言列表
     *
     * @return array
     */
    public function getSupportedLanguages(): array
    {
        try {
            $response = $this->makeRequest('GET', '/api/languages');
            return $response['data'] ?? [];

        } catch (\Exception $e) {
            $this->log('error', '获取支持的语言列表失败: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * 设置API配置
     *
     * @param array $config
     * @return self
     */
    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);

        // 重新初始化HTTP客户端
        $this->httpClient = new Client([
            'base_uri' => $this->config['base_url'],
            'timeout' => $this->config['timeout'] ?? 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->config['token'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        return $this;
    }

    /**
     * 发送HTTP请求
     *
     * @param string $method
     * @param string $endpoint
     * @param array|null $data
     * @param array $queryParams
     * @return array
     * @throws ExternalApiException
     */
    protected function makeRequest(string $method, string $endpoint, ?array $data = null, array $queryParams = []): array
    {
        $options = [];

        if ($data) {
            $options['json'] = $data;
        }

        if ($queryParams) {
            $options['query'] = $queryParams;
        }

        $retryTimes = $this->config['retry_times'] ?? 3;
        $retrySleep = $this->config['retry_sleep'] ?? 100;

        for ($attempt = 1; $attempt <= $retryTimes; $attempt++) {
            try {
                $response = $this->httpClient->request($method, $endpoint, $options);
                $body = $response->getBody()->getContents();

                return json_decode($body, true) ?: [];

            } catch (RequestException $e) {
                $this->log('warning', "API请求失败 (尝试 {$attempt}/{$retryTimes}): " . $e->getMessage());

                if ($attempt === $retryTimes) {
                    throw new ExternalApiException(
                        "API请求失败，已重试 {$retryTimes} 次: " . $e->getMessage(),
                        $e->getCode(),
                        $e
                    );
                }

                // 等待后重试
                usleep($retrySleep * 1000 * $attempt);
            }
        }

        throw new ExternalApiException('未知的API请求错误');
    }

    /**
     * 格式化翻译数据为API格式
     *
     * @param array $translations
     * @return array
     */
    protected function formatTranslationsForApi(array $translations): array
    {
        return array_map(function ($translation) {
            return [
                'key' => $translation['key'],
                'default_text' => $translation['default_text'],
                'source_file' => $translation['source_file'] ?? '',
                'line_number' => $translation['line_number'] ?? 0,
                'context' => $translation['context'] ?? '',
                'module' => $translation['module'] ?? '',
                'metadata' => [
                    'file_type' => $translation['file_type'] ?? '',
                    'created_at' => $translation['created_at'] ?? now()->toISOString(),
                ],
            ];
        }, $translations);
    }

    /**
     * 记录日志
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        $config = config('translation-collector.logging', ['enabled' => true]);

        if (!$config['enabled']) {
            return;
        }

        $channel = $config['channel'] ?? 'daily';

        Log::channel($channel)->{$level}("[ExternalApiClient] {$message}", $context);
    }
}

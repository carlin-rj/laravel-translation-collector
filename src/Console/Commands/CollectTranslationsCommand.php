<?php

namespace Carlin\LaravelTranslationCollector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;

class CollectTranslationsCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'translation:collect
                            {--module=* : 指定要扫描的模块}
                            {--path=* : 指定要扫描的路径}
                            {--dry-run : 仅扫描不上传}
                            {--format=json : 输出格式 (json, table, csv)}
                            {--output= : 输出到文件}
                            {--no-cache : 不使用缓存}
                            {--upload : 自动上传到外部系统}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '收集项目中的翻译文本';

    /**
     * 翻译收集器
     *
     * @var TranslationCollectorInterface
     */
    protected $collector;

    /**
     * 外部API客户端
     *
     * @var ExternalApiClientInterface
     */
    protected $apiClient;

    /**
     * 构造函数
     *
     * @param TranslationCollectorInterface $collector
     * @param ExternalApiClientInterface $apiClient
     */
    public function __construct(
        TranslationCollectorInterface $collector,
        ExternalApiClientInterface $apiClient
    ) {
        parent::__construct();
        $this->collector = $collector;
        $this->apiClient = $apiClient;
    }

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('🔍 开始收集翻译文本...');

        try {
            // 设置收集选项
            $options = $this->buildCollectionOptions();
            
            // 执行收集
            $translations = $this->performCollection($options);

            // 显示统计信息
            $this->displayStatistics();

            // 处理输出
            $this->handleOutput($translations);

            // 上传到外部系统
            if ($this->option('upload') && !$this->option('dry-run')) {
                $this->uploadToExternalSystem($translations);
            }

            $this->info('✅ 翻译收集完成!');
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ 翻译收集失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 构建收集选项
     *
     * @return array
     */
    protected function buildCollectionOptions(): array
    {
        $options = [];

        // 指定模块
        if ($modules = $this->option('module')) {
            $options['modules'] = $modules;
        }

        // 指定路径
        if ($paths = $this->option('path')) {
            $options['paths'] = $paths;
        }

        // 缓存选项
        if ($this->option('no-cache')) {
            $options['use_cache'] = false;
        }

        return $options;
    }

    /**
     * 执行收集
     *
     * @param array $options
     * @return array
     */
    protected function performCollection(array $options): array
    {
        $translations = [];

        // 进度条
        $progressBar = $this->output->createProgressBar();
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $progressBar->setMessage('初始化...');
        $progressBar->start();

        try {
            $this->collector->setOptions($options);

            // 如果指定了模块，只扫描模块
            if (isset($options['modules'])) {
                $progressBar->setMessage('扫描模块...');
                $translations = $this->collector->scanModules($options['modules']);
            } else {
                $progressBar->setMessage('扫描项目...');
                $translations = $this->collector->collect($options);
            }

            $progressBar->setMessage('完成');
            $progressBar->finish();
            $this->newLine(2);

            return $translations;

        } catch (\Exception $e) {
            $progressBar->finish();
            $this->newLine(2);
            throw $e;
        }
    }

    /**
     * 显示统计信息
     */
    protected function displayStatistics(): void
    {
        $stats = $this->collector->getStatistics();

        $this->info('📊 统计信息:');
        $this->table(
            ['项目', '数量'],
            [
                ['扫描文件数', number_format($stats['total_files_scanned'])],
                ['发现翻译数', number_format($stats['total_translations_found'])],
                ['新增翻译数', number_format($stats['new_translations'])],
                ['已存在翻译数', number_format($stats['existing_translations'])],
                ['扫描耗时', round($stats['scan_duration'], 2) . ' 秒'],
            ]
        );
    }

    /**
     * 处理输出
     *
     * @param array $translations
     */
    protected function handleOutput(array $translations): void
    {
        $format = $this->option('format');
        $output = $this->option('output');

        switch ($format) {
            case 'table':
                $this->displayAsTable($translations);
                break;
            case 'csv':
                $content = $this->formatAsCsv($translations);
                break;
            case 'json':
            default:
                $content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
        }

        // 输出到文件
        if ($output && isset($content)) {
            $this->saveToFile($output, $content);
        } elseif (isset($content)) {
            $this->line($content);
        }
    }

    /**
     * 表格形式显示翻译
     *
     * @param array $translations
     */
    protected function displayAsTable(array $translations): void
    {
        if (empty($translations)) {
            $this->warn('没有找到翻译文本');
            return;
        }

        $headers = ['翻译键', '模块', '文件', '行号'];
        $rows = [];

        foreach (array_slice($translations, 0, 50) as $translation) {
            $rows[] = [
                $translation['key'],
                $translation['module'] ?? 'N/A',
                basename($translation['source_file'] ?? ''),
                $translation['line_number'] ?? 'N/A',
            ];
        }

        $this->table($headers, $rows);

        if (count($translations) > 50) {
            $this->info('显示前50条记录，总共 ' . count($translations) . ' 条');
        }
    }

    /**
     * CSV格式化
     *
     * @param array $translations
     * @return string
     */
    protected function formatAsCsv(array $translations): string
    {
        $csv = "翻译键,默认文本,模块,文件,行号,上下文\n";
        
        foreach ($translations as $translation) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s","%s"' . "\n",
                $translation['key'],
                $translation['default_text'] ?? '',
                $translation['module'] ?? '',
                basename($translation['source_file'] ?? ''),
                $translation['line_number'] ?? '',
                str_replace('"', '""', $translation['context'] ?? '')
            );
        }

        return $csv;
    }

    /**
     * 保存到文件
     *
     * @param string $path
     * @param string $content
     */
    protected function saveToFile(string $path, string $content): void
    {
        $directory = dirname($path);
        
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, $content);
        $this->info("✅ 结果已保存到: {$path}");
    }

    /**
     * 上传到外部系统
     *
     * @param array $translations
     */
    protected function uploadToExternalSystem(array $translations): void
    {
        if (empty($translations)) {
            $this->warn('没有翻译需要上传');
            return;
        }

        $this->info('🚀 开始上传到外部翻译系统...');

        // 检查连接
        if (!$this->apiClient->checkConnection()) {
            $this->error('❌ 无法连接到外部翻译系统');
            return;
        }

        try {
            // 获取现有翻译
            $existingTranslations = $this->apiClient->getTranslations();
            
            // 分析差异
            $differences = $this->collector->analyzeDifferences($translations, $existingTranslations);
            
            $newCount = count($differences['new']);
            $updatedCount = count($differences['updated']);

            if ($newCount === 0 && $updatedCount === 0) {
                $this->info('✅ 没有新的翻译需要上传');
                return;
            }

            $this->info("📤 准备上传 {$newCount} 个新翻译和 {$updatedCount} 个更新翻译");

            // 上传新翻译
            if ($newCount > 0) {
                $uploadData = array_merge($differences['new'], $differences['updated']);
                $result = $this->apiClient->batchUpload($uploadData);
                
                $successCount = count(array_filter($result, fn($r) => $r['success'] ?? true));
                $this->info("✅ 成功上传 {$successCount} 个翻译");
            }

        } catch (\Exception $e) {
            $this->error("❌ 上传失败: {$e->getMessage()}");
        }
    }
}

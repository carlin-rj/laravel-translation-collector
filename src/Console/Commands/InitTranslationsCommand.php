<?php

namespace Carlin\LaravelTranslationCollector\Console\Commands;

use Illuminate\Console\Command;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;

class InitTranslationsCommand extends Command
{
    /**
     * 命令名称和签名
     *
     * @var string
     */
    protected $signature = 'translation:init
                           {--language=* : 指定初始化的语言，如果不指定则初始化所有支持的语言}
                           {--dry-run : 仅显示将要初始化的内容，不实际执行}
                           {--batch-size=100 : 批量上传的大小}
                           {--force : 强制执行，跳过确认}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '将本地翻译文件初始化到外部翻译系统';

    /**
     * 翻译收集器
     *
     * @var TranslationCollectorInterface
     */
    protected TranslationCollectorInterface $collector;

    /**
     * 外部API客户端
     *
     * @var ExternalApiClientInterface
     */
    protected ExternalApiClientInterface $apiClient;

    /**
     * 配置
     *
     * @var array
     */
    protected array $config;

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
        $this->config = config('translation-collector');
    }

    /**
     * 执行命令
     *
     * @return int
     */
    public function handle(): int
    {
        $this->info('🚀 开始初始化项目翻译到外部系统...');

        try {
            // 检查API连接
            if (!$this->apiClient->checkConnection()) {
                $this->error('❌ 无法连接到外部翻译系统');
                return 1;
            }

            // 获取要初始化的语言
            $languages = $this->getTargetLanguages();
            
            // 显示初始化配置
            $this->displayInitConfiguration($languages);

            // 扫描本地翻译文件
            $translations = $this->scanLocalTranslations($languages);

            if (empty($translations)) {
                $this->warn('📝 没有找到本地翻译文件');
                return 0;
            }

            // 显示统计信息
            $this->displayStatistics($translations);

            // 确认执行
            if (!$this->option('force') && !$this->option('dry-run')) {
                if (!$this->confirm('确定要初始化这些翻译到外部系统吗？')) {
                    $this->info('⏹️ 已取消初始化操作');
                    return 0;
                }
            }

            // 执行初始化
            if (!$this->option('dry-run')) {
                $this->initializeTranslations($translations);
            } else {
                $this->info('🔍 干跑模式：仅显示将要初始化的内容');
                $this->displaySampleTranslations($translations);
            }

            $this->info('✅ 翻译初始化完成!');
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ 翻译初始化失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 获取目标语言
     *
     * @return array
     */
    protected function getTargetLanguages(): array
    {
        $languages = $this->option('language');
        
        if (empty($languages)) {
            $languages = array_keys($this->config['supported_languages']);
        }

        // 验证语言是否支持
        $supportedLanguages = array_keys($this->config['supported_languages']);
        $invalidLanguages = array_diff($languages, $supportedLanguages);
        
        if (!empty($invalidLanguages)) {
            $this->error('❌ 不支持的语言: ' . implode(', ', $invalidLanguages));
            $this->line('支持的语言: ' . implode(', ', $supportedLanguages));
            exit(1);
        }

        return $languages;
    }

    /**
     * 显示初始化配置
     *
     * @param array $languages
     */
    protected function displayInitConfiguration(array $languages): void
    {
        $this->info('📊 初始化配置:');
        $this->line("  - 目标语言: " . implode(', ', $languages));
        $this->line("  - 批量大小: " . $this->option('batch-size'));
        $this->line("  - 干跑模式: " . ($this->option('dry-run') ? '是' : '否'));
        $this->line("  - 强制执行: " . ($this->option('force') ? '是' : '否'));
        $this->line('');
    }

    /**
     * 扫描本地翻译文件
     *
     * @param array $languages
     * @return array
     */
    protected function scanLocalTranslations(array $languages): array
    {
        $this->info('📂 扫描本地翻译文件...');
        
        $translations = [];
        
        foreach ($languages as $language) {
            $this->line("  - 扫描语言: {$language}");
            
            $languageTranslations = $this->collector->scanExistingTranslations($language);
            $translations = array_merge($translations, $languageTranslations);
        }

        return $translations;
    }

    /**
     * 显示统计信息
     *
     * @param array $translations
     */
    protected function displayStatistics(array $translations): void
    {
        $this->info('📈 扫描统计:');
        
        // 按语言分组统计
        $byLanguage = [];
        foreach ($translations as $translation) {
            $language = $translation['language'];
            $byLanguage[$language] = ($byLanguage[$language] ?? 0) + 1;
        }

        foreach ($byLanguage as $language => $count) {
            $languageName = $this->config['supported_languages'][$language] ?? $language;
            $this->line("  - {$languageName} ({$language}): {$count} 条翻译");
        }

        // 按文件类型统计
        $byFileType = [];
        foreach ($translations as $translation) {
            $fileType = $translation['file_type'];
            $byFileType[$fileType] = ($byFileType[$fileType] ?? 0) + 1;
        }

        $this->line('');
        $this->info('📁 文件类型统计:');
        foreach ($byFileType as $fileType => $count) {
            $this->line("  - {$fileType}: {$count} 条翻译");
        }

        $this->line('');
        $this->info("📊 总计: " . count($translations) . " 条翻译");
        $this->line('');
    }

    /**
     * 初始化翻译
     *
     * @param array $translations
     */
    protected function initializeTranslations(array $translations): void
    {
        $this->info('📤 上传翻译到外部系统...');
        
        $batchSize = (int) $this->option('batch-size');
        
        if (count($translations) <= $batchSize) {
            // 单次上传
            $this->initializeBatch($translations, 1, 1);
        } else {
            // 批量上传
            $batches = array_chunk($translations, $batchSize);
            $totalBatches = count($batches);
            
            foreach ($batches as $index => $batch) {
                $this->initializeBatch($batch, $index + 1, $totalBatches);
                
                // 添加延迟避免API限流
                if ($index < $totalBatches - 1) {
                    usleep(($this->config['external_api']['retry_sleep'] ?? 100) * 1000);
                }
            }
        }
    }

    /**
     * 初始化批次
     *
     * @param array $batch
     * @param int $currentBatch
     * @param int $totalBatches
     */
    protected function initializeBatch(array $batch, int $currentBatch, int $totalBatches): void
    {
        try {
            $this->line("  - 处理批次 {$currentBatch}/{$totalBatches} (" . count($batch) . " 条翻译)");
            
            $result = $this->apiClient->initTranslations($batch);
            
            if (isset($result['success']) && $result['success']) {
                $this->line("    ✅ 批次 {$currentBatch} 上传成功");
            } else {
                $this->warn("    ⚠️ 批次 {$currentBatch} 上传响应异常");
            }
            
        } catch (\Exception $e) {
            $this->error("    ❌ 批次 {$currentBatch} 上传失败: {$e->getMessage()}");
        }
    }

    /**
     * 显示示例翻译内容
     *
     * @param array $translations
     */
    protected function displaySampleTranslations(array $translations): void
    {
        $this->info('📄 示例翻译内容（前10条）:');
        
        $sample = array_slice($translations, 0, 10);
        
        $headers = ['键', '值', '语言', '文件类型', '模块'];
        $rows = [];
        
        foreach ($sample as $translation) {
            $rows[] = [
                $translation['key'],
                \Str::limit($translation['default_text'], 30),
                $translation['language'],
                $translation['file_type'],
                $translation['module'] ?? 'N/A',
            ];
        }
        
        $this->table($headers, $rows);
        
        if (count($translations) > 10) {
            $this->line('... 共 ' . count($translations) . ' 条翻译');
        }
    }
}
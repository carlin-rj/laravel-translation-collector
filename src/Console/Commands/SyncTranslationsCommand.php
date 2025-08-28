<?php

namespace Carlin\LaravelTranslationCollector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;

class SyncTranslationsCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'translation:sync
                            {--direction=pull : 同步方向 (pull, push, both)}
                            {--language=* : 指定同步的语言}
                            {--merge-mode=merge : 内容合并模式 (merge, overwrite)}
                            {--dry-run : 仅显示差异，不实际同步}
                            {--force : 强制覆盖本地文件}
                            {--auto-detect-format : 自动检测文件格式根据外部系统返回的file_type}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '与外部翻译系统同步翻译文件，支持智能格式检测和内容合并';

    /**
     * 外部API客户端
     *
     * @var ExternalApiClientInterface
     */
    protected $apiClient;

    /**
     * 配置
     *
     * @var array
     */
    protected $config;

    /**
     * 构造函数
     *
     * @param ExternalApiClientInterface $apiClient
     */
    public function __construct(ExternalApiClientInterface $apiClient)
    {
        parent::__construct();
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
        $this->info('🔄 开始同步翻译文件...');

        try {
            // 检查API连接
            if (!$this->apiClient->checkConnection()) {
                $this->error('❌ 无法连接到外部翻译系统');
                return 1;
            }

            $direction = $this->option('direction');
            $languages = $this->option('language') ?: array_keys($this->config['supported_languages']);

            // 显示配置信息
            $this->displaySyncConfiguration($direction, $languages);

            switch ($direction) {
                case 'pull':
                    $this->pullFromExternal($languages);
                    break;
                case 'push':
                    $this->pushToExternal($languages);
                    break;
                case 'both':
                    $this->syncBidirectional($languages);
                    break;
                default:
                    $this->error("无效的同步方向: {$direction}");
                    return 1;
            }

            $this->info('✅ 翻译同步完成!');
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ 翻译同步失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 显示同步配置信息
     *
     * @param string $direction
     * @param array $languages
     */
    protected function displaySyncConfiguration(string $direction, array $languages): void
    {
        $this->info('📊 同步配置:');
        $this->table(
            ['项目', '值'],
            [
                ['同步方向', $direction],
                ['目标语言', implode(', ', $languages)],
                ['合并模式', $this->option('merge-mode')],
                ['自动检测格式', $this->option('auto-detect-format') ? '是' : '否'],
                ['乾跑模式', $this->option('dry-run') ? '是' : '否'],
            ]
        );
        $this->newLine();
    }

    /**
     * 从外部系统拉取翻译
     *
     * @param array $languages
     */
    protected function pullFromExternal(array $languages): void
    {
        $this->info('📥 从外部系统拉取翻译...');

        foreach ($languages as $language) {
            $this->line("处理语言: {$language}");

            try {
                // 获取外部翻译
                $externalTranslations = $this->apiClient->getTranslations([
                    'language' => $language
                ]);

                if (empty($externalTranslations)) {
                    $this->warn("  - 没有找到 {$language} 的翻译");
                    continue;
                }

                // 检测文件格式和处理合并
                $processedData = $this->processExternalTranslations($language, $externalTranslations);

                // 保存翻译文件
                $this->saveLanguageFileWithFormat($language, $processedData);
                
                $this->info("  - ✅ 已更新 {$language} (" . count($processedData['translations']) . " 项, 格式: {$processedData['format']})");

            } catch (\Exception $e) {
                $this->error("  - ❌ {$language} 同步失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 处理外部翻译数据，支持格式检测和内容合并
     *
     * @param string $language
     * @param array $externalTranslations
     * @return array
     */
    protected function processExternalTranslations(string $language, array $externalTranslations): array
    {
        // 检测文件格式
        $detectedFormat = $this->detectFileFormat($externalTranslations);
        
        // 获取本地现有翻译
        $localTranslations = $this->loadLanguageFile($language);
        
        // 处理合并或覆盖
        $finalTranslations = $this->mergeTranslations($localTranslations, $externalTranslations);
        
        return [
            'translations' => $finalTranslations,
            'format' => $detectedFormat,
        ];
    }

    /**
     * 检测文件格式根据外部系统返回的数据
     *
     * @param array $externalTranslations
     * @return string
     */
    protected function detectFileFormat(array $externalTranslations): string
    {
        // 如果不启用自动检测，默认使用JSON
        if (!$this->option('auto-detect-format')) {
            return 'json';
        }

        // 检查外部数据中的file_type字段
        foreach ($externalTranslations as $translation) {
            if (isset($translation['file_type'])) {
                $fileType = strtolower($translation['file_type']);
                if (in_array($fileType, ['php', 'json'])) {
                    return $fileType;
                }
            }
        }

        // 如果没有检测到，检查键的格式来判断
        foreach ($externalTranslations as $translation) {
            $key = $translation['key'] ?? '';
            if (strpos($key, '.') !== false) {
                // 包含点号的键可能来自PHP文件
                return 'php';
            }
        }

        // 默认使用JSON格式
        return 'json';
    }

    /**
     * 合并翻译内容
     *
     * @param array $localTranslations
     * @param array $externalTranslations
     * @return array
     */
    protected function mergeTranslations(array $localTranslations, array $externalTranslations): array
    {
        $mergeMode = $this->option('merge-mode');
        
        // 先将外部翻译转换为简单的键值对
        $externalFormatted = $this->formatTranslationsForLocal($externalTranslations);
        
        if ($mergeMode === 'overwrite') {
            // 完全覆盖模式
            if (!empty($localTranslations) && !$this->option('force')) {
                if (!$this->confirm("检测到本地已有翻译文件，是否完全覆盖？")) {
                    return array_merge($localTranslations, $externalFormatted);
                }
            }
            return $externalFormatted;
        }
        
        // 合并模式（默认）
        return array_merge($localTranslations, $externalFormatted);
    }

    /**
     * 根据格式保存语言文件
     *
     * @param string $language
     * @param array $processedData
     */
    protected function saveLanguageFileWithFormat(string $language, array $processedData): void
    {
        $translations = $processedData['translations'];
        $format = $processedData['format'];
        
        $langPath = $this->config['lang_path'];

        if ($format === 'json') {
            // JSON格式保存在 lang/{language}.json
            $filePath = "{$langPath}/{$language}.json";

            // 确保目录存在
            if (!File::exists($langPath)) {
                File::makeDirectory($langPath, 0755, true);
            }
            
            $content = json_encode($translations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            
        } else {
            // PHP格式保存在 lang/{language}/messages.php
            $languageDir = "{$langPath}/{$language}";
            $filePath = "{$languageDir}/messages.php";

            // 确保目录存在
            if (!File::exists($languageDir)) {
                File::makeDirectory($languageDir, 0755, true);
            }
            
            // 将平块的键转换为嵌套数组（仅限PHP格式）
            $nestedTranslations = $this->unflattenArray($translations);
            $content = "<?php\n\nreturn " . var_export($nestedTranslations, true) . ";\n";
        }

        // 检查是否强制覆盖
        if (File::exists($filePath) && !$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm("文件 {$filePath} 已存在，是否覆盖？")) {
                return;
            }
        }
        
        if (!$this->option('dry-run')) {
            File::put($filePath, $content);
            $this->line("    → 已保存到: {$filePath}");
        } else {
            $this->line("    → [乾跑] 将保存到: {$filePath}");
        }
    }

    /**
     * 将平坂的键转换为嵌套数组
     *
     * @param array $flatArray
     * @param string $separator
     * @return array
     */
    protected function unflattenArray(array $flatArray, string $separator = '.'): array
    {
        $result = [];
        
        foreach ($flatArray as $key => $value) {
            $keys = explode($separator, $key);
            $temp = &$result;
            
            foreach ($keys as $k) {
                if (!isset($temp[$k])) {
                    $temp[$k] = [];
                }
                $temp = &$temp[$k];
            }
            
            $temp = $value;
        }
        
        return $result;
    }

    /**
     * 推送翻译到外部系统
     *
     * @param array $languages
     */
    protected function pushToExternal(array $languages): void
    {
        $this->info('📤 推送翻译到外部系统...');
        $this->line('');
        
        $this->warn('⚠️  注意：推送功能已在 translation:collect --upload 命令中实现');
        $this->line('');
        
        $this->info('💡 建议使用以下命令进行推送：');
        
        foreach ($languages as $language) {
            $this->line("  php artisan translation:collect --upload --language={$language}");
        }
        
        $this->line('');
        $this->info('该命令支持更完整的功能：');
        $this->line('  - 自动收集项目中的翻译文本');
        $this->line('  - 过滤本地不存在的翻译键');
        $this->line('  - 批量上传和错误处理');
        $this->line('  - 差异分析和增量同步');
    }

    /**
     * 双向同步
     *
     * @param array $languages
     */
    protected function syncBidirectional(array $languages): void
    {
        $this->info('🔄 执行双向同步...');
        $this->line('');
        
        $this->warn('⚠️  注意：双向同步将先执行pull，然后建议使用 translation:collect --upload 执行push');
        $this->line('');
        
        // 先执行pull操作
        $this->pullFromExternal($languages);
        
        $this->line('');
        $this->info('💡 接下来请使用以下命令执行push操作：');
        foreach ($languages as $language) {
            $this->line("  php artisan translation:collect --upload --language={$language}");
        }
    }

    /**
     * 加载语言文件
     *
     * @param string $language
     * @return array
     */
    protected function loadLanguageFile(string $language): array
    {
        $langPath = $this->config['lang_path'];
        $format = $this->option('format');

        // 优先尝试JSON格式文件（直接在lang目录下）
		$allData = [];
        $jsonFile = "{$langPath}/{$language}.json";
        if (File::exists($jsonFile)) {
            $content = File::get($jsonFile);
            $jsonData = json_decode($content, true) ?: [];
			$allData = array_merge($allData, $jsonData);
        }

        // 如果指定format为php或未找到json文件，扫描语言目录下的php文件
        $languageDir = "{$langPath}/{$language}";
        if (File::exists($languageDir) && File::isDirectory($languageDir)) {
            $phpFiles = File::glob("{$languageDir}/*.php");

            foreach ($phpFiles as $phpFile) {
                try {
                    $fileData = include $phpFile;
                    if (is_array($fileData)) {
                        $fileName = pathinfo($phpFile, PATHINFO_FILENAME);
                        // 为PHP文件中的键加上文件名前缀
                        $flatData = $this->flattenArray($fileData, $fileName);
                        $allData = array_merge($allData, $flatData);
                    }
                } catch (\Exception $e) {
                    // 忽略无法加载的文件
                }
            }
		}
		return $allData;
	}

    /**
     * 分析差异
     *
     * @param array $local
     * @param array $external
     * @return array
     */
    protected function analyzeDifferences(array $local, array $external): array
    {
        $localKeys = array_keys($local);
        $externalKeys = array_keys($external);

        return [
            'local_only' => array_diff($localKeys, $externalKeys),
            'external_only' => array_diff($externalKeys, $localKeys),
            'different_values' => $this->findDifferentValues($local, $external),
            'common' => array_intersect($localKeys, $externalKeys),
        ];
    }

    /**
     * 查找值不同的键
     *
     * @param array $local
     * @param array $external
     * @return array
     */
    protected function findDifferentValues(array $local, array $external): array
    {
        $different = [];

        foreach ($local as $key => $value) {
            if (isset($external[$key]) && $external[$key] !== $value) {
                $different[] = $key;
            }
        }

        return $different;
    }

    /**
     * 显示差异
     *
     * @param string $language
     * @param array $differences
     */
    protected function displayDifferences(string $language, array $differences): void
    {
        $this->line("  - 📊 {$language} 差异分析:");
        $this->line("    - 仅本地存在: " . count($differences['local_only']));
        $this->line("    - 仅外部存在: " . count($differences['external_only']));
        $this->line("    - 值不同: " . count($differences['different_values']));
        $this->line("    - 相同: " . count($differences['common']));
    }

    /**
     * 处理差异
     *
     * @param string $language
     * @param array $differences
     */
    protected function processDifferences(string $language, array $differences): void
    {
        // 这里可以实现具体的差异处理逻辑
        // 例如：合并、选择性同步等
        $this->line("  - ✅ {$language} 差异已处理");
    }

    /**
     * 格式化翻译数据为API格式
     *
     * @param array $translations
     * @param string $language
     * @return array
     */
    protected function formatTranslationsForApi(array $translations, string $language): array
    {
        $formatted = [];

        foreach ($translations as $key => $value) {
            $formatted[] = [
                'key' => $key,
                'language' => $language,
                'value' => $value,
                'updated_at' => now()->toISOString(),
            ];
        }

        return $formatted;
    }

    /**
     * 格式化翻译数据为本地格式
     *
     * @param array $translations
     * @return array
     */
    protected function formatTranslationsForLocal(array $translations): array
    {
        $formatted = [];

        foreach ($translations as $translation) {
            $key = $translation['key'] ?? '';
            $value = $translation['value'] ?? $translation['text'] ?? '';

            if ($key && $value) {
                $formatted[$key] = $value;
            }
        }

        return $formatted;
    }

    /**
     * 展平嵌套数组
     *
     * @param array $array
     * @param string $prefix
     * @param string $separator
     * @return array
     */
    protected function flattenArray(array $array, string $prefix = '', string $separator = '.'): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix === '' ? $key : $prefix . $separator . $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey, $separator));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }
}

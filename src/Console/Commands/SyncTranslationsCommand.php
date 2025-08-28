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

            $languages = $this->option('language') ?: array_keys($this->config['supported_languages']);

            // 显示配置信息
            $this->displaySyncConfiguration($languages);

			$this->pullFromExternal($languages);

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
     * @param array $languages
     */
    protected function displaySyncConfiguration(array $languages): void
    {
        $this->info('📊 同步配置:');
        $this->table(
            ['项目', '值'],
            [
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

                // 按file_type分组处理外部翻译
                $groupedTranslations = $this->groupTranslationsByFileType($externalTranslations);

                $totalProcessed = 0;
                $savedFormats = [];

                // 分别处理每个格式的翻译
                foreach ($groupedTranslations as $fileType => $translations) {
                    $processedData = $this->processTranslationsByFormat($language, $translations, $fileType);

                    if (!empty($processedData['translations'])) {
                        $this->saveLanguageFileWithFormat($language, $processedData);
                        $totalProcessed += count($processedData['translations']);
                        $savedFormats[] = $processedData['format'];
                    }
                }

                if ($totalProcessed > 0) {
                    $formatStr = implode('+', array_unique($savedFormats));
                    $this->info("  - ✅ 已更新 {$language} ({$totalProcessed} 项, 格式: {$formatStr})");
                } else {
                    $this->warn("  - ⚠️ {$language} 没有有效的翻译数据");
                }

            } catch (\Exception $e) {
                $this->error("  - ❌ {$language} 同步失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 按file_type分组外部翻译数据
     *
     * @param array $externalTranslations
     * @return array
     */
    protected function groupTranslationsByFileType(array $externalTranslations): array
    {
        $grouped = [];
        $validTranslations = 0;
        $invalidTranslations = 0;

        foreach ($externalTranslations as $translation) {
            // 验证翻译数据格式
            if (!$this->validateTranslationData($translation)) {
                $invalidTranslations++;
                $this->warn("跳过无效的翻译数据: " . json_encode($translation));
                continue;
            }

            // 获取file_type，如果没有则使用默认格式
            $fileType = 'json'; // 默认值

            if (isset($translation['file_type'])) {
                $detectedType = strtolower(trim($translation['file_type']));
                if (in_array($detectedType, ['php', 'json'])) {
                    $fileType = $detectedType;
                }
            }

            // 如果启用自动检测且没有file_type，根据键名格式推断
            if (!isset($translation['file_type']) && $this->option('auto-detect-format')) {
                $key = $translation['key'] ?? '';
                if (str_contains($key, '.')) {
                    $fileType = 'php'; // 包含点号的键可能来自PHP文件
                }
            }

            if (!isset($grouped[$fileType])) {
                $grouped[$fileType] = [];
            }

            $grouped[$fileType][] = $translation;
            $validTranslations++;
        }

        // 输出处理统计
        if ($invalidTranslations > 0) {
            $this->warn("跳过了 {$invalidTranslations} 条无效翻译数据");
        }
        
        if ($validTranslations > 0) {
            $this->info("有效翻译数据: {$validTranslations} 条，分组结果: " . implode(', ', array_keys($grouped)));
        }

        return $grouped;
    }

    /**
     * 验证外部翻译数据格式
     *
     * @param mixed $translation
     * @return bool
     */
    protected function validateTranslationData($translation): bool
    {
        if (!is_array($translation)) {
            return false;
        }

        // 必须包含 key 和 value 字段
        if (!isset($translation['key']) || !isset($translation['value'])) {
            return false;
        }

        // key 和 value 不能为空
        if (empty(trim($translation['key'])) || empty(trim($translation['value']))) {
            return false;
        }

        // 如果有 file_type 字段，验证其值是否有效
        if (isset($translation['file_type'])) {
            $fileType = strtolower(trim($translation['file_type']));
            if (!in_array($fileType, ['php', 'json', ''])) {
                return false;
            }
        }

        return true;
    }

    /**
     * 按指定格式处理翻译数据
     *
     * @param string $language
     * @param array $translations
     * @param string $fileType
     * @return array
     */
    protected function processTranslationsByFormat(string $language, array $translations, string $fileType): array
    {
        // 获取对应格式的本地现有翻译
        $localTranslations = $this->loadLanguageFileByFormat($language, $fileType);

        // 将外部翻译转换为适合的格式
        $externalFormatted = $this->formatTranslationsForLocal($translations);

        // 处理合并或覆盖
        $finalTranslations = $this->mergeTranslations($localTranslations, $externalFormatted, $fileType);

        return [
            'translations' => $finalTranslations,
            'format' => $fileType,
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
            if (str_contains($key, '.')) {
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
     * @param string $fileType
     * @return array
     */
    protected function mergeTranslations(array $localTranslations, array $externalTranslations, string $fileType = 'json'): array
    {
        $mergeMode = $this->option('merge-mode');

        // 先将外部翻译转换为简单的键值对
        $externalFormatted = $this->formatTranslationsForLocal($externalTranslations);

        if ($mergeMode === 'overwrite') {
            return $this->handleOverwriteMode($localTranslations, $externalFormatted, $fileType);
        }

        // 合并模式（默认）
        return $this->handleMergeMode($localTranslations, $externalFormatted, $fileType);
    }

    /**
     * 处理覆盖模式
     *
     * @param array $localTranslations
     * @param array $externalFormatted
     * @param string $fileType
     * @return array
     */
    protected function handleOverwriteMode(array $localTranslations, array $externalFormatted, string $fileType): array
    {
        // 完全覆盖模式
        if (!empty($localTranslations) && !$this->option('force')) {
            $fileDesc = $fileType === 'json' ? 'JSON翻译文件' : 'PHP翻译文件';
            if (!$this->confirm("检测到本地已有{$fileDesc}，是否完全覆盖？")) {
                return array_merge($localTranslations, $externalFormatted);
            }
        }
        return $externalFormatted;
    }

    /**
     * 处理合并模式
     *
     * @param array $localTranslations
     * @param array $externalFormatted
     * @param string $fileType
     * @return array
     */
    protected function handleMergeMode(array $localTranslations, array $externalFormatted, string $fileType): array
    {
        if ($fileType === 'json') {
            // JSON格式的简单合并
            return array_merge($localTranslations, $externalFormatted);
        } else {
            // PHP格式的智能合并：按文件分组处理
            return $this->mergePhpTranslationsIntelligently($localTranslations, $externalFormatted);
        }
    }

    /**
     * 智能合并PHP翻译（按文件分组处理）
     *
     * @param array $localTranslations
     * @param array $externalFormatted
     * @return array
     */
    protected function mergePhpTranslationsIntelligently(array $localTranslations, array $externalFormatted): array
    {
        // 按文件名分组本地和外部翻译
        $localGrouped = $this->groupTranslationsByFile($localTranslations);
        $externalGrouped = $this->groupTranslationsByFile($externalFormatted);

        $mergedTranslations = [];

        // 合并所有文件的翻译
        $allFiles = array_unique(array_merge(array_keys($localGrouped), array_keys($externalGrouped)));

        foreach ($allFiles as $fileName) {
            $localFileTranslations = $localGrouped[$fileName] ?? [];
            $externalFileTranslations = $externalGrouped[$fileName] ?? [];

            // 合并当前文件的翻译
            $mergedFileTranslations = array_merge($localFileTranslations, $externalFileTranslations);
            $mergedTranslations = array_merge($mergedTranslations, $mergedFileTranslations);

            if (!empty($externalFileTranslations)) {
                $count = count($externalFileTranslations);
                $this->line("    → {$fileName}.php: 合并 {$count} 条翻译");
            }
        }

        return $mergedTranslations;
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
            $this->saveJsonTranslations($language, $translations, $langPath);
        } else {
            $this->savePhpTranslations($language, $translations, $langPath);
        }
    }

    /**
     * 保存JSON格式翻译（单文件）
     *
     * @param string $language
     * @param array $translations
     * @param string $langPath
     */
    protected function saveJsonTranslations(string $language, array $translations, string $langPath): void
    {
        // JSON格式保存在 lang/{language}.json
        $filePath = "{$langPath}/{$language}.json";

        // 确保目录存在
        if (!File::exists($langPath)) {
            File::makeDirectory($langPath, 0755, true);
        }

        $content = json_encode($translations, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

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
            $this->line("    → [干跑] 将保存到: {$filePath}");
        }
    }

    /**
     * 保存PHP格式翻译（多文件）
     *
     * @param string $language
     * @param array $translations
     * @param string $langPath
     */
    protected function savePhpTranslations(string $language, array $translations, string $langPath): void
    {
        $languageDir = "{$langPath}/{$language}";

        // 确保语言目录存在
        if (!File::exists($languageDir)) {
            File::makeDirectory($languageDir, 0755, true);
        }

        // 按文件名分组翻译
        $groupedByFile = $this->groupTranslationsByFile($translations);

        foreach ($groupedByFile as $fileName => $fileTranslations) {
            $filePath = "{$languageDir}/{$fileName}.php";

            // 将平坦的键转换为嵌套数组
            $nestedTranslations = $this->unflattenArrayForFile($fileTranslations, $fileName);
            $content = "<?php\n\nreturn " . var_export($nestedTranslations, true) . ";\n";

            // 检查是否强制覆盖
            if (File::exists($filePath) && !$this->option('force') && !$this->option('dry-run')) {
                if (!$this->confirm("文件 {$filePath} 已存在，是否覆盖？")) {
                    continue;
                }
            }

            if (!$this->option('dry-run')) {
                File::put($filePath, $content);
                $this->line("    → 已保存到: {$filePath}");
            } else {
                $this->line("    → [干跑] 将保存到: {$filePath}");
            }
        }
    }

    /**
     * 按文件名分组翻译
     * 例如: 'auth.login' -> 归类到 'auth' 文件
     *       'validation.required' -> 归类到 'validation' 文件
     *       'simple_key' -> 归类到 'messages' 文件（默认）
     *
     * @param array $translations
     * @return array
     */
    protected function groupTranslationsByFile(array $translations): array
    {
        $grouped = [];

        foreach ($translations as $key => $value) {
            // 检查键是否包含点号（命名空间分隔符）
            if (str_contains($key, '.')) {
                // 获取第一个点号之前的部分作为文件名
                $fileName = explode('.', $key, 2)[0];
            } else {
                // 没有命名空间的键归类到 messages 文件
                $fileName = 'messages';
            }

            if (!isset($grouped[$fileName])) {
                $grouped[$fileName] = [];
            }

            $grouped[$fileName][$key] = $value;
        }

        return $grouped;
    }

    /**
     * 为特定文件将平坦的键转换为嵌套数组
     * 移除文件名前缀
     *
     * @param array $translations
     * @param string $fileName
     * @return array
     */
    protected function unflattenArrayForFile(array $translations, string $fileName): array
    {
        $result = [];

        foreach ($translations as $key => $value) {
            // 如果键以文件名开头，移除文件名前缀
            if (str_starts_with($key, $fileName . '.')) {
                $cleanKey = substr($key, strlen($fileName) + 1);
            } else {
                // 没有前缀的键（如归类到messages的简单键）
                $cleanKey = $key;
            }

            // 将清理后的键转换为嵌套结构
            $keys = explode('.', $cleanKey);
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
     * 加载语言文件（按格式）
     *
     * @param string $language
     * @param string $fileType
     * @return array
     */
    protected function loadLanguageFileByFormat(string $language, string $fileType): array
    {
        $langPath = $this->config['lang_path'];

        if ($fileType === 'json') {
            return $this->loadJsonTranslations($language, $langPath);
        } else {
            return $this->loadPhpTranslations($language, $langPath);
        }
    }

    /**
     * 加载JSON格式翻译文件
     *
     * @param string $language
     * @param string $langPath
     * @return array
     */
    protected function loadJsonTranslations(string $language, string $langPath): array
    {
        $jsonFile = "{$langPath}/{$language}.json";
        if (File::exists($jsonFile)) {
            $content = File::get($jsonFile);
            return json_decode($content, true) ?: [];
        }
        return [];
    }

    /**
     * 加载PHP格式翻译文件（多文件）
     *
     * @param string $language
     * @param string $langPath
     * @return array
     */
    protected function loadPhpTranslations(string $language, string $langPath): array
    {
        $languageDir = "{$langPath}/{$language}";
        $allData = [];

        if (File::exists($languageDir) && File::isDirectory($languageDir)) {
            $phpFiles = File::glob("{$languageDir}/*.php");

            foreach ($phpFiles as $phpFile) {
                try {
                    $fileData = include $phpFile;
                    if (is_array($fileData)) {
                        $fileName = pathinfo($phpFile, PATHINFO_FILENAME);
                        // 为PHP文件中的键加上文件名前缀，扁平化处理
                        $flatData = $this->flattenArrayWithPrefix($fileData, $fileName);
                        $allData = array_merge($allData, $flatData);
                    }
                } catch (\Exception $e) {
                    // 忽略无法加载的文件
                    $this->warn("无法加载文件 {$phpFile}: {$e->getMessage()}");
                }
            }
        }

        return $allData;
    }

    /**
     * 带前缀扁平化数组
     * 例如：fileName = 'auth', array = ['login' => 'Login']
     * 结果：['auth.login' => 'Login']
     *
     * @param array $array
     * @param string $prefix
     * @param string $separator
     * @return array
     */
    protected function flattenArrayWithPrefix(array $array, string $prefix, string $separator = '.'): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix . $separator . $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArrayWithPrefix($value, $newKey, $separator));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
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

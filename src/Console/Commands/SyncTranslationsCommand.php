<?php

namespace Carlin\LaravelTranslationCollector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
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

                // 按文件目标分组，并预处理所有文件信息
                $groupedTranslations = $this->groupTranslationsByFileTarget($externalTranslations, $language);

                $totalProcessed = 0;
                $savedFormats = [];

                // 处理每个文件组
                foreach ($groupedTranslations as $fileGroup) {
                    $processedCount = $this->processFileGroup($fileGroup);

                    if ($processedCount > 0) {
                        $totalProcessed += $processedCount;
                        $savedFormats[] = $fileGroup['fileInfo']['type'];
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
     * 按文件目标分组外部翻译数据，并预处理文件信息
     *
     * @param array $externalTranslations
     * @param string $language
     * @return array
     */
    protected function groupTranslationsByFileTarget(array $externalTranslations, string $language): array
    {
        $grouped = [];
        $validTranslations = 0;
        $invalidTranslations = 0;
        $langPath = $this->config['lang_path'];

        foreach ($externalTranslations as $translation) {
            // 验证翻译数据格式
            if (!$this->validateTranslationData($translation)) {
                $invalidTranslations++;
                $this->warn("跳过无效的翻译数据: " . json_encode($translation));
                continue;
            }

            // 确定文件类型和目标路径
			$fileInfo = $this->buildFileInfo($translation, $language, $langPath);
			// 预处理文件信息
            if (!isset($grouped[$fileInfo['path']])) {
                $grouped[$fileInfo['path']] = [
					'fileInfo'     => $fileInfo,
					'translations' => []
                ];
            }
            $grouped[$fileInfo['path']]['translations'][] = $translation;
            $validTranslations++;
        }

        // 输出处理统计
        if ($invalidTranslations > 0) {
            $this->warn("跳过了 {$invalidTranslations} 条无效翻译数据");
        }

        if ($validTranslations > 0) {
            $fileTargets = array_keys($grouped);
            $this->info("有效翻译数据: {$validTranslations} 条，文件目标: " . implode(', ', $fileTargets));
        }

        return $grouped;
    }

    /**
     * 获取翻译文件类型
     *
     * @param array $translation
     * @return string
     */
    protected function getTranslationFileType(array $translation): string
    {
        // 默认使用 JSON 格式
        $fileType = 'json';

        if (isset($translation['file_type'])) {
            $detectedType = strtolower(trim($translation['file_type']));
            if (in_array($detectedType, ['php', 'json'])) {
                $fileType = $detectedType;
            }
        }
        return $fileType;
    }

    /**
     * 构建文件信息
     *
     * @param array $translation
     * @param string $language
     * @param string $langPath
     * @return array
     */
    protected function buildFileInfo(array $translation, string $language, string $langPath): array
    {
		$fileType = $this->getTranslationFileType($translation);


		if ($fileType === 'json') {
            return [
                'type' => 'json',
                'language' => $language,
                'path' => "{$langPath}/{$language}.json",
                'directory' => $langPath,
                'fileName' => "{$language}.json"
            ];
        } else {
            $fileName = $this->extractFileNameFromKey($translation['key']);
            $directory = "{$langPath}/{$language}";
            return [
                'type' => 'php',
                'language' => $language,
                'fileName' => $fileName,
                'path' => "{$directory}/{$fileName}.php",
                'directory' => $directory
            ];
        }
    }

    /**
     * 从翻译键中提取文件名
     *
     * @param string $key
     * @return string|null
     */
    protected function extractFileNameFromKey(string $key): ?string
    {
        if (str_contains($key, '.')) {
            return explode('.', $key, 2)[0];
        }
        return null; // 默认文件名
    }

    /**
     * 处理文件组
     *
     * @param array $fileGroup
     * @return int 处理的翻译数量
     */
    protected function processFileGroup(array $fileGroup): int
    {
        $fileInfo = $fileGroup['fileInfo'];
        $translations = $fileGroup['translations'];

        if (empty($translations)) {
            return 0;
        }

        // 确保目录存在
        $this->ensureDirectoryExists($fileInfo['directory']);

        // 根据文件类型处理
        if ($fileInfo['type'] === 'json') {
            return $this->processJsonFileGroup($fileInfo, $translations);
        } else {
            return $this->processPhpFileGroup($fileInfo, $translations);
        }
    }

	/**
	 * 处理 JSON 文件组
	 *
	 * @param array $fileInfo
	 * @param array $translations
	 * @return int
	 * @throws FileNotFoundException
	 * @throws \JsonException
	 */
    protected function processJsonFileGroup(array $fileInfo, array $translations): int
    {
        // 加载现有的 JSON 翻译
        $localTranslations = $this->loadJsonTranslations($fileInfo['path']);

        // 格式化外部翻译
        $externalFormatted = $this->formatTranslationsForLocal($translations);

        // 合并翻译
        $finalTranslations = $this->mergeTranslations($localTranslations, $externalFormatted);

        // 保存文件
        $this->saveJsonFile($fileInfo['path'], $finalTranslations);

        return count($finalTranslations);
    }

    /**
     * 处理 PHP 文件组
     *
     * @param array $fileInfo
     * @param array $translations
     * @return int
     */
    protected function processPhpFileGroup(array $fileInfo, array $translations): int
    {
        // 加载现有的 PHP 翻译（按文件名过滤）
        $localTranslations = $this->loadPhpTranslationsByFile($fileInfo['path'], $fileInfo['fileName']);

        // 格式化外部翻译
        $externalFormatted = $this->formatTranslationsForLocal($translations);

        // 合并翻译
        $finalTranslations = $this->mergeTranslations($localTranslations, $externalFormatted);

        // 保存文件
        $this->savePhpFile($fileInfo['path'], $finalTranslations, $fileInfo['fileName']);

        return count($finalTranslations);
    }

    /**
     * 确保目录存在
     *
     * @param string $directory
     */
    protected function ensureDirectoryExists(string $directory): void
    {
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }
    }

    /**
     * 保存 JSON 文件
     *
     * @param string $filePath
     * @param array $translations
     */
    protected function saveJsonFile(string $filePath, array $translations): void
    {
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
     * 保存 PHP 文件
     *
     * @param string $filePath
     * @param array $translations
     * @param string $fileName
     */
    protected function savePhpFile(string $filePath, array $translations, string $fileName): void
    {
        // 将平坦的键转换为嵌套数组
        $nestedTranslations = $this->unflattenArrayForFile($translations, $fileName);
        $content = "<?php\n\nreturn " . var_export($nestedTranslations, true) . ";\n";

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
	 * 按文件名加载 PHP 翻译
	 *
	 * @param string $filePath
	 * @return array
	 */
    protected function loadPhpTranslationsByFile(string $filePath, string $fileName): array
    {
        if (File::exists($filePath)) {
            try {
                $fileData = include $filePath;
                if (is_array($fileData)) {
                    // 为 PHP 文件中的键加上文件名前缀，扁平化处理
                    return $this->flattenArray($fileData, $fileName);
                }
            } catch (\Exception $e) {
                $this->warn("无法加载文件 {$filePath}: {$e->getMessage()}");
            }
        }

        return [];
    }

    /**
     * 验证外部翻译数据格式
     *
     * @param array $translation
     * @return bool
     */
    protected function validateTranslationData(array $translation): bool
    {
        // 必须包含 key 和 value 字段
        if (!isset($translation['key'], $translation['value'])) {
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

		//php，但是key规则不对
		if ($translation['file_type'] === 'php' && !$this->extractFileNameFromKey($translation['key'])) {
			return false;
		}

        return true;
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

        if ($mergeMode === 'overwrite') {
			return $externalTranslations;
        }

        // 合并模式（默认）
		return array_merge($localTranslations, $externalTranslations);
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
	 * 加载JSON格式翻译文件
	 *
	 * @param string $fileName
	 * @return array
	 * @throws FileNotFoundException
	 */
    protected function loadJsonTranslations(string $fileName): array
    {
        if (File::exists($fileName)) {
            $content = File::get($fileName);
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
                        $flatData = $this->flattenArray($fileData, $fileName);
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
    protected function flattenArray(array $array, string $prefix, string $separator = '.'): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix . $separator . $key;

            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey, $separator));
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
            $value = $translation['value'] ?? '';

            if ($key && $value) {
                $formatted[$key] = $value;
            }
        }

        return $formatted;
    }

}

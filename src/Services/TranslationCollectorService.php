<?php

namespace Carlin\LaravelTranslationCollector\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Finder\Finder;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Exceptions\TranslationCollectorException;

class TranslationCollectorService implements TranslationCollectorInterface
{
    /**
     * Laravel 应用实例
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * 配置数组
     *
     * @var array
     */
    protected $config;

    /**
     * 收集选项
     *
     * @var array
     */
    protected $options = [];

    /**
     * 收集统计信息
     *
     * @var array
     */
    protected $statistics = [
        'total_files_scanned' => 0,
        'total_translations_found' => 0,
        'new_translations' => 0,
        'existing_translations' => 0,
        'scan_duration' => 0,
    ];

    /**
     * 构造函数
     *
     * @param \Illuminate\Foundation\Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
        $this->config = config('translation-collector');
    }

    /**
     * 收集项目中的所有翻译文本
     *
     * @param array $options 收集选项
     * @return array
     */
    public function collect(array $options = []): array
    {
        $startTime = microtime(true);
        $this->setOptions($options);

        try {
            $this->log('info', '开始收集翻译文本');

            // 获取扫描路径
            $scanPaths = $this->getScanPaths();

            // 扫描翻译文本
            $translations = $this->scanPaths($scanPaths);

            // 处理模块化架构
            if ($this->config['modules_support']['enabled']) {
                $moduleTranslations = $this->scanModules();
                $translations = [...$translations, ...$moduleTranslations];
            }

            // 去重和标准化
            $translations = $this->normalizeTranslations($translations);

            // 更新统计信息
            $this->statistics['scan_duration'] = microtime(true) - $startTime;
            $this->statistics['total_translations_found'] = count($translations);

            $this->log('info', '翻译文本收集完成', [
                'total_found' => count($translations),
                'duration' => $this->statistics['scan_duration'],
            ]);

            // 缓存结果
            if ($this->config['cache']['enabled']) {
                $this->cacheResults($translations);
            }

            return $translations;

        } catch (\Exception $e) {
            $this->log('error', '翻译收集失败: ' . $e->getMessage());
            throw new TranslationCollectorException('翻译收集失败: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 扫描指定路径的翻译文本
     *
     * @param string|array $paths 扫描路径
     * @param array $options 扫描选项
     * @return array
     */
    public function scanPaths($paths, array $options = []): array
    {
        if (is_string($paths)) {
            $paths = [$paths];
        }

        $translations = [];

        foreach ($paths as $path) {
            // 如果是绝对路径，直接使用；否则使用base_path转换
            $fullPath = (str_starts_with($path, '/') || str_contains($path, ':\\')) ? $path : base_path($path);

            if (!File::exists($fullPath)) {
                $this->log('warning', "扫描路径不存在: {$fullPath}");
                continue;
            }

            $pathTranslations = $this->scanDirectory($fullPath);
            $translations = [...$translations, ...$pathTranslations];
        }

        return $translations;
    }

    /**
     * 扫描指定模块的翻译文本
     *
     * @param string|array $modules 模块名称
     * @param array $options 扫描选项
     * @return array
     */
    public function scanModules($modules = null, array $options = []): array
    {
        if (!$this->config['modules_support']['enabled']) {
            return [];
        }

        $modulesPath = $this->config['modules_support']['modules_path'];

        if (!File::exists($modulesPath)) {
            $this->log('warning', "模块路径不存在: {$modulesPath}");
            return [];
        }

        $translations = [];

        // 如果未指定模块，扫描所有模块
        if ($modules === null) {
            $modules = $this->getAvailableModules();
        } elseif (is_string($modules)) {
            $modules = [$modules];
        }

        foreach ($modules as $module) {
            $moduleTranslations = $this->scanModule($module);
            $translations = [...$translations, ...$moduleTranslations];
        }

        return $translations;
    }

    /**
     * 分析翻译差异
     *
     * @param array $collected 收集到的翻译
     * @param array $existing 现有的翻译
     * @return array
     */
    public function analyzeDifferences(array $collected, array $existing): array
    {
		$collectedMap = collect($collected)->keyBy(fn($t) => $this->makeUniqueKey($t));
		$existingMap  = collect($existing)->keyBy(fn($t) => $this->makeUniqueKey($t));

		return [
			// 新增：在 collected 里有，但 existing 没有
			'new' => $collectedMap->diffKeys($existingMap)->values()->all(),

			//// 删除：在 existing 里有，但 collected 没有
			//'deleted' => $existingMap->diffKeys($collectedMap)->values()->all(),
			//
			//// 更新：两边都有，但内容不一致
			//'updated' => $collectedMap
			//	->intersectByKeys($existingMap)
			//	->filter(fn($item, $key) => $this->hasTranslationChanged($item, $existingMap[$key]))
			//	->values()
			//	->all(),
			//
			//// 未变更：两边都有，内容一致
			//'unchanged' => $collectedMap
			//	->intersectByKeys($existingMap)
			//	->filter(fn($item, $key) => ! $this->hasTranslationChanged($item, $existingMap[$key]))
			//	->values()
			//	->all(),
		];
    }

	private function makeUniqueKey(array $translation): string
	{
		return "{$translation['module']}::{$translation['key']}";
	}


	/**
     * 获取收集统计信息
     *
     * @return array
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * 设置收集选项
     *
     * @param array $options
     * @return self
     */
    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }

    /**
     * 扫描目录
     *
     * @param string $directory
     * @return array
     */
    protected function scanDirectory(string $directory): array
    {
        $finder = new Finder();
        $translations = [];

        $finder->files()
            ->in($directory)
            ->exclude($this->config['exclude_paths']);

        // 正确配置文件扩展名匹配
        $extensions = $this->config['scan_file_extensions'];
        if (!empty($extensions)) {
            $patterns = [];
            foreach ($extensions as $extension) {
                $patterns[] = "*.{$extension}";
            }
            $finder->name($patterns);
        }

        foreach ($finder as $file) {
            $this->statistics['total_files_scanned']++;

            $fileTranslations = $this->scanFile($file->getRealPath());
            $translations = [...$translations, ...$fileTranslations];
        }

        return $translations;
    }

    /**
     * 扫描单个文件
     *
     * @param string $filePath
     * @return array
     */
    protected function scanFile(string $filePath): array
    {
        $content = File::get($filePath);
        $extension = $this->getFileExtension($filePath);
        $translations = [];

        // 根据文件类型选择合适的正则表达式
        $patterns = $this->getRegexPatterns($extension);

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $extractedText = $match[1][0];
                $offset = $match[0][1];

                // 计算行号
                $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

                // 获取上下文
                $context = $this->getContext($content, $offset, $match[0][0]);

                // 分析翻译键和值
                $translationData = $this->analyzeTranslationKeyAndValue($extractedText);
				//不正常的翻译键，跳过
				if (is_null($translationData['file_type'])) {
					$this->log('error', "文件: {$filePath} 行号: {$lineNumber} 未获取到翻译值: {$extractedText}");
					continue;
				}

                $translations[] = [
					'key'                   => $translationData['key'],
					'default_text'          => $translationData['value'],
					'source_file'           => $filePath,
					'line_number'           => $lineNumber,
					'context'               => $context,
					'module'                => $this->detectModule($filePath),
					'file_type'             => $translationData['file_type'],
					'is_direct_text'        => $translationData['is_direct_text'],
					'source_type'           => 'code_scan',
					'created_at'            => now()->toISOString(),
                ];
            }
        }

        return $translations;
    }

    /**
     * 扫描单个模块
     *
     * @param string $moduleName
     * @return array
     */
    protected function scanModule(string $moduleName): array
    {
        $modulePath = $this->config['modules_support']['modules_path'] . '/' . $moduleName;

        if (!File::exists($modulePath)) {
            $this->log('warning', "模块不存在: {$moduleName}");
            return [];
        }

        $translations = [];
        $scanPaths = $this->config['modules_support']['scan_module_paths'];

        foreach ($scanPaths as $subPath) {
            $fullPath = $modulePath . '/' . $subPath;

            if (File::exists($fullPath)) {
                $pathTranslations = $this->scanDirectory($fullPath);

                // 为模块翻译添加模块前缀
                foreach ($pathTranslations as &$translation) {
                    $translation['module'] = $moduleName;
                }
				unset($translation);
                $translations = [...$translations, ...$pathTranslations];
            }
        }

        return $translations;
    }

    /**
     * 获取可用的模块列表
     *
     * @return array
     */
    protected function getAvailableModules(): array
    {
        $modulesPath = $this->config['modules_support']['modules_path'];

        if (!File::exists($modulesPath)) {
            return [];
        }

        $modules = [];
        $directories = File::directories($modulesPath);

        foreach ($directories as $directory) {
            $modules[] = basename($directory);
        }

        return $modules;
    }

    /**
     * 标准化翻译数据
     *
     * @param array $translations
     * @return array
     */
    protected function normalizeTranslations(array $translations): array
    {
        // 去重
        $uniqueTranslations = [];
        $seenKeys = [];

        foreach ($translations as $translation) {
            $key = $translation['key'];

            if (!in_array($key, $seenKeys)) {
                $seenKeys[] = $key;
                $uniqueTranslations[] = $translation;
            }
        }

        // 验证翻译键格式
        return $uniqueTranslations;
    }


    /**
     * 检查翻译是否发生变更
     *
     * @param array $collected
     * @param array $existing
     * @return bool
     */
    protected function hasTranslationChanged(array $collected, array $existing): bool
    {
        // 比较源文件、行号、上下文等信息
        return $collected['source_file'] !== $existing['source_file'] ||
               $collected['line_number'] !== $existing['line_number'] ||
               $collected['context'] !== $existing['context'];
    }

    /**
     * 获取文件扩展名（正确处理.blade.php文件）
     *
     * @param string $filePath
     * @return string
     */
    protected function getFileExtension(string $filePath): string
    {
        // 特殊处理 .blade.php 文件
        if (str_ends_with($filePath, '.blade.php')) {
            return 'blade.php';
        }

        return pathinfo($filePath, PATHINFO_EXTENSION);
    }

    /**
     * 获取正则表达式模式
     *
     * @param string $fileType
     * @return array
     */
    protected function getRegexPatterns(string $fileType): array
    {
        $patterns = $this->config['regex_patterns'];

        switch ($fileType) {
            case 'php':
                return $patterns['php'] ?? [];
            case 'blade.php':
                return array_merge($patterns['php'] ?? [], $patterns['blade'] ?? []);
            default:
                return [];
        }
    }

    /**
     * 获取上下文信息
     *
     * @param string $content
     * @param int $offset
     * @param string $match
     * @return string
     */
    protected function getContext(string $content, int $offset, string $match): string
    {
        $contextLength = 100;
        $start = max(0, $offset - $contextLength);
        $end = min(strlen($content), $offset + strlen($match) + $contextLength);

        return substr($content, $start, $end - $start);
    }

    /**
     * 检测模块名称
     *
     * @param string $filePath
     * @return string|null
     */
    protected function detectModule(string $filePath): ?string
    {
        $modulesPath = $this->config['modules_support']['modules_path'];

        if (strpos($filePath, $modulesPath) === 0) {
            $relativePath = substr($filePath, strlen($modulesPath) + 1);
            $parts = explode('/', $relativePath);
            return $parts[0] ?? null;
        }

        return null;
    }

    /**
     * 获取扫描路径
     *
     * @return array
     */
    protected function getScanPaths(): array
    {
        $paths = $this->config['scan_paths'];

        if (isset($this->options['paths'])) {
            $paths = $this->options['paths'];
        }

        return $paths;
    }

    /**
     * 缓存结果
     *
     * @param array $translations
     */
    protected function cacheResults(array $translations): void
    {
        $cacheKey = $this->config['cache']['key_prefix'] . 'collected_translations';
        $ttl = $this->config['cache']['ttl'];

        Cache::put($cacheKey, $translations, $ttl);
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
        if (!$this->config['logging']['enabled']) {
            return;
        }

        Log::channel($this->config['logging']['channel'])
            ->{$level}("[TranslationCollector] {$message}", $context);
    }

	/**
	 * 扫描现有翻译文件
	 *
	 * @param array|string|null $languages 指定语言，为空则扫描所有支持的语言
	 * @return array
	 */
    public function scanExistingTranslations(array|string|null $languages = null): array
    {
        $langPath = $this->config['lang_path'];

        if (!File::exists($langPath)) {
            $this->log('warning', "翻译文件路径不存在: {$langPath}");
            return [];
        }

        if ($languages === null) {
            $languages = array_keys($this->config['supported_languages']);
        } elseif (is_string($languages)) {
            $languages = [$languages];
        }

        $translations = [];

        foreach ($languages as $language) {
            $languageTranslations = $this->scanLanguageFiles($language, $langPath);
            $translations = [...$translations, ...$languageTranslations];
        }

        $this->log('info', '现有翻译文件扫描完成', [
            'languages' => $languages,
            'total_found' => count($translations),
        ]);

        return $translations;
    }

    /**
     * 扫描指定语言的翻译文件
     *
     * @param string $language
     * @param string $langPath
     * @return array
     */
    protected function scanLanguageFiles(string $language, string $langPath): array
    {
        $translations = [];

        // 1. 扫描JSON格式文件: resources/lang/{locale}.json
        $jsonFile = "{$langPath}/{$language}.json";
        if (File::exists($jsonFile)) {
            $jsonTranslations = $this->loadJsonTranslations($jsonFile, $language);
            $translations = array_merge($translations, $jsonTranslations);
        }

        // 2. 扫描PHP格式文件: resources/lang/{locale}/*.php
        $languageDir = "{$langPath}/{$language}";
        if (File::exists($languageDir) && File::isDirectory($languageDir)) {
            $phpTranslations = $this->loadPhpTranslations($languageDir, $language);
            $translations = array_merge($translations, $phpTranslations);
        }

        return $translations;
    }

    /**
     * 加载JSON格式翻译文件
     *
     * @param string $filePath
     * @param string $language
     * @return array
     */
    protected function loadJsonTranslations(string $filePath, string $language): array
    {
        $translations = [];

        try {
            $content = File::get($filePath);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->log('error', "JSON翻译文件解析失败: {$filePath}");
                return [];
            }

            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $translations[] = [
                        'key' => $key,
                        'default_text' => $key,
                        'value' => $value,
                        'source_file' => $filePath,
                        'line_number' => 1,
                        'context' => json_encode([$key => $value]),
                        'module' => null,
                        'file_type' => 'json',
                        'language' => $language,
                        'source_type' => 'translation_file',
                        'is_direct_text' => true,
                        'created_at' => now()->toISOString(),
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->log('error', "加载JSON翻译文件失败: {$filePath}", ['error' => $e->getMessage()]);
        }

        return $translations;
    }

    /**
     * 加载PHP格式翻译文件
     *
     * @param string $languageDir
     * @param string $language
     * @return array
     */
    protected function loadPhpTranslations(string $languageDir, string $language): array
    {
        $translations = [];
        $phpFiles = File::glob("{$languageDir}/*.php");

        foreach ($phpFiles as $phpFile) {
            try {
                $data = include $phpFile;
                if (!is_array($data)) {
                    continue;
                }

                $fileName = basename($phpFile, '.php');
                $fileTranslations = $this->flattenTranslations($data, $fileName, $phpFile, $language);
                $translations = [...$translations, ...$fileTranslations];
            } catch (\Exception $e) {
                $this->log('error', "加载PHP翻译文件失败: {$phpFile}", ['error' => $e->getMessage()]);
            }
        }

        return $translations;
    }

    /**
     * 展平嵌套的翻译数组
     *
     * @param array $data
     * @param string $prefix
     * @param string $filePath
     * @param string $language
     * @return array
     */
    protected function flattenTranslations(array $data, string $prefix, string $filePath, string $language): array
    {
        $translations = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix . '.' . $key;

            if (is_array($value)) {
                // 递归处理嵌套数组
                $nestedTranslations = $this->flattenTranslations($value, $fullKey, $filePath, $language);
                $translations = [...$translations, ...$nestedTranslations];
            } elseif (is_string($value)) {
                $translations[] = [
					'key'            => $fullKey,
					'default_text'   => $value,
					'value'          => $value,
					'source_file'    => $filePath,
					'line_number'    => 1,
					'context'        => "['{$key}' => '{$value}']",
					'module'         => null,
					'file_type'      => 'php',
					'language'       => $language,
					'source_type'    => 'translation_file',
					'is_direct_text' => false,
					'created_at'     => now()->toISOString(),
                ];
            }
        }

        return $translations;
    }

    /**
     * 分析翻译键和值
     *
     * @param string $extractedText
     * @return array
     */
    protected function analyzeTranslationKeyAndValue(string $extractedText): array
    {
        $isDirectText = $this->isDirectText($extractedText);

        if ($isDirectText) {
            // 直接文本：生成键和使用原文本作为值
            return [
                'key' => $extractedText,
                'value' => $extractedText,
                'is_direct_text' => true,
				'file_type' => 'json',
            ];
        }

		// 翻译键：尝试从翻译文件中查找对应值
		$value = $this->resolveTranslationValue($extractedText);
		return [
			'key'                   => $extractedText,
			'value'                 => $value['value'] ?? null,
			'is_direct_text'        => false,
			'file_type' => $value['file_type'] ?? null,
		];
    }

    /**
     * 判断是否为直接文本
     *
     * @param string $text
     * @return bool
     */
    protected function isDirectText(string $text): bool
    {
		if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z0-9_]+)+$/', $text)) {
			return false;
		}

        return true;
    }


    /**
     * 解析翻译键对应的值
     *
     * @param string $key
     * @return array
     */
    protected function resolveTranslationValue(string $key): array
    {
		$defaultLanguage = $this->config['default_language'];

        $langPath = $this->config['lang_path'];

        // 1. 尝试从PHP数组文件中查找 (优先级高)
        $value = $this->resolveFromPhpFiles($key, $defaultLanguage, $langPath);
        if ($value !== null) {
			return [
				'value' => $value,
				'file_type' => 'php',
			];
        }

        // 2. 尝试从JSON文件中查找
        $value = $this->resolveFromJsonFile($key, $defaultLanguage, $langPath);
        if ($value !== null) {
			return [
				'value' => $value,
				'file_type' => 'json',
			];
        }

        // 3. 都没找到，返回键本身
        return [];
    }

    /**
     * 从PHP翻译文件中解析值
     *
     * @param string $key
     * @param string $language
     * @param string $langPath
     * @return string|null
     */
    protected function resolveFromPhpFiles(string $key, string $language, string $langPath): ?string
    {
        $keyParts = explode('.', $key);
        if (count($keyParts) < 2) {
            return null;
        }

        $fileName = array_shift($keyParts);
        $phpFile = "{$langPath}/{$language}/{$fileName}.php";

        if (!File::exists($phpFile)) {
            return null;
        }

        try {
            $data = include $phpFile;
            if (!is_array($data)) {
                return null;
            }

            // 按键路径查找值
            $current = $data;
            foreach ($keyParts as $part) {
                if (!is_array($current) || !isset($current[$part])) {
                    return null;
                }
                $current = $current[$part];
            }

            return is_string($current) ? $current : null;
        } catch (\Exception $e) {
            $this->log('error', "解析PHP翻译文件失败: {$phpFile}", ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * 从JSON翻译文件中解析值
     *
     * @param string $key
     * @param string $language
     * @param string $langPath
     * @return string|null
     */
    protected function resolveFromJsonFile(string $key, string $language, string $langPath): ?string
    {
        $jsonFile = "{$langPath}/{$language}.json";

        if (!File::exists($jsonFile)) {
            return null;
        }

        try {
            $content = File::get($jsonFile);
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return null;
            }

            return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
        } catch (\Exception $e) {
            $this->log('error', "解析JSON翻译文件失败: {$jsonFile}", ['error' => $e->getMessage()]);
            return null;
        }
    }
}

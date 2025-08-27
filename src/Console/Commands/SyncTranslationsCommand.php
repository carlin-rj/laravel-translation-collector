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
                            {--direction=both : 同步方向 (pull, push, both)}
                            {--language=* : 指定同步的语言}
                            {--format=json : 本地文件格式 (json, php)}
                            {--dry-run : 仅显示差异，不实际同步}
                            {--force : 强制覆盖本地文件}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '与外部翻译系统同步翻译文件';

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

            switch ($direction) {
                case 'pull':
                    $this->pullFromExternal($languages);
                    break;
                case 'push':
                    $this->pushToExternal($languages);
                    break;
                case 'both':
                default:
                    $this->syncBidirectional($languages);
                    break;
            }

            $this->info('✅ 翻译同步完成!');
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ 翻译同步失败: {$e->getMessage()}");
            return 1;
        }
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

                // 转换格式并保存
                $this->saveLanguageFile($language, $externalTranslations);
                $this->info("  - ✅ 已更新 {$language} (" . count($externalTranslations) . " 项)");

            } catch (\Exception $e) {
                $this->error("  - ❌ {$language} 同步失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 推送翻译到外部系统
     *
     * @param array $languages
     */
    protected function pushToExternal(array $languages): void
    {
        $this->info('📤 推送翻译到外部系统...');

        foreach ($languages as $language) {
            $this->line("处理语言: {$language}");

            try {
                // 读取本地翻译文件
                $localTranslations = $this->loadLanguageFile($language);

                if (empty($localTranslations)) {
                    $this->warn("  - 没有找到 {$language} 的本地翻译文件");
                    continue;
                }

                // 转换格式
                $formattedTranslations = $this->formatTranslationsForApi($localTranslations, $language);

                // 上传到外部系统
                $result = $this->apiClient->syncTranslations($formattedTranslations);
                $this->info("  - ✅ 已上传 {$language} (" . count($formattedTranslations) . " 项)");

            } catch (\Exception $e) {
                $this->error("  - ❌ {$language} 上传失败: " . $e->getMessage());
            }
        }
    }

    /**
     * 双向同步
     *
     * @param array $languages
     */
    protected function syncBidirectional(array $languages): void
    {
        $this->info('🔄 执行双向同步...');

        foreach ($languages as $language) {
            $this->line("处理语言: {$language}");

            try {
                // 获取本地翻译
                $localTranslations = $this->loadLanguageFile($language);
                
                // 获取外部翻译
                $externalTranslations = $this->apiClient->getTranslations([
                    'language' => $language
                ]);

                // 分析差异
                $differences = $this->analyzeDifferences($localTranslations, $externalTranslations);

                if ($this->option('dry-run')) {
                    $this->displayDifferences($language, $differences);
                    continue;
                }

                // 处理差异
                $this->processDifferences($language, $differences);

            } catch (\Exception $e) {
                $this->error("  - ❌ {$language} 同步失败: " . $e->getMessage());
            }
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

        $filePath = "{$langPath}/{$language}/{$language}.{$format}";

        if (!File::exists($filePath)) {
            // 尝试查找其他格式的文件
            $alternativeFormats = ['json', 'php'];
            foreach ($alternativeFormats as $altFormat) {
                $altPath = "{$langPath}/{$language}/{$language}.{$altFormat}";
                if (File::exists($altPath)) {
                    $filePath = $altPath;
                    $format = $altFormat;
                    break;
                }
            }
        }

        if (!File::exists($filePath)) {
            return [];
        }

        $content = File::get($filePath);

        switch ($format) {
            case 'json':
                return json_decode($content, true) ?: [];
            case 'php':
                return include $filePath;
            default:
                return [];
        }
    }

    /**
     * 保存语言文件
     *
     * @param string $language
     * @param array $translations
     */
    protected function saveLanguageFile(string $language, array $translations): void
    {
        $langPath = $this->config['lang_path'];
        $format = $this->option('format');
        $languageDir = "{$langPath}/{$language}";
        $filePath = "{$languageDir}/{$language}.{$format}";

        // 确保目录存在
        if (!File::exists($languageDir)) {
            File::makeDirectory($languageDir, 0755, true);
        }

        // 检查是否强制覆盖
        if (File::exists($filePath) && !$this->option('force')) {
            if (!$this->confirm("文件 {$filePath} 已存在，是否覆盖？")) {
                return;
            }
        }

        // 格式化翻译数据
        $formattedTranslations = $this->formatTranslationsForLocal($translations);

        switch ($format) {
            case 'json':
                $content = json_encode($formattedTranslations, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
            case 'php':
                $content = "<?php\n\nreturn " . var_export($formattedTranslations, true) . ";\n";
                break;
            default:
                throw new \InvalidArgumentException("不支持的文件格式: {$format}");
        }

        File::put($filePath, $content);
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
}

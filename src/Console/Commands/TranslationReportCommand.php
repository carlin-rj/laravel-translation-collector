<?php

namespace Carlin\LaravelTranslationCollector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;

class TranslationReportCommand extends Command
{
    /**
     * 命令签名
     *
     * @var string
     */
    protected $signature = 'translation:report
                            {--format=json : 报告格式 (json, html, csv)}
                            {--output= : 输出文件路径}
                            {--include-statistics : 包含统计信息}
                            {--include-missing : 包含缺失的翻译}
                            {--include-unused : 包含未使用的翻译}
                            {--language=* : 指定要分析的语言}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '生成翻译状态报告';

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
     * 配置
     *
     * @var array
     */
    protected $config;

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
        $this->info('📊 开始生成翻译报告...');

        try {
            // 收集翻译数据
            $reportData = $this->gatherReportData();

            // 生成报告
            $report = $this->generateReport($reportData);

            // 输出报告
            $this->outputReport($report);

            $this->info('✅ 翻译报告生成完成!');
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ 报告生成失败: {$e->getMessage()}");
            return 1;
        }
    }

    /**
     * 收集报告数据
     *
     * @return array
     */
    protected function gatherReportData(): array
    {
        $this->line('🔍 收集翻译数据...');

        // 收集项目中的翻译
        $collectedTranslations = $this->collector->collect();

        // 获取本地翻译文件
        $localTranslations = $this->loadLocalTranslations();

        // 获取外部系统翻译
        $externalTranslations = [];
        try {
            if ($this->apiClient->checkConnection()) {
                $externalTranslations = $this->apiClient->getTranslations();
            }
        } catch (\Exception $e) {
            $this->warn("无法获取外部系统翻译: {$e->getMessage()}");
        }

        return [
            'collected' => $collectedTranslations,
            'local' => $localTranslations,
            'external' => $externalTranslations,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * 生成报告
     *
     * @param array $data
     * @return array
     */
    protected function generateReport(array $data): array
    {
        $this->line('📝 生成报告内容...');

        $report = [
            'summary' => $this->generateSummary($data),
            'generated_at' => $data['generated_at'],
        ];

        if ($this->option('include-statistics')) {
            $report['statistics'] = $this->generateStatistics($data);
        }

        if ($this->option('include-missing')) {
            $report['missing_translations'] = $this->findMissingTranslations($data);
        }

        if ($this->option('include-unused')) {
            $report['unused_translations'] = $this->findUnusedTranslations($data);
        }

        $report['language_coverage'] = $this->generateLanguageCoverage($data);
        $report['modules_analysis'] = $this->generateModulesAnalysis($data);

        return $report;
    }

    /**
     * 生成摘要
     *
     * @param array $data
     * @return array
     */
    protected function generateSummary(array $data): array
    {
        $collectedCount = count($data['collected']);
        $localCount = array_sum(array_map('count', $data['local']));
        $externalCount = count($data['external']);

        return [
            'total_collected_keys' => $collectedCount,
            'total_local_translations' => $localCount,
            'total_external_translations' => $externalCount,
            'supported_languages' => array_keys($this->config['supported_languages']),
            'collection_statistics' => $this->collector->getStatistics(),
        ];
    }

    /**
     * 生成统计信息
     *
     * @param array $data
     * @return array
     */
    protected function generateStatistics(array $data): array
    {
        $stats = [];

        // 按模块统计
        $moduleStats = [];
        foreach ($data['collected'] as $translation) {
            $module = $translation['module'] ?? 'Core';
            $moduleStats[$module] = ($moduleStats[$module] ?? 0) + 1;
        }

        $stats['by_module'] = $moduleStats;

        // 按文件类型统计
        $fileTypeStats = [];
        foreach ($data['collected'] as $translation) {
            $fileType = $translation['file_type'] ?? 'unknown';
            $fileTypeStats[$fileType] = ($fileTypeStats[$fileType] ?? 0) + 1;
        }

        $stats['by_file_type'] = $fileTypeStats;

        // 按语言统计翻译覆盖率
        $languageStats = [];
        $totalKeys = count($data['collected']);

        foreach ($data['local'] as $language => $translations) {
            $translatedCount = count($translations);
            $coveragePercent = $totalKeys > 0 ? round(($translatedCount / $totalKeys) * 100, 2) : 0;

            $languageStats[$language] = [
                'total_translations' => $translatedCount,
                'coverage_percent' => $coveragePercent,
            ];
        }

        $stats['by_language'] = $languageStats;

        return $stats;
    }

    /**
     * 查找缺失的翻译
     *
     * @param array $data
     * @return array
     */
    protected function findMissingTranslations(array $data): array
    {
        $missing = [];
        $collectedKeys = array_column($data['collected'], 'key');

        foreach ($data['local'] as $language => $translations) {
            $localKeys = array_keys($translations);
            $missingKeys = array_diff($collectedKeys, $localKeys);

            if (!empty($missingKeys)) {
                $missing[$language] = array_map(function ($key) use ($data) {
                    $translation = collect($data['collected'])->firstWhere('key', $key);
                    return [
                        'key' => $key,
                        'source_file' => $translation['source_file'] ?? '',
                        'module' => $translation['module'] ?? '',
                    ];
                }, $missingKeys);
            }
        }

        return $missing;
    }

    /**
     * 查找未使用的翻译
     *
     * @param array $data
     * @return array
     */
    protected function findUnusedTranslations(array $data): array
    {
        $unused = [];
        $collectedKeys = array_column($data['collected'], 'key');

        foreach ($data['local'] as $language => $translations) {
            $localKeys = array_keys($translations);
            $unusedKeys = array_diff($localKeys, $collectedKeys);

            if (!empty($unusedKeys)) {
                $unused[$language] = array_map(function ($key) use ($translations) {
                    return [
                        'key' => $key,
                        'value' => $translations[$key],
                    ];
                }, $unusedKeys);
            }
        }

        return $unused;
    }

    /**
     * 生成语言覆盖率
     *
     * @param array $data
     * @return array
     */
    protected function generateLanguageCoverage(array $data): array
    {
        $coverage = [];
        $totalKeys = count($data['collected']);

        foreach ($this->config['supported_languages'] as $langCode => $langName) {
            $translations = $data['local'][$langCode] ?? [];
            $translatedCount = count($translations);
            $coveragePercent = $totalKeys > 0 ? round(($translatedCount / $totalKeys) * 100, 2) : 0;

            $coverage[$langCode] = [
                'language_name' => $langName,
                'total_keys' => $totalKeys,
                'translated_keys' => $translatedCount,
                'missing_keys' => $totalKeys - $translatedCount,
                'coverage_percent' => $coveragePercent,
                'status' => $this->getCoverageStatus($coveragePercent),
            ];
        }

        return $coverage;
    }

    /**
     * 生成模块分析
     *
     * @param array $data
     * @return array
     */
    protected function generateModulesAnalysis(array $data): array
    {
        $analysis = [];

        // 按模块分组
        $moduleGroups = [];
        foreach ($data['collected'] as $translation) {
            $module = $translation['module'] ?? 'Core';
            $moduleGroups[$module][] = $translation;
        }

        foreach ($moduleGroups as $module => $translations) {
            $keys = array_column($translations, 'key');

            $analysis[$module] = [
                'total_keys' => count($keys),
                'unique_files' => count(array_unique(array_column($translations, 'source_file'))),
                'language_coverage' => $this->getModuleLanguageCoverage($keys, $data['local']),
            ];
        }

        return $analysis;
    }

    /**
     * 获取模块语言覆盖率
     *
     * @param array $moduleKeys
     * @param array $localTranslations
     * @return array
     */
    protected function getModuleLanguageCoverage(array $moduleKeys, array $localTranslations): array
    {
        $coverage = [];
        $totalKeys = count($moduleKeys);

        foreach ($localTranslations as $language => $translations) {
            $translatedKeys = array_intersect($moduleKeys, array_keys($translations));
            $translatedCount = count($translatedKeys);
            $coveragePercent = $totalKeys > 0 ? round(($translatedCount / $totalKeys) * 100, 2) : 0;

            $coverage[$language] = $coveragePercent;
        }

        return $coverage;
    }

    /**
     * 获取覆盖率状态
     *
     * @param float $percent
     * @return string
     */
    protected function getCoverageStatus(float $percent): string
    {
        if ($percent >= 95) {
            return 'excellent';
        } elseif ($percent >= 80) {
            return 'good';
        } elseif ($percent >= 60) {
            return 'fair';
        } else {
            return 'poor';
        }
    }

    /**
     * 加载本地翻译
     *
     * @return array
     */
    protected function loadLocalTranslations(): array
    {
        $translations = [];
        $langPath = $this->config['lang_path'];
        $languages = $this->option('language') ?: array_keys($this->config['supported_languages']);

        foreach ($languages as $language) {
            $languageDir = "{$langPath}/{$language}";

            if (!File::exists($languageDir)) {
                continue;
            }

            // 尝试加载不同格式的文件
            $langData = [];

            // JSON 格式 - 直接在lang目录下的{language}.json文件
            $jsonFile = "{$langPath}/{$language}.json";
            if (File::exists($jsonFile)) {
                $content = File::get($jsonFile);
                $langData = array_merge($langData, json_decode($content, true) ?: []);
            }

            // PHP 格式 - 扫描lang/{language}/目录下的所有.php文件
            if (File::exists($languageDir) && File::isDirectory($languageDir)) {
                $phpFiles = File::glob("{$languageDir}/*.php");
                foreach ($phpFiles as $phpFile) {
                    try {
                        $fileData = include $phpFile;
                        if (is_array($fileData)) {
                            $fileName = pathinfo($phpFile, PATHINFO_FILENAME);
                            // 为PHP文件中的键加上文件名前缀
                            foreach ($fileData as $key => $value) {
                                $flatData = $this->flattenArray($fileData, $fileName);
                                $langData = array_merge($langData, $flatData);
                                break; // 只需要展平一次
                            }
                        }
                    } catch (\Exception $e) {
                        // 忽略无法加载的文件
                    }
                }
            }

            $translations[$language] = $langData;
        }

        return $translations;
    }

    /**
     * 输出报告
     *
     * @param array $report
     */
    protected function outputReport(array $report): void
    {
        $format = $this->option('format');
        $output = $this->option('output');

        switch ($format) {
            case 'html':
                $content = $this->generateHtmlReport($report);
                break;
            case 'csv':
                $content = $this->generateCsvReport($report);
                break;
            case 'json':
            default:
                $content = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                break;
        }

        if ($output) {
            $this->saveReportToFile($output, $content);
        } else {
            $this->displayReportSummary($report);
            if ($format === 'json') {
                $this->line($content);
            }
        }
    }

    /**
     * 生成HTML报告
     *
     * @param array $report
     * @return string
     */
    protected function generateHtmlReport(array $report): string
    {
        // 这里可以实现HTML报告模板
        // 为了简化，暂时返回基本HTML
        $html = '<html><head><title>翻译状态报告</title></head><body>';
        $html .= '<h1>翻译状态报告</h1>';
        $html .= '<pre>' . json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
        $html .= '</body></html>';

        return $html;
    }

    /**
     * 生成CSV报告
     *
     * @param array $report
     * @return string
     */
    protected function generateCsvReport(array $report): string
    {
        $csv = "语言,总键数,已翻译,未翻译,覆盖率,状态\n";

        foreach ($report['language_coverage'] ?? [] as $langCode => $coverage) {
            $csv .= sprintf(
                '"%s","%s","%s","%s","%s%%","%s"' . "\n",
                $langCode,
                $coverage['total_keys'],
                $coverage['translated_keys'],
                $coverage['missing_keys'],
                $coverage['coverage_percent'],
                $coverage['status']
            );
        }

        return $csv;
    }

    /**
     * 显示报告摘要
     *
     * @param array $report
     */
    protected function displayReportSummary(array $report): void
    {
        $summary = $report['summary'];

        $this->info('📊 翻译状态报告摘要:');
        $this->table(
            ['项目', '数量'],
            [
                ['收集到的翻译键', number_format($summary['total_collected_keys'])],
                ['本地翻译总数', number_format($summary['total_local_translations'])],
                ['外部系统翻译数', number_format($summary['total_external_translations'])],
                ['支持的语言数', count($summary['supported_languages'])],
            ]
        );

        // 显示语言覆盖率
        if (isset($report['language_coverage'])) {
            $this->info('🌍 语言覆盖率:');
            $headers = ['语言', '覆盖率', '已翻译', '未翻译', '状态'];
            $rows = [];

            foreach ($report['language_coverage'] as $langCode => $coverage) {
                $rows[] = [
                    $coverage['language_name'],
                    $coverage['coverage_percent'] . '%',
                    $coverage['translated_keys'],
                    $coverage['missing_keys'],
                    ucfirst($coverage['status']),
                ];
            }

            $this->table($headers, $rows);
        }
    }

    /**
     * 保存报告到文件
     *
     * @param string $path
     * @param string $content
     */
    protected function saveReportToFile(string $path, string $content): void
    {
        $directory = dirname($path);

        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        File::put($path, $content);
        $this->info("✅ 报告已保存到: {$path}");
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

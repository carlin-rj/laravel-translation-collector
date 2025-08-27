<?php

namespace Carlin\LaravelTranslationCollector\Tests\Feature;

use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Carlin\LaravelTranslationCollector\Facades\TranslationCollector;

class TranslationCollectionFeatureTest extends TestCase
{
    /**
     * 测试完整的翻译收集流程
     */
    public function test_complete_translation_collection_workflow()
    {
        $tempDir = $this->getTempDirectory();

        // 配置扫描路径
        config([
            'translation-collector.scan_paths' => [
                $tempDir . '/app/',
                $tempDir . '/Modules/',
            ],
            'translation-collector.modules_support.enabled' => true,
            'translation-collector.modules_support.modules_path' => $tempDir . '/Modules',
            'translation-collector.modules_support.scan_module_paths' => [
                'Http/',
                'Resources/views/',
                'Console/',
                'Services/',
            ],
        ]);

        // 1. 收集翻译
        $translations = TranslationCollector::collect();
        $this->assertIsArray($translations);
        $this->assertNotEmpty($translations);

		// 2. 验证收集到的翻译
        $keys = array_column($translations, 'key');
        $expectedKeys = [
            '这是中文标题', // 直接文本
            'Text with space', // 直接文本
            'user.login.success', // 存在的翻译键
            'user.store.success', // 存在的翻译键（添加了翻译）
        ];

        foreach ($expectedKeys as $expectedKey) {
            $this->assertContains($expectedKey, $keys, "期望的翻译键 '{$expectedKey}' 没有被收集到");
        }

        // 3. 验证翻译数据结构
        foreach ($translations as $translation) {
            $this->assertValidTranslationStructure($translation);
            $this->assertNotEmpty($translation['key']);
            $this->assertNotEmpty($translation['source_file']);
            $this->assertGreaterThan(0, $translation['line_number']);
        }

        // 4. 验证模块识别
        $moduleTranslations = array_filter($translations, function ($t) {
            return isset($t['module']) && $t['module'] === 'User';
        });
        $this->assertNotEmpty($moduleTranslations, '应该能识别到User模块的翻译');

        // 5. 验证文件类型识别
        $fileTypes = array_unique(array_column($translations, 'file_type'));
        $this->assertContains('php', $fileTypes);
        $this->assertContains('blade.php', $fileTypes);

        // 6. 获取统计信息
        $statistics = TranslationCollector::getStatistics();
        $this->assertArrayHasKey('total_files_scanned', $statistics);
        $this->assertArrayHasKey('total_translations_found', $statistics);
        $this->assertEquals(count($translations), $statistics['total_translations_found']);
        $this->assertGreaterThan(0, $statistics['total_files_scanned']);
        $this->assertGreaterThan(0, $statistics['scan_duration']);
    }

    /**
     * 测试按模块收集翻译
     */
    public function test_module_specific_collection()
    {
        $tempDir = $this->getTempDirectory();

        config([
            'translation-collector.modules_support.enabled' => true,
            'translation-collector.modules_support.modules_path' => $tempDir . '/Modules',
            'translation-collector.modules_support.scan_module_paths' => [
                'Http/',
                'Resources/views/',
                'Console/',
                'Services/',
            ],
        ]);

        // 收集特定模块的翻译
        $userTranslations = TranslationCollector::scanModules(['User']);

        $this->assertIsArray($userTranslations);

        // 验证所有翻译都属于User模块
        foreach ($userTranslations as $translation) {
            $this->assertEquals('User', $translation['module']);
            $this->assertStringContainsString('Modules/User', $translation['source_file']);
        }

        // 验证找到了Blade模板中的翻译
        $bladeTranslations = array_filter($userTranslations, function ($t) {
            return $t['file_type'] === 'blade.php';
        });
        $this->assertNotEmpty($bladeTranslations);

        $bladeKeys = array_column($bladeTranslations, 'key');
        $this->assertContains('user.title', $bladeKeys);
        $this->assertContains('user.description', $bladeKeys);
        $this->assertContains('user.submit', $bladeKeys);
        $this->assertContains('user.cancel', $bladeKeys);
    }

    /**
     * 测试不同文件类型的翻译识别
     */
    public function test_different_file_types_recognition()
    {
        $tempDir = $this->getTempDirectory();

        // 创建不同类型的测试文件
        $this->createAdvancedTestFiles($tempDir);

        config([
            'translation-collector.scan_paths' => [$tempDir . '/advanced/'],
            'translation-collector.lang_path' => $tempDir . '/lang', // 正确设置翻译文件路径
            'translation-collector.scan_file_extensions' => ['php', 'blade.php'],
            'translation-collector.regex_patterns' => [
                'php' => [
                    '/__\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                    '/trans\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                ],
                'blade' => [
                    '/@lang\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)/',
                    '/\{\{\s*__\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*\}\}/',
                ],
            ],
        ]);

        $translations = TranslationCollector::scanPaths([$tempDir . '/advanced/']);

        // 按文件类型分组验证
        $byFileType = [];
        foreach ($translations as $translation) {
            $fileType = $translation['file_type'];
            $byFileType[$fileType][] = $translation['key'];
        }

        // 验证PHP文件翻译
        $this->assertArrayHasKey('php', $byFileType);
        $this->assertContains('advanced.php.key', $byFileType['php']);

        // 验证Blade文件翻译
        $this->assertArrayHasKey('blade.php', $byFileType);
        $this->assertContains('advanced.blade.key', $byFileType['blade.php']);
    }

    /**
     * 测试翻译差异分析
     */
    public function test_translation_difference_analysis()
    {
        // 准备测试数据
        $collected = [
            ['key' => 'common.save', 'source_file' => 'new.php', 'line_number' => 10, 'context' => 'new context'],
            ['key' => 'common.cancel', 'source_file' => 'new.php', 'line_number' => 11, 'context' => 'context'],
            ['key' => 'user.new_feature', 'source_file' => 'feature.php', 'line_number' => 5, 'context' => 'context'],
        ];

        $existing = [
            ['key' => 'common.save', 'source_file' => 'old.php', 'line_number' => 5, 'context' => 'old context'],
            ['key' => 'common.delete', 'source_file' => 'old.php', 'line_number' => 6, 'context' => 'context'],
            ['key' => 'user.old_feature', 'source_file' => 'old.php', 'line_number' => 7, 'context' => 'context'],
        ];

        $differences = TranslationCollector::analyzeDifferences($collected, $existing);

        // 验证分析结果结构
        $this->assertArrayHasKey('new', $differences);
        $this->assertArrayHasKey('updated', $differences);
        $this->assertArrayHasKey('deleted', $differences);
        $this->assertArrayHasKey('unchanged', $differences);

        // 验证新增的翻译
        $newKeys = array_column($differences['new'], 'key');
        $this->assertContains('common.cancel', $newKeys);
        $this->assertContains('user.new_feature', $newKeys);

        // 验证已删除的翻译
        $deletedKeys = array_column($differences['deleted'], 'key');
        $this->assertContains('common.delete', $deletedKeys);
        $this->assertContains('user.old_feature', $deletedKeys);

        // 验证更新的翻译（源文件或上下文发生变化）
        $updatedKeys = array_column($differences['updated'], 'key');
        $this->assertContains('common.save', $updatedKeys);

        // 验证统计信息更新
        $statistics = TranslationCollector::getStatistics();
        $this->assertEquals(2, $statistics['new_translations']);
        $this->assertEquals(1, $statistics['existing_translations']);
    }


    /**
     * 创建高级测试文件
     */
    protected function createAdvancedTestFiles(string $tempDir): void
    {
        $advancedDir = $tempDir . '/advanced/';
        if (!is_dir($advancedDir)) {
            mkdir($advancedDir, 0755, true);
        }

        // 创建翻译文件目录
        $langDir = $tempDir . '/lang';
        if (!is_dir($langDir)) {
            mkdir($langDir, 0755, true);
        }

        // 创建翻译文件，支持高级测试所需的键
        file_put_contents(
            $langDir . '/en.json',
            json_encode([
                'advanced.php.key' => 'Advanced PHP Key Value',
                'advanced.blade.key' => 'Advanced Blade Key Value',
            ], JSON_PRETTY_PRINT)
        );

        // PHP文件
        file_put_contents(
            $advancedDir . 'AdvancedController.php',
            '<?php
            class AdvancedController {
                public function test() {
                    return __("advanced.php.key");
                }
            }'
        );

        // Blade文件
        file_put_contents(
            $advancedDir . 'advanced.blade.php',
            '<div>
                <h1>{{ __("advanced.blade.key") }}</h1>
            </div>'
        );
    }
}

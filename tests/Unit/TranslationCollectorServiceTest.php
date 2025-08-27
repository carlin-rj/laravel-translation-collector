<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Carlin\LaravelTranslationCollector\Services\TranslationCollectorService;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;

class TranslationCollectorServiceTest extends TestCase
{
    /**
     * 翻译收集器实例
     *
     * @var TranslationCollectorService
     */
    protected $collector;

    /**
     * 设置测试
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = $this->app->make(TranslationCollectorInterface::class);
    }

    /**
     * 测试收集器实例化
     */
    public function test_collector_can_be_instantiated()
    {
        $this->assertInstanceOf(TranslationCollectorService::class, $this->collector);
        $this->assertInstanceOf(TranslationCollectorInterface::class, $this->collector);
    }

    /**
     * 测试设置选项
     */
    public function test_can_set_options()
    {
        $options = ['test' => 'value'];
        $result = $this->collector->setOptions($options);

        $this->assertInstanceOf(TranslationCollectorInterface::class, $result);
    }

    /**
     * 测试获取统计信息
     */
    public function test_can_get_statistics()
    {
        $statistics = $this->collector->getStatistics();

        $this->assertIsArray($statistics);
        $this->assertArrayHasKey('total_files_scanned', $statistics);
        $this->assertArrayHasKey('total_translations_found', $statistics);
        $this->assertArrayHasKey('new_translations', $statistics);
        $this->assertArrayHasKey('existing_translations', $statistics);
        $this->assertArrayHasKey('scan_duration', $statistics);
    }

    /**
     * 测试扫描路径
     */
    public function test_can_scan_paths()
    {
        $tempDir = $this->getTempDirectory();
        $paths = ['app/'];

        // 设置扫描路径配置
        config(['translation-collector.scan_paths' => [$tempDir . '/app/']]);

        $translations = $this->collector->scanPaths([$tempDir . '/app/']);

        $this->assertIsArray($translations);

        // 验证找到的翻译
        if (!empty($translations)) {
            $this->assertTranslationKeyExists('user.login.success', $translations);
            $this->assertTranslationKeyExists('user.logout', $translations);
            $this->assertTranslationKeyExists('welcome.message', $translations);

            foreach ($translations as $translation) {
                $this->assertValidTranslationStructure($translation);
            }
        }
    }

    /**
     * 测试扫描单个路径
     */
    public function test_can_scan_single_path()
    {
        $tempDir = $this->getTempDirectory();
        $path = $tempDir . '/app/';

        $translations = $this->collector->scanPaths($path);

        $this->assertIsArray($translations);
    }

    /**
     * 测试扫描不存在的路径
     */
    public function test_scan_nonexistent_path_returns_empty_array()
    {
        $translations = $this->collector->scanPaths(['/path/that/does/not/exist']);

        $this->assertIsArray($translations);
        $this->assertEmpty($translations);
    }

    /**
     * 测试扫描模块
     */
    public function test_can_scan_modules()
    {
        $tempDir = $this->getTempDirectory();

        // 设置模块配置
        config([
            'translation-collector.modules_support.enabled' => true,
            'translation-collector.modules_support.modules_path' => $tempDir . '/Modules',
        ]);

        $translations = $this->collector->scanModules(['User']);

        $this->assertIsArray($translations);

        // 验证模块翻译
        if (!empty($translations)) {
            foreach ($translations as $translation) {
                $this->assertValidTranslationStructure($translation);
                $this->assertEquals('User', $translation['module']);
            }
        }
    }

    /**
     * 测试扫描所有模块
     */
    public function test_can_scan_all_modules()
    {
        $tempDir = $this->getTempDirectory();

        config([
            'translation-collector.modules_support.enabled' => true,
            'translation-collector.modules_support.modules_path' => $tempDir . '/Modules',
        ]);

        $translations = $this->collector->scanModules();

        $this->assertIsArray($translations);
    }

    /**
     * 测试禁用模块支持时扫描模块
     */
    public function test_scan_modules_when_disabled_returns_empty_array()
    {
        config(['translation-collector.modules_support.enabled' => false]);

        $translations = $this->collector->scanModules(['User']);

        $this->assertIsArray($translations);
        $this->assertEmpty($translations);
    }

    /**
     * 测试分析差异
     */
    public function test_can_analyze_differences()
    {
        $collected = [
            ['key' => 'user.login.success', 'source_file' => 'test.php', 'line_number' => 1, 'context' => 'test'],
            ['key' => 'user.new.key', 'source_file' => 'test.php', 'line_number' => 2, 'context' => 'test'],
        ];

        $existing = [
            ['key' => 'user.login.success', 'source_file' => 'old.php', 'line_number' => 1, 'context' => 'test'],
            ['key' => 'user.old.key', 'source_file' => 'old.php', 'line_number' => 3, 'context' => 'test'],
        ];

        $differences = $this->collector->analyzeDifferences($collected, $existing);

        $this->assertIsArray($differences);
        $this->assertArrayHasKey('new', $differences);
        $this->assertArrayHasKey('updated', $differences);
        $this->assertArrayHasKey('deleted', $differences);
        $this->assertArrayHasKey('unchanged', $differences);

        // 验证新增的翻译
        $this->assertCount(1, $differences['new']);
        $this->assertEquals('user.new.key', $differences['new'][0]['key']);

        // 验证删除的翻译
        $this->assertCount(1, $differences['deleted']);
        $this->assertEquals('user.old.key', $differences['deleted'][0]['key']);

        // 验证更新的翻译（源文件不同）
        $this->assertCount(1, $differences['updated']);
        $this->assertEquals('user.login.success', $differences['updated'][0]['key']);
    }

    /**
     * 测试完整收集流程
     */
    public function test_can_collect_translations()
    {
        $tempDir = $this->getTempDirectory();

        // 设置配置
        config([
            'translation-collector.scan_paths' => [$tempDir . '/app/'],
            'translation-collector.modules_support.enabled' => true,
            'translation-collector.modules_support.modules_path' => $tempDir . '/Modules',
        ]);

        $translations = $this->collector->collect();

        $this->assertIsArray($translations);

        // 获取统计信息
        $statistics = $this->collector->getStatistics();
        $this->assertGreaterThanOrEqual(0, $statistics['total_files_scanned']);
        $this->assertEquals(count($translations), $statistics['total_translations_found']);
        $this->assertGreaterThan(0, $statistics['scan_duration']);
    }

    /**
     * 测试带选项的收集
     */
    public function test_can_collect_with_options()
    {
        $tempDir = $this->getTempDirectory();

        $options = [
            'paths' => [$tempDir . '/app/'],
            'use_cache' => false,
        ];

        $translations = $this->collector->collect($options);

        $this->assertIsArray($translations);
    }

    /**
     * 测试正则表达式模式匹配
     */
    public function test_regex_patterns_match_translation_functions()
    {
        $tempDir = $this->getTempDirectory();

        // 创建包含各种翻译函数调用的测试文件
        file_put_contents(
            $tempDir . '/app/TranslationFunctionsController.php',
            '<?php
            class TranslationFunctionsController {
                public function test() {
                    $a = __("underscore.function");
                    $b = trans("trans.function");
                    $c = Lang::get("lang.facade");
                    $d = trans_choice("choice.function", 1);
                }
            }'
        );

        $translations = $this->collector->scanPaths([$tempDir . '/app/']);

        $keys = array_column($translations, 'key');
        $this->assertContains('underscore.function', $keys);
        $this->assertContains('trans.function', $keys);
        $this->assertContains('lang.facade', $keys);
        $this->assertContains('choice.function', $keys);
    }
}

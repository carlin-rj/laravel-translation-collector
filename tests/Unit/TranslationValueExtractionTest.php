<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Carlin\LaravelTranslationCollector\Services\TranslationCollectorService;
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;

class TranslationValueExtractionTest extends TestCase
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
     * 测试现有翻译文件扫描
     */
    public function test_scan_existing_translations()
    {
        $tempDir = $this->getTempDirectory();

        // 创建测试翻译文件
        $this->createTestTranslationFiles($tempDir);

        // 设置lang_path
        config(['translation-collector.lang_path' => $tempDir . '/lang']);

        // 扫描现有翻译文件
        $translations = $this->collector->scanExistingTranslations(['en', 'zh_CN']);
        $this->assertIsArray($translations);
        $this->assertNotEmpty($translations);

        // 验证JSON格式的翻译
        $jsonTranslations = array_filter($translations, function ($t) {
            return $t['file_type'] === 'json';
        });
        $this->assertNotEmpty($jsonTranslations);

        // 验证PHP格式的翻译
        $phpTranslations = array_filter($translations, function ($t) {
            return $t['file_type'] === 'php';
        });
        $this->assertNotEmpty($phpTranslations);

        // 验证翻译数据结构
        foreach ($translations as $translation) {
            $this->assertArrayHasKey('key', $translation);
            $this->assertArrayHasKey('default_text', $translation);
            $this->assertArrayHasKey('source_file', $translation);
            $this->assertArrayHasKey('language', $translation);
            $this->assertArrayHasKey('source_type', $translation);
            $this->assertEquals('translation_file', $translation['source_type']);
        }
    }

    /**
     * 测试JSON翻译文件加载
     */
    public function test_load_json_translations()
    {
        $tempDir = $this->getTempDirectory();
        $this->createTestTranslationFiles($tempDir);

        config(['translation-collector.lang_path' => $tempDir . '/lang']);

        $translations = $this->collector->scanExistingTranslations(['en']);

        $jsonTranslations = array_filter($translations, function ($t) {
            return $t['file_type'] === 'json' && $t['language'] === 'en';
        });

        $this->assertNotEmpty($jsonTranslations);

        $keys = array_column($jsonTranslations, 'key');
        $this->assertContains('user.login.success', $keys);
        $this->assertContains('user.logout', $keys);
    }

    /**
     * 测试PHP翻译文件加载
     */
    public function test_load_php_translations()
    {
        $tempDir = $this->getTempDirectory();
        $this->createTestTranslationFiles($tempDir);

        config(['translation-collector.lang_path' => $tempDir . '/lang']);

        $translations = $this->collector->scanExistingTranslations(['en']);

        $phpTranslations = array_filter($translations, function ($t) {
            return $t['file_type'] === 'php' && $t['language'] === 'en';
        });

        $this->assertNotEmpty($phpTranslations);

        $keys = array_column($phpTranslations, 'key');
        $this->assertContains('messages.welcome', $keys);
        $this->assertContains('messages.goodbye', $keys);
        $this->assertContains('validation.required', $keys);
    }

    /**
     * 测试直接文本识别
     */
    public function test_direct_text_recognition()
    {
        $tempDir = $this->getTempDirectory();

        // 创建包含直接中文文本的测试文件
        file_put_contents(
            $tempDir . '/app/DirectTextController.php',
            '<?php
            class DirectTextController {
                public function test() {
                    $chinese = __("这是中文标题");
                    $longText = __("This is a very long text that should be recognized as direct text");
                    $withSpace = __("Text with space");
                    $normalKey = __("user.login.title");
                }
            }'
        );

        config([
            'translation-collector.scan_paths' => [$tempDir . '/app/'],
            'translation-collector.lang_path' => $tempDir . '/lang',
        ]);

        $translations = $this->collector->scanPaths([$tempDir . '/app/']);

        // 验证直接文本识别
        $directTexts = array_filter($translations, function ($t) {
            return $t['is_direct_text'] === true;
        });

        $this->assertNotEmpty($directTexts);

        $directTextKeys = array_column($directTexts, 'key');
        $directTextValues = array_column($directTexts, 'default_text');

        // 验证中文文本被识别为直接文本
        $this->assertContains('这是中文标题', $directTextValues);

        // 验证长文本被识别为直接文本
        $this->assertContains('This is a very long text that should be recognized as direct text', $directTextValues);

        // 验证包含空格的文本被识别为直接文本
        $this->assertContains('Text with space', $directTextValues);
    }

    /**
     * 测试翻译键值解析
     */
    public function test_translation_key_value_resolution()
    {
        $tempDir = $this->getTempDirectory();
        $this->createTestTranslationFiles($tempDir);

        // 创建包含翻译键的测试文件
        file_put_contents(
            $tempDir . '/app/KeyResolutionController.php',
            '<?php
            class KeyResolutionController {
                public function test() {
                    $msg1 = __("user.login.success");
                    $msg2 = __("messages.welcome");
                    $msg3 = __("nonexistent.key"); // 这个键不存在于翻译文件中，应该被跳过
                }
            }'
        );

        config([
            'translation-collector.scan_paths' => [$tempDir . '/app/'],
            'translation-collector.lang_path' => $tempDir . '/lang',
        ]);

        $translations = $this->collector->scanPaths([$tempDir . '/app/']);

        // 验证翻译键解析
        $keyTranslations = array_filter($translations, function ($t) {
            return $t['is_direct_text'] === false;
        });

        $this->assertNotEmpty($keyTranslations);

        // 验证收集到的翻译键
        $collectedKeys = array_column($keyTranslations, 'key');
        
        // 验证存在的翻译键被正确收集
        $this->assertContains('user.login.success', $collectedKeys, '存在的翻译键 user.login.success 应该被收集');
        $this->assertContains('messages.welcome', $collectedKeys, '存在的翻译键 messages.welcome 应该被收集');
        
        // 验证不存在的翻译键被跳过（根据新的业务逻辑）
        $this->assertNotContains('nonexistent.key', $collectedKeys, '不存在的翻译键 nonexistent.key 应该被跳过，不在收集结果中');

        // 验证收集到的翻译键的值
        foreach ($keyTranslations as $translation) {
            if ($translation['key'] === 'user.login.success') {
                $this->assertEquals('Login successful', $translation['default_text']);
            } elseif ($translation['key'] === 'messages.welcome') {
                $this->assertEquals('Welcome to our application', $translation['default_text']);
            }
        }
    }

    /**
     * 测试嵌套PHP翻译数组的展平
     */
    public function test_nested_php_translation_flattening()
    {
        $tempDir = $this->getTempDirectory();

        // 创建嵌套的PHP翻译文件
        $nestedDir = $tempDir . '/lang/en';
        if (!is_dir($nestedDir)) {
            mkdir($nestedDir, 0755, true);
        }

        file_put_contents(
            $nestedDir . '/nested.php',
            '<?php
            return [
                "user" => [
                    "profile" => [
                        "name" => "Name",
                        "email" => "Email",
                    ],
                    "settings" => [
                        "theme" => "Theme",
                        "language" => "Language",
                    ]
                ],
                "admin" => [
                    "dashboard" => "Dashboard"
                ]
            ];'
        );

        config(['translation-collector.lang_path' => $tempDir . '/lang']);

        $translations = $this->collector->scanExistingTranslations(['en']);

        $nestedTranslations = array_filter($translations, function ($t) {
            return str_contains($t['source_file'], 'nested.php');
        });

        $this->assertNotEmpty($nestedTranslations);

        $keys = array_column($nestedTranslations, 'key');
        $this->assertContains('nested.user.profile.name', $keys);
        $this->assertContains('nested.user.profile.email', $keys);
        $this->assertContains('nested.user.settings.theme', $keys);
        $this->assertContains('nested.admin.dashboard', $keys);
    }

    /**
     * 测试跳过不存在的翻译键
     */
    public function test_skips_nonexistent_translation_keys()
    {
        $tempDir = $this->getTempDirectory();
        $this->createTestTranslationFiles($tempDir);

        // 创建包含多个不存在翻译键的测试文件
        file_put_contents(
            $tempDir . '/app/SkipTestController.php',
            '<?php
            class SkipTestController {
                public function test() {
                    // 存在的翻译键
                    $existing1 = __("user.login.success");
                    $existing2 = __("messages.welcome");
                    
                    // 不存在的翻译键，应该被跳过
                    $nonexistent1 = __("message.error");
                    $nonexistent2 = __("user.unknown.action");
                    $nonexistent3 = __("completely.missing.key");
                    
                    // 存在的翻译键（嵌套的）
                    $existing3 = __("messages.user.name");
                }
            }'
        );

        config([
            'translation-collector.scan_paths' => [$tempDir . '/app/'],
            'translation-collector.lang_path' => $tempDir . '/lang',
        ]);

        $translations = $this->collector->scanPaths([$tempDir . '/app/']);

        // 过滤出非直接文本的翻译（即翻译键）
        $keyTranslations = array_filter($translations, function ($t) {
            return isset($t['is_direct_text']) && $t['is_direct_text'] === false;
        });

        $collectedKeys = array_column($keyTranslations, 'key');

        // 验证存在的翻译键被收集
        $this->assertContains('user.login.success', $collectedKeys, '存在的翻译键 user.login.success 应该被收集');
        $this->assertContains('messages.welcome', $collectedKeys, '存在的翻译键 messages.welcome 应该被收集');
        $this->assertContains('messages.user.name', $collectedKeys, '存在的嵌套翻译键 messages.user.name 应该被收集');

        // 验证不存在的翻译键被跳过
        $this->assertNotContains('message.error', $collectedKeys, '不存在的翻译键 message.error 应该被跳过');
        $this->assertNotContains('user.unknown.action', $collectedKeys, '不存在的翻译键 user.unknown.action 应该被跳过');
        $this->assertNotContains('completely.missing.key', $collectedKeys, '不存在的翻译键 completely.missing.key 应该被跳过');

        // 验证收集的数量（首先查看实际收集到了什么）
        $expectedExistingKeys = ['user.login.success', 'messages.welcome', 'messages.user.name'];
        $actualExistingKeys = array_intersect($collectedKeys, $expectedExistingKeys);
        $this->assertGreaterThanOrEqual(count($expectedExistingKeys), count($actualExistingKeys), '应该至少收集存在的翻译键');
        
        // 记录实际收集到的键，用于调试
        // error_log('Collected keys: ' . json_encode($collectedKeys));
    }

    /**
     * 创建测试翻译文件
     */
    protected function createTestTranslationFiles(string $tempDir): void
    {
        $langDir = $tempDir . '/lang';
        $enDir = $langDir . '/en';
        $zhDir = $langDir . '/zh_CN';

        // 创建目录
        foreach ([$langDir, $enDir, $zhDir] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // 创建JSON格式翻译文件
        file_put_contents(
            $langDir . '/en.json',
            json_encode([
                'user.login.success' => 'Login successful',
                'user.logout' => 'Logout',
                'welcome.message' => 'Welcome to our site',
            ], JSON_PRETTY_PRINT)
        );

        file_put_contents(
            $langDir . '/zh_CN.json',
            json_encode([
                'user.login.success' => '登录成功',
                'user.logout' => '退出登录',
                'welcome.message' => '欢迎来到我们的网站',
            ], JSON_PRETTY_PRINT)
        );

        // 创建PHP格式翻译文件
        file_put_contents(
            $enDir . '/messages.php',
            '<?php
            return [
                "welcome" => "Welcome to our application",
                "goodbye" => "Goodbye!",
                "user" => [
                    "name" => "User Name",
                    "email" => "Email Address",
                ]
            ];'
        );

        file_put_contents(
            $enDir . '/validation.php',
            '<?php
            return [
                "required" => "This field is required",
                "email" => "Please enter a valid email",
                "min" => "Minimum length is :min characters",
            ];'
        );

        file_put_contents(
            $zhDir . '/messages.php',
            '<?php
            return [
                "welcome" => "欢迎使用我们的应用",
                "goodbye" => "再见！",
                "user" => [
                    "name" => "用户名",
                    "email" => "邮箱地址",
                ]
            ];'
        );
    }
}

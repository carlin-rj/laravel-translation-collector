<?php

namespace Carlin\LaravelTranslationCollector\Tests\Unit;

use Carlin\LaravelTranslationCollector\Tests\TestCase;
use Carlin\LaravelTranslationCollector\Services\TranslationCollectorService;
use Illuminate\Support\Facades\File;

/**
 * 测试TranslationCollectorService的保护方法
 */
class TranslationCollectorProtectedMethodsTest extends TestCase
{
    /**
     * 翻译收集器实例
     *
     * @var TestableTranslationCollectorService
     */
    protected $collector;

    /**
     * 设置测试
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->collector = new TestableTranslationCollectorService($this->app);
    }

    /**
     * 测试loadJsonTranslations方法
     */
    public function test_load_json_translations()
    {
        $tempDir = $this->getTempDirectory();
        $langPath = $tempDir . '/lang';
        $jsonFile = $langPath . '/en.json';

        // 确保目录存在
        File::ensureDirectoryExists($langPath);

        // 创建测试JSON文件
        File::put($jsonFile, json_encode([
            'welcome' => 'Welcome to our application',
            'goodbye' => 'Goodbye!',
            'user.login' => 'Please login'
        ]));

        $translations = $this->collector->loadJsonTranslations($jsonFile, 'en');

        $this->assertIsArray($translations);
        $this->assertCount(3, $translations);

        foreach ($translations as $translation) {
            $this->assertArrayHasKey('key', $translation);
            $this->assertArrayHasKey('value', $translation);
            $this->assertArrayHasKey('language', $translation);
            $this->assertArrayHasKey('file_type', $translation);
            $this->assertArrayHasKey('source_type', $translation);
            
            $this->assertEquals('en', $translation['language']);
            $this->assertEquals('json', $translation['file_type']);
            $this->assertEquals('translation_file', $translation['source_type']);
            $this->assertTrue($translation['is_direct_text']);
        }

        // 验证特定的翻译
        $welcomeTranslation = collect($translations)->firstWhere('key', 'welcome');
        $this->assertNotNull($welcomeTranslation);
        $this->assertEquals('Welcome to our application', $welcomeTranslation['value']);
    }

    /**
     * 测试loadJsonTranslations方法处理无效JSON
     */
    public function test_load_json_translations_with_invalid_json()
    {
        $tempDir = $this->getTempDirectory();
        $langPath = $tempDir . '/lang';
        $jsonFile = $langPath . '/invalid.json';

        File::ensureDirectoryExists($langPath);
        File::put($jsonFile, '{"invalid": json}'); // 无效JSON

        $translations = $this->collector->loadJsonTranslations($jsonFile, 'en');

        $this->assertIsArray($translations);
        $this->assertEmpty($translations);
    }

    /**
     * 测试loadPhpTranslations方法
     */
    public function test_load_php_translations()
    {
        $tempDir = $this->getTempDirectory();
        $langPath = $tempDir . '/lang';
        $languageDir = $langPath . '/en';

        File::ensureDirectoryExists($languageDir);

        // 创建测试PHP翻译文件
        File::put($languageDir . '/messages.php', "<?php\nreturn [\n    'welcome' => 'Welcome',\n    'user' => [\n        'login' => 'Login',\n        'logout' => 'Logout',\n    ],\n];");
        
        File::put($languageDir . '/auth.php', "<?php\nreturn [\n    'failed' => 'These credentials do not match our records.',\n    'throttle' => 'Too many login attempts.',\n];");

        $translations = $this->collector->loadPhpTranslations($languageDir, 'en');

        $this->assertIsArray($translations);
        $this->assertGreaterThan(0, count($translations));

        foreach ($translations as $translation) {
            $this->assertArrayHasKey('key', $translation);
            $this->assertArrayHasKey('value', $translation);
            $this->assertArrayHasKey('language', $translation);
            $this->assertArrayHasKey('file_type', $translation);
            $this->assertArrayHasKey('source_type', $translation);
            
            $this->assertEquals('en', $translation['language']);
            $this->assertEquals('php', $translation['file_type']);
            $this->assertEquals('translation_file', $translation['source_type']);
            $this->assertFalse($translation['is_direct_text']);
        }

        // 验证嵌套翻译键
        $userLoginTranslation = collect($translations)->firstWhere('key', 'messages.user.login');
        $this->assertNotNull($userLoginTranslation);
        $this->assertEquals('Login', $userLoginTranslation['value']);
    }

    /**
     * 测试flattenTranslations方法
     */
    public function test_flatten_translations()
    {
        $data = [
            'simple' => 'Simple translation',
            'nested' => [
                'level1' => 'Level 1',
                'deep' => [
                    'level2' => 'Level 2'
                ]
            ]
        ];

        $translations = $this->collector->flattenTranslations($data, 'test', '/test/file.php', 'en');

        $this->assertIsArray($translations);
        $this->assertCount(3, $translations);

        $keys = array_column($translations, 'key');
        $this->assertContains('test.simple', $keys);
        $this->assertContains('test.nested.level1', $keys);
        $this->assertContains('test.nested.deep.level2', $keys);
    }

    /**
     * 测试analyzeTranslationKeyAndValue方法 - 直接文本
     */
    public function test_analyze_translation_key_and_value_direct_text()
    {
        $result = $this->collector->analyzeTranslationKeyAndValue('Hello World');

        $this->assertIsArray($result);
        $this->assertEquals('Hello World', $result['key']);
        $this->assertEquals('Hello World', $result['value']);
        $this->assertTrue($result['is_direct_text']);
        $this->assertEquals('json', $result['file_type']);
    }

    /**
     * 测试analyzeTranslationKeyAndValue方法 - 翻译键
     */
    public function test_analyze_translation_key_and_value_translation_key()
    {
        // 创建临时翻译文件用于测试解析
        $tempDir = $this->getTempDirectory();
        $langPath = $tempDir . '/lang';
        
        config([
            'translation-collector.lang_path' => $langPath,
            'translation-collector.default_language' => 'en'
        ]);

        File::ensureDirectoryExists($langPath . '/en');
        File::put($langPath . '/en/messages.php', "<?php\nreturn ['welcome' => 'Welcome to our app'];");

        $result = $this->collector->analyzeTranslationKeyAndValue('messages.welcome');

        $this->assertIsArray($result);
        $this->assertEquals('messages.welcome', $result['key']);
        $this->assertEquals('Welcome to our app', $result['value']);
        $this->assertFalse($result['is_direct_text']);
        $this->assertEquals('php', $result['file_type']);
    }

    /**
     * 测试isDirectText方法
     */
    public function test_is_direct_text()
    {
        // 直接文本
        $this->assertTrue($this->collector->isDirectText('Hello World'));
        $this->assertTrue($this->collector->isDirectText('用户登录成功'));
        $this->assertTrue($this->collector->isDirectText('123 test'));
        
        // 翻译键
        $this->assertFalse($this->collector->isDirectText('user.login.success'));
        $this->assertFalse($this->collector->isDirectText('messages.welcome'));
        $this->assertFalse($this->collector->isDirectText('auth.failed'));
    }

    /**
     * 测试resolveTranslationValue方法
     */
    public function test_resolve_translation_value()
    {
        $tempDir = $this->getTempDirectory();
        $langPath = $tempDir . '/lang';
        
        config([
            'translation-collector.lang_path' => $langPath,
            'translation-collector.default_language' => 'en'
        ]);

        // 创建PHP翻译文件
        File::ensureDirectoryExists($langPath . '/en');
        File::put($langPath . '/en/messages.php', "<?php\nreturn ['welcome' => 'Welcome to our app'];");
        
        // 创建JSON翻译文件
        File::put($langPath . '/en.json', json_encode(['direct.key' => 'Direct translation']));

        // 测试从PHP文件解析
        $result = $this->collector->resolveTranslationValue('messages.welcome');
        $this->assertEquals('Welcome to our app', $result['value']);
        $this->assertEquals('php', $result['file_type']);

        // 测试从JSON文件解析
        $result = $this->collector->resolveTranslationValue('direct.key');
        $this->assertEquals('Direct translation', $result['value']);
        $this->assertEquals('json', $result['file_type']);

        // 测试不存在的键
        $result = $this->collector->resolveTranslationValue('nonexistent.key');
        $this->assertEmpty($result);
    }

    /**
     * 测试resolveFromPhpFiles方法
     */
    public function test_resolve_from_php_files()
    {
        $tempDir = $this->getTempDirectory();
        $langPath = $tempDir . '/lang';
        
        File::ensureDirectoryExists($langPath . '/en');
        File::put($langPath . '/en/messages.php', "<?php\nreturn [\n    'welcome' => 'Welcome',\n    'user' => [\n        'login' => 'User Login'\n    ]\n];");

        $result = $this->collector->resolveFromPhpFiles('messages.welcome', 'en', $langPath);
        $this->assertEquals('Welcome', $result);

        $result = $this->collector->resolveFromPhpFiles('messages.user.login', 'en', $langPath);
        $this->assertEquals('User Login', $result);

        $result = $this->collector->resolveFromPhpFiles('messages.nonexistent', 'en', $langPath);
        $this->assertNull($result);
    }

    /**
     * 测试resolveFromJsonFile方法
     */
    public function test_resolve_from_json_file()
    {
        $tempDir = $this->getTempDirectory();
        $langPath = $tempDir . '/lang';
        
        File::ensureDirectoryExists($langPath);
        File::put($langPath . '/en.json', json_encode([
            'welcome' => 'Welcome',
            'goodbye' => 'Goodbye'
        ]));

        $result = $this->collector->resolveFromJsonFile('welcome', 'en', $langPath);
        $this->assertEquals('Welcome', $result);

        $result = $this->collector->resolveFromJsonFile('nonexistent', 'en', $langPath);
        $this->assertNull($result);
    }

    /**
     * 测试scanDirectory方法
     */
    public function test_scan_directory()
    {
        $tempDir = $this->getTempDirectory();
        $testDir = $tempDir . '/scan_test';
        
        File::ensureDirectoryExists($testDir);
        File::put($testDir . '/test.php', "<?php echo __('test.message'); ?>");
        
        $translations = $this->collector->scanDirectory($testDir);
        
        $this->assertIsArray($translations);
        // 可能返回空数组，因为没有匹配的翻译键
    }

    /**
     * 测试scanFile方法
     */
    public function test_scan_file()
    {
        $tempDir = $this->getTempDirectory();
        $testFile = $tempDir . '/test_scan.php';
        
        File::put($testFile, "<?php echo __('test.scan.message'); ?>");
        
        $translations = $this->collector->scanFile($testFile);
        
        $this->assertIsArray($translations);
    }

    /**
     * 测试getFileExtension方法
     */
    public function test_get_file_extension()
    {
        $extension = $this->collector->getFileExtension('/path/to/file.php');
        $this->assertEquals('php', $extension);
        
        $extension = $this->collector->getFileExtension('/path/to/template.blade.php');
        $this->assertEquals('blade.php', $extension);
        
        $extension = $this->collector->getFileExtension('/path/to/script.js');
        $this->assertEquals('js', $extension);
    }

    /**
     * 测试getRegexPatterns方法
     */
    public function test_get_regex_patterns()
    {
        $patterns = $this->collector->getRegexPatterns('php');
        $this->assertIsArray($patterns);
        
        $patterns = $this->collector->getRegexPatterns('blade.php');
        $this->assertIsArray($patterns);
        
        $patterns = $this->collector->getRegexPatterns('unknown');
        $this->assertIsArray($patterns);
        $this->assertEmpty($patterns);
    }

    /**
     * 测试getContext方法
     */
    public function test_get_context()
    {
        $content = "This is a test content with some text to get context from.";
        $offset = 20; // 位置在"test"附近
        $match = "test";
        
        $context = $this->collector->getContext($content, $offset, $match);
        
        $this->assertIsString($context);
        $this->assertStringContainsString('test', $context);
    }

    /**
     * 测试detectModule方法
     */
    public function test_detect_module()
    {
        // 设置模块路径配置
        $modulesPath = '/app/Modules';
        $this->collector->setConfig(['modules_support' => ['modules_path' => $modulesPath]]);
        
        $module = $this->collector->detectModule('/app/Modules/User/Http/Controllers/UserController.php');
        $this->assertEquals('User', $module);
        
        $module = $this->collector->detectModule('/app/Http/Controllers/HomeController.php');
        $this->assertNull($module);
        
        // 测试子目录模块
        $module = $this->collector->detectModule('/app/Modules/Admin/Services/AdminService.php');
        $this->assertEquals('Admin', $module);
    }

    /**
     * 测试getScanPaths方法
     */
    public function test_get_scan_paths()
    {
        // 直接设置实例的配置，而不是使用Laravel的config()
        $this->collector->setConfig(['scan_paths' => ['app/', 'resources/']]);
        
        $paths = $this->collector->getScanPaths();
        
        $this->assertIsArray($paths);
        $this->assertContains('app/', $paths);
        $this->assertContains('resources/', $paths);
        
        // 测试选项覆盖配置的情况
        $this->collector->setOptions(['paths' => ['custom/path/']]);
        $paths = $this->collector->getScanPaths();
        
        $this->assertIsArray($paths);
        $this->assertContains('custom/path/', $paths);
    }

    /**
     * 测试normalizeTranslations方法
     */
    public function test_normalize_translations()
    {
        $translations = [
            ['key' => 'user.login', 'value' => 'Login'],
            ['key' => 'user.login', 'value' => 'Login Duplicate'],
            ['key' => 'user.logout', 'value' => 'Logout'],
        ];
        
        $normalized = $this->collector->normalizeTranslations($translations);
        
        $this->assertIsArray($normalized);
        $this->assertCount(2, $normalized);
        
        $keys = array_column($normalized, 'key');
        $this->assertContains('user.login', $keys);
        $this->assertContains('user.logout', $keys);
        
        // 验证去重功能
        $loginCount = count(array_filter($normalized, fn($t) => $t['key'] === 'user.login'));
        $this->assertEquals(1, $loginCount);
    }

    /**
     * 测试hasTranslationChanged方法
     */
    public function test_has_translation_changed()
    {
        $collected = [
            'key' => 'user.login',
            'source_file' => '/new/path/file.php',
            'line_number' => 10,
            'context' => 'new context'
        ];
        
        $existing = [
            'key' => 'user.login',
            'source_file' => '/old/path/file.php',
            'line_number' => 5,
            'context' => 'old context'
        ];
        
        $hasChanged = $this->collector->hasTranslationChanged($collected, $existing);
        $this->assertTrue($hasChanged);
        
        // 测试未变更的情况
        $existing['source_file'] = '/new/path/file.php';
        $existing['line_number'] = 10;
        $existing['context'] = 'new context';
        
        $hasChanged = $this->collector->hasTranslationChanged($collected, $existing);
        $this->assertFalse($hasChanged);
    }
}

/**
 * 可测试的TranslationCollectorService类，暴露保护方法
 */
class TestableTranslationCollectorService extends TranslationCollectorService
{
    public function loadJsonTranslations(string $filePath, string $language): array
    {
        return parent::loadJsonTranslations($filePath, $language);
    }

    public function loadPhpTranslations(string $languageDir, string $language): array
    {
        return parent::loadPhpTranslations($languageDir, $language);
    }

    public function flattenTranslations(array $data, string $prefix, string $filePath, string $language): array
    {
        return parent::flattenTranslations($data, $prefix, $filePath, $language);
    }

    public function analyzeTranslationKeyAndValue(string $extractedText): array
    {
        return parent::analyzeTranslationKeyAndValue($extractedText);
    }

    public function isDirectText(string $text): bool
    {
        return parent::isDirectText($text);
    }

    public function resolveTranslationValue(string $key): array
    {
        return parent::resolveTranslationValue($key);
    }

    public function resolveFromPhpFiles(string $key, string $language, string $langPath): ?string
    {
        return parent::resolveFromPhpFiles($key, $language, $langPath);
    }

    public function resolveFromJsonFile(string $key, string $language, string $langPath): ?string
    {
        return parent::resolveFromJsonFile($key, $language, $langPath);
    }

    public function scanDirectory(string $directory): array
    {
        return parent::scanDirectory($directory);
    }

    public function scanFile(string $filePath): array
    {
        return parent::scanFile($filePath);
    }

    public function getFileExtension(string $filePath): string
    {
        return parent::getFileExtension($filePath);
    }

    public function getRegexPatterns(string $fileType): array
    {
        return parent::getRegexPatterns($fileType);
    }

    public function getContext(string $content, int $offset, string $match): string
    {
        return parent::getContext($content, $offset, $match);
    }

    public function detectModule(string $filePath): ?string
    {
        return parent::detectModule($filePath);
    }

    public function getScanPaths(): array
    {
        return parent::getScanPaths();
    }

    public function normalizeTranslations(array $translations): array
    {
        return parent::normalizeTranslations($translations);
    }

    public function hasTranslationChanged(array $collected, array $existing): bool
    {
        return parent::hasTranslationChanged($collected, $existing);
    }

    public function setConfig(array $config): void
    {
        $this->config = array_merge($this->config, $config);
    }

    public function setOptions(array $options): self
    {
        $this->options = array_merge($this->options, $options);
        return $this;
    }
}
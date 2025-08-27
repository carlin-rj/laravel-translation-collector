<?php

namespace Carlin\LaravelTranslationCollector\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Carlin\LaravelTranslationCollector\TranslationCollectorServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    /**
     * 设置测试环境
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 设置测试用的翻译文件目录
        $this->app['config']->set('translation-collector.lang_path', $this->getTempDirectory('lang'));
        $this->app['config']->set('translation-collector.cache.enabled', false);
        $this->app['config']->set('translation-collector.logging.enabled', false);

        // 创建测试目录
        $this->createTestDirectories();
        $this->createTestFiles();
    }

    /**
     * 清理测试环境
     */
    protected function tearDown(): void
    {
        // 如果设置了KEEP_TEST_FILES环境变量，则不清理测试目录
        if (!env('KEEP_TEST_FILES', true)) {
            $this->cleanupTestDirectories();
        }
        parent::tearDown();
    }

    /**
     * 获取包的服务提供者
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            TranslationCollectorServiceProvider::class,
        ];
    }

    /**
     * 获取包的别名
     *
     * @param \Illuminate\Foundation\Application $app
     * @return array
     */
    protected function getPackageAliases($app)
    {
        return [
            'TranslationCollector' => \Carlin\LaravelTranslationCollector\Facades\TranslationCollector::class,
        ];
    }

    /**
     * 定义环境设置
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function defineEnvironment($app)
    {
        $app['config']->set('translation-collector.external_api', [
            'base_url' => 'https://api.test.com',
            'token' => 'test-token',
            'project_id' => 'test-project',
            'timeout' => 30,
            'retry_times' => 3,
            'retry_sleep' => 100,
            'endpoints' => [
                'add_translation' => '/api/translations/add',
                'get_translations' => '/api/translations/list',
                'sync_translations' => '/api/translations/sync',
            ],
        ]);

        $app['config']->set('translation-collector.scan_paths', [
            'app/',
            'Modules/',
        ]);

        $app['config']->set('translation-collector.supported_languages', [
            'en' => 'English',
            'zh_CN' => '简体中文',
            'zh_HK' => '繁体中文',
        ]);
    }

    /**
     * 获取临时目录路径
     *
     * @param string $suffix
     * @return string
     */
    protected function getTempDirectory(string $suffix = ''): string
    {
        // 使用项目test目录下的temp子目录，方便观察和调试
        $tempDir = __DIR__ . '/temp';

        if ($suffix) {
            $tempDir .= '/' . $suffix;
        }

        return $tempDir;
    }

    /**
     * 创建测试目录
     */
    protected function createTestDirectories(): void
    {
        $directories = [
            $this->getTempDirectory(),
            $this->getTempDirectory('lang'),
            $this->getTempDirectory('lang/en'),
            $this->getTempDirectory('lang/zh_CN'),
            $this->getTempDirectory('app'),
            $this->getTempDirectory('Modules'),
            $this->getTempDirectory('Modules/User'),
            $this->getTempDirectory('Modules/User/Http'),
            $this->getTempDirectory('Modules/User/Resources/views'),
        ];

        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }

    /**
     * 创建测试文件
     */
    protected function createTestFiles(): void
    {
        // 创建PHP测试文件
        file_put_contents(
            $this->getTempDirectory('app/TestController.php'),
            $this->getTestPhpContent()
        );

        // 创建Blade测试文件
        file_put_contents(
            $this->getTempDirectory('Modules/User/Resources/views/test.blade.php'),
            $this->getTestBladeContent()
        );

        // 创建翻译文件
        file_put_contents(
            $this->getTempDirectory('lang/en.json'),
            json_encode([
                'user.login.success' => 'Login successful',
                'user.logout' => 'Logout',
                'user.title' => 'title',
                'user.description' => 'description',
				'user.submit' => 'submit',
				'user.cancel' => 'cancel',
				'advanced.blade.key' => 'dddd',
				'advanced.php.key' => 'Advanced PHP key', // 为高级测试添加翻译
				'user.store.success' => 'Store successful', // 为getTestPhpContent中的键添加翻译
            ], JSON_PRETTY_PRINT)
        );

        file_put_contents(
            $this->getTempDirectory('lang/zh_CN.json'),
            json_encode([
				'user.login.success' => 'Login 成功',
				'user.logout' => '退出',
				'user.title' => '标题',
				'user.description' => '描述',
				'user.submit' => '描述1',
				'user.cancel' => '描述2',
				'advanced.blade.key' => 'dd',
				'advanced.php.key' => 'PHP高级键', // 为高级测试添加翻译
				'user.store.success' => '存储成功', // 为getTestPhpContent中的键添加翻译
            ], JSON_PRETTY_PRINT)
        );
    }

    /**
     * 清理测试目录
     */
    protected function cleanupTestDirectories(): void
    {
        $tempDir = $this->getTempDirectory();

        if (is_dir($tempDir)) {
            $this->removeDirectory($tempDir);
        }
    }

    /**
     * 递归删除目录
     *
     * @param string $directory
     */
    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = array_diff(scandir($directory), ['.', '..']);

        foreach ($files as $file) {
            $path = $directory . '/' . $file;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }

    /**
     * 获取测试PHP文件内容
     *
     * @return string
     */
    protected function getTestPhpContent(): string
    {
        return '<?php

namespace App\Http\Controllers;

class TestController extends Controller
{
    public function index()
    {
        $message = __("user.login.success");
        $logout = trans("user.logout");
        
        return response()->json([
            "message" => $message,
            "logout" => $logout,
            "welcome" => $welcome
        ]);
    }
    
    public function store()
    {
        return trans("user.store.success");
    }
}';
    }

    /**
     * 获取测试Blade文件内容
     *
     * @return string
     */
    protected function getTestBladeContent(): string
    {
        return '<div>
    <h1>{{ __("user.title") }}</h1>
    <p>@lang("user.description")</p>
    <button>{{ trans("user.submit") }}</button>
    <span>{{ __("user.cancel") }}</span>
</div>';
    }

    /**
     * 创建模拟的HTTP响应
     *
     * @param array $data
     * @param int $status
     * @return \GuzzleHttp\Psr7\Response
     */
    protected function createMockResponse(array $data = [], int $status = 200): \GuzzleHttp\Psr7\Response
    {
        return new \GuzzleHttp\Psr7\Response($status, [], json_encode($data));
    }

    /**
     * 断言数组包含翻译键
     *
     * @param string $key
     * @param array $translations
     */
    protected function assertTranslationKeyExists(string $key, array $translations): void
    {
        $keys = array_column($translations, 'key');
        $this->assertContains($key, $keys, "翻译键 '{$key}' 不存在于收集结果中");
    }

    /**
     * 断言翻译数据结构正确
     *
     * @param array $translation
     */
    protected function assertValidTranslationStructure(array $translation): void
    {
        $requiredFields = ['key', 'default_text', 'source_file', 'line_number', 'context', 'created_at'];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $translation, "翻译数据缺少必需字段: {$field}");
        }

        $this->assertIsString($translation['key']);
        $this->assertIsString($translation['source_file']);
        $this->assertIsInt($translation['line_number']);
        $this->assertGreaterThan(0, $translation['line_number']);
    }
}

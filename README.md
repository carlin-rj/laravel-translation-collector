# Laravel Translation Collector

Laravel 翻译文本自动收集器扩展包，用于自动收集项目中的翻译文本并与外部翻译系统同步。

## 功能特性

- 🔍 **自动扫描**: 自动扫描项目代码中的翻译函数调用
- 🏗️ **模块化支持**: 完美支持 `nwidart/laravel-modules` 模块化架构
- 🌍 **多语言**: 支持多种语言格式 (JSON、PHP)
- 🔄 **双向同步**: 支持与外部翻译系统的双向同步
- 🚀 **初始化翻译**: 一键将本地翻译文件初始化到外部系统
- 📊 **详细报告**: 生成详细的翻译状态报告
- ⚡ **高性能**: 支持批量处理和缓存机制
- 🛠️ **可配置**: 丰富的配置选项

## 安装

### 1. 安装扩展包

```bash
composer require carlin/laravel-translation-collector
```

### 3. 发布配置文件

```bash
php artisan vendor:publish --tag=translation-collector-config
```

### 4. 配置环境变量

在 `.env` 文件中添加以下配置：

```env
# 外部翻译API配置
TRANSLATION_API_URL=https://api.translation-system.com
TRANSLATION_API_TOKEN=your_api_token
TRANSLATION_PROJECT_ID=your_project_id

# 可选配置
TRANSLATION_AUTO_SYNC=false
TRANSLATION_BATCH_SIZE=100
TRANSLATION_CACHE_ENABLED=true
TRANSLATION_CACHE_TTL=3600
```

## 使用方法

### 命令行工具

#### 1. 收集翻译文本

```bash
# 收集所有翻译文本
php artisan translation:collect

# 指定模块收集
php artisan translation:collect --module=User,Order

# 指定路径收集
php artisan translation:collect --path=app/Http,Modules/Admin

# 仅扫描不上传 (dry-run 模式)
php artisan translation:collect --dry-run

# 自动上传到外部系统
php artisan translation:collect --upload

# 输出为表格格式
php artisan translation:collect --format=table

# 保存结果到文件
php artisan translation:collect --output=storage/translations.json
```

#### 2. 同步翻译

```bash
# 双向同步
php artisan translation:sync

# 仅从外部系统拉取
php artisan translation:sync --direction=pull

# 仅推送到外部系统
php artisan translation:sync --direction=push

# 指定语言同步
php artisan translation:sync --language=zh_CN,en

# 预览同步差异
php artisan translation:sync --dry-run
```

#### 3. 初始化翻译

```bash
# 将本地翻译文件初始化到外部系统（用于首次集成）
php artisan translation:init

# 指定语言初始化
php artisan translation:init --language=en,zh_CN

# 查看将要初始化的内容（干跑模式）
php artisan translation:init --dry-run

# 强制执行（跳过确认）
php artisan translation:init --force

# 指定批量大小
php artisan translation:init --batch-size=50
```

#### 4. 生成报告

```bash
# 生成完整报告
php artisan translation:report

# 生成HTML格式报告
php artisan translation:report --format=html --output=storage/report.html

# 包含统计信息
php artisan translation:report --include-statistics --include-missing --include-unused

# 指定语言报告
php artisan translation:report --language=zh_CN,en
```

### 程序化使用

#### 使用门面

```php
use Carlin\LaravelTranslationCollector\Facades\TranslationCollector;

// 收集翻译
$translations = TranslationCollector::collect();

// 扫描指定模块
$translations = TranslationCollector::scanModules(['User', 'Order']);

// 扫描指定路径
$translations = TranslationCollector::scanPaths(['app/Http', 'Modules/Admin']);

// 获取统计信息
$statistics = TranslationCollector::getStatistics();
```

#### 使用依赖注入

```php
use Carlin\LaravelTranslationCollector\Contracts\TranslationCollectorInterface;
use Carlin\LaravelTranslationCollector\Contracts\ExternalApiClientInterface;

class TranslationService
{
    public function __construct(
        private TranslationCollectorInterface $collector,
        private ExternalApiClientInterface $apiClient
    ) {}

    public function collectAndUpload()
    {
        $translations = $this->collector->collect();
        
        if (!empty($translations)) {
            $result = $this->apiClient->addTranslations($translations);
            return $result;
        }
    }
}
```

## 配置说明

### 扫描配置

```php
// config/translation-collector.php

'scan_paths' => [
    'app/',
    'Modules/',
    'resources/views/',
    'resources/js/',
],

'exclude_paths' => [
    'vendor/',
    'storage/',
    'bootstrap/cache/',
    'public/',
    'node_modules/',
],

'translation_functions' => [
    '__',           // Laravel 标准翻译函数
    'trans',        // Laravel trans函数
    'Lang::get',    // Lang门面调用
    '@lang',        // Blade模板指令
    'trans_choice', // 复数翻译
],
```

### 外部API配置

```php
'external_api' => [
    'base_url' => env('TRANSLATION_API_URL'),
    'token' => env('TRANSLATION_API_TOKEN'),
    'project_id' => env('TRANSLATION_PROJECT_ID'),
    'timeout' => 30,
    'retry_times' => 3,
    'retry_sleep' => 100,
    
    'endpoints' => [
        'add_translation' => '/api/translations/add',
        'get_translations' => '/api/translations/get_translations',
        'sync_translations' => '/api/translations/sync',
        'init_translations' => '/api/translations/init',
    ],
],
```

### 模块化支持

```php
'modules_support' => [
    'enabled' => true,
    'modules_path' => base_path('Modules'),
    'scan_module_paths' => [
        'Http/',
        'Resources/views/',
        'Console/',
        'Services/',
    ],
],
```

## 支持的翻译函数

扩展包能够识别以下翻译函数调用：

### PHP 文件
- `__('key')`
- `trans('key')`
- `Lang::get('key')`
- `trans_choice('key', count)`

### Blade 模板
- `@lang('key')`
- `{{ __('key') }}`
- `{{ trans('key') }}`

### JavaScript 文件
- `__('key')`
- `trans('key')`

## 输出格式

### JSON 格式
```json
[
    {
        "key": "user.login.success",
        "default_text": "Login successful",
        "source_file": "/path/to/file.php",
        "line_number": 45,
        "context": "return response()->json(['message' => __('user.login.success')]);",
        "module": "User",
        "file_type": "php",
        "created_at": "2025-08-27T10:00:00Z"
    }
]
```

### 报告格式
```json
{
    "summary": {
        "total_collected_keys": 150,
        "total_local_translations": 450,
        "supported_languages": ["en", "zh_CN", "zh_HK"]
    },
    "language_coverage": {
        "zh_CN": {
            "language_name": "简体中文",
            "coverage_percent": 85.5,
            "status": "good"
        }
    }
}
```

## 错误处理

扩展包提供了详细的错误处理和日志记录：

```php
try {
    $translations = TranslationCollector::collect();
} catch (\Carlin\LaravelTranslationCollector\Exceptions\TranslationCollectorException $e) {
    // 处理收集错误
    Log::error('翻译收集失败', ['error' => $e->getMessage()]);
} catch (\Carlin\LaravelTranslationCollector\Exceptions\ExternalApiException $e) {
    // 处理API错误
    Log::error('外部API调用失败', ['error' => $e->getMessage()]);
}
```

## 性能优化

- **缓存机制**: 自动缓存扫描结果
- **增量扫描**: 只扫描变更的文件
- **批量处理**: 支持批量上传到外部系统
- **并行处理**: 多进程并行扫描（可配置）

## 贡献指南

欢迎提交 Issue 和 Pull Request 来改进这个扩展包。

## 许可证

MIT License

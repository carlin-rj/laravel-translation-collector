<?php

/**
 * 初始化翻译功能演示脚本
 * 
 * 这个脚本演示了如何使用新的translation:init命令
 * 将本地翻译文件初始化到外部翻译系统
 */

require_once __DIR__ . '/vendor/autoload.php';

echo "🚀 Laravel 翻译收集器 - 初始化翻译功能演示\n";
echo "=" . str_repeat("=", 50) . "\n\n";

echo "📋 功能说明:\n";
echo "  - 扫描本地翻译文件（JSON 和 PHP 格式）\n";
echo "  - 将翻译内容格式化为标准API格式\n";
echo "  - 批量上传到外部翻译系统\n";
echo "  - 支持多语言和混合文件格式\n\n";

echo "🛠️ 使用方法:\n";
echo "1. 初始化所有支持的语言:\n";
echo "   php artisan translation:init\n\n";

echo "2. 初始化指定语言:\n";
echo "   php artisan translation:init --language=en,zh_CN\n\n";

echo "3. 干跑模式（查看将要初始化的内容）:\n";
echo "   php artisan translation:init --dry-run\n\n";

echo "4. 强制执行（跳过确认）:\n";
echo "   php artisan translation:init --force\n\n";

echo "5. 指定批量大小:\n";
echo "   php artisan translation:init --batch-size=50\n\n";

echo "📊 支持的文件格式:\n";
echo "  - JSON格式: resources/lang/{locale}.json\n";
echo "  - PHP格式: resources/lang/{locale}/*.php\n\n";

echo "🔧 配置说明:\n";
echo "需要在 config/translation-collector.php 中配置:\n";
echo "  - external_api.base_url: 外部API基础URL\n";
echo "  - external_api.token: API认证令牌\n";
echo "  - external_api.project_id: 项目ID\n";
echo "  - supported_languages: 支持的语言列表\n\n";

echo "📂 本地翻译文件示例:\n";
echo "resources/lang/en.json:\n";
echo "{\n";
echo "  \"welcome\": \"Welcome\",\n";
echo "  \"goodbye\": \"Goodbye\"\n";
echo "}\n\n";

echo "resources/lang/en/auth.php:\n";
echo "<?php\n";
echo "return [\n";
echo "  'login' => 'Login',\n";
echo "  'logout' => 'Logout',\n";
echo "  'password' => [\n";
echo "    'required' => 'Password is required',\n";
echo "    'min' => 'Password must be at least 8 characters'\n";
echo "  ]\n";
echo "];\n\n";

echo "🌐 API请求格式:\n";
echo "POST /api/translations/init\n";
echo "{\n";
echo "  \"project_id\": \"your-project-id\",\n";
echo "  \"translations\": [\n";
echo "    {\n";
echo "      \"key\": \"welcome\",\n";
echo "      \"default_text\": \"Welcome\",\n";
echo "      \"value\": \"Welcome\",\n";
echo "      \"language\": \"en\",\n";
echo "      \"module\": \"\",\n";
echo "      \"metadata\": {\n";
echo "        \"file_type\": \"json\",\n";
echo "        \"created_at\": \"2025-08-27T10:00:00Z\"\n";
echo "      }\n";
echo "    }\n";
echo "  ]\n";
echo "}\n\n";

echo "✅ 特点:\n";
echo "  ✓ 自动检测和扫描本地翻译文件\n";
echo "  ✓ 支持JSON和PHP混合格式\n";
echo "  ✓ 批量处理和上传\n";
echo "  ✓ 详细的统计信息显示\n";
echo "  ✓ 干跑模式预览\n";
echo "  ✓ 错误处理和重试机制\n";
echo "  ✓ 完整的单元测试覆盖\n\n";

echo "🎯 使用场景:\n";
echo "  - 项目首次集成外部翻译系统\n";
echo "  - 将现有翻译数据迁移到新系统\n";
echo "  - 批量同步本地翻译到远程系统\n";
echo "  - 备份和恢复翻译数据\n\n";

echo "=" . str_repeat("=", 50) . "\n";
echo "📚 更多信息请查看 README.md 文档\n";
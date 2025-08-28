# 外部翻译系统API规范

## 1. 通用规范

### 请求头
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

### 通用响应格式
```json
{
  "success": true|false,
  "message": "success message or error message",
  "data": {}, // 具体数据
  "code": 200, // HTTP状态码
  "timestamp": "2025-08-27T10:00:00Z"
}
```

## 2. 具体API接口

### 2.1 健康检查 GET /api/health
**请求**: 无参数
**响应**:
```json
{
  "success": true,
  "message": "Service is healthy",
  "data": {
    "status": "ok",
    "version": "1.0.0"
  }
}
```

### 2.2 采集翻译 POST /api/translations/add
**请求**:
```json
{
  "project_id": "project_123",
  "languages": ["en", "zh_CN"],
  "translations": [
    {
      "key": "user.login",
      "default_text": "Login",
      "module": "User",
      "metadata": {
        "file_type": "php",
        "created_at": "2025-08-27T10:00:00Z"
      }
    }
  ]
}
```
**响应**:
```json
{
  "success": true,
  "message": "Translations added successfully",
  "data": {
    "added_count": 1,
    "skipped_count": 0
  }
}
```

### 2.3 获取翻译列表 GET /api/translations/list
**请求参数**:
```
project_id: string (required)
language: string (optional)
page: int (optional, default: 1)
per_page: int (optional, default: 100)
```
**响应**:
```json
{
  "success": true,
  "message": "Translations retrieved successfully",
  "data": [
    {
      "key": "user.login",
      "default_text": "Login",
      "value": "login",
      "module": "User",
      "language": "en",
      "file_type": "php",
      "updated_at": "2025-08-27T10:00:00Z"
    },
    {
      "key": "welcome",
      "default_text": "Welcome",
      "value": "Welcome",
      "module": "User",
      "language": "zh_CN", 
      "file_type": "json",
      "updated_at": "2025-08-27T10:00:00Z"
    }
  ]
}
```

## 3. 错误响应格式

```json
{
  "success": false,
  "message": "Error description",
  "data": null,
  "error": {
    "code": "VALIDATION_ERROR",
    "details": {
      "field": "error description"
    }
  }
}
```

## 4. 混合格式处理要求

1. **file_type 字段**: 每个翻译记录必须包含 `file_type` 字段，值为 `"json"` 或 `"php"`
2. **响应格式**: 外部系统返回的翻译数据中必须包含 `file_type` 字段
3. **分组处理**: 客户端需要根据 `file_type` 对翻译进行分组处理

## 5. 认证和安全

1. **Token认证**: 所有请求必须包含有效的Bearer token
2. **项目隔离**: 所有请求必须包含 `project_id` 参数
3. **HTTPS**: 生产环境必须使用HTTPS协议

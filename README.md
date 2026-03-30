# Kode\Http

## 现代化、高性能的 PHP HTTP 服务端库

[![PHP Version](https://img.shields.io/badge/PHP-%5E8.1-blue)](https://www.php.net/)
[![PSR-7/15/17](https://img.shields.io/badge/PSR-7%2F15%2F17-brightgreen)](https://www.php-fig.org/)
[![License](https://img.shields.io/badge/License-Apache--2.0-orange)](LICENSE)

> **Kode\Http** 是一个专为 PHP 8.1+ 设计的高性能 HTTP 服务端库，完全兼容 PSR-7/PSR-15/PSR-17 标准。支持 Swoole、Workerman 等协程环境，支持**分布式部署**，深度集成 `kode/process`、`kode/fibers`、`kode/parallel`，打造现代化全栈 PHP 应用。
>
> **设计理念**：借鉴 ThinkPHP/Laravel/webman 的简洁风格，提供 `Req`、`Res`、`App` 三大核心 API，让开发者无需心智负担即可快速构建高性能 HTTP 服务。

## 核心特性

- **📦 简洁 API**：借鉴 webman/ThinkPHP/Laravel 设计，`Req`、`Res`、`App` 三剑客
- **🎯 PSR-7/15/17 完全兼容**：标准化的 HTTP 消息、中间件和工厂实现
- **⚡ 高性能协程支持**：无缝对接 Swoole/Workerman，支持 Fiber 协程
- **🔄 多运行时适配**：自动检测并适配 FPM、CLI、Swoole、Workerman 环境
- **🌐 分布式部署支持**：支持跨机器 Worker、Fiber、并行任务分发
- **🧩 模块化中间件**：灵活的中间件管道，支持链式调用
- **🔗 深度集成**：与 `kode/context`、`kode/process`、`kode/fibers`、`kode/parallel` 无缝协作
- **🛡️ 企业级特性**：CORS、限流、错误处理、进程管理等开箱即用

## 环境要求

| 环境 | 版本要求 |
|------|----------|
| PHP | >= 8.1 |
| PSR-7 | ^1.0 或 ^2.0 |
| PSR-15 | ^1.0 |
| PSR-17 | ^1.0 |

### 可选扩展

| 扩展 | 说明 |
|------|------|
| `ext-swoole` | Swoole 协程支持和异步 HTTP 服务器 |
| `ext-fiber` | PHP Fiber 协程支持 |
| `workerman/workerman` | Workerman 多进程支持 |

## 快速开始

### 安装

```bash
composer require kode/http
```

### 最简示例

```php
<?php

require 'vendor/autoload.php';

use Kode\Http\App;
use Kode\Http\Req;
use Kode\Http\Res;

$app = App::create();

$app->get('/api/hello', function() {
    $name = Req::get('name', 'World');
    return Res::success(['greeting' => "你好，{$name}！"]);
});

$app->serve(8080);
```

## 核心 API

### Req - 请求解析（借鉴 webman）

**无需传入 request 参数，直接获取当前请求**

```php
// 参数获取（自动从当前请求获取）
Req::get('name');           // GET 参数
Req::post('name');          // POST 参数
Req::json('name');          // JSON body 参数
Req::header('Authorization'); // 请求头
Req::cookie('session_id');  // Cookie

// 字段选择（借鉴 Laravel）
Req::only('name', 'email');           // 仅获取指定字段
Req::except('password', 'token');     // 排除指定字段

// 判断存在（借鉴 ThinkPHP）
Req::has('name');            // 参数是否存在
Req::missing('token');       // 参数是否缺失

// 获取所有参数
Req::all();                  // 合并 query + body

// 请求信息
Req::ip();                   // 客户端 IP
Req::method();              // 请求方法
Req::path();                // 请求路径
Req::isAjax();              // 是否 AJAX 请求
Req::isJson();              // 是否 JSON 请求
Req::isMobile();            // 是否移动端
Req::isGet();               // 是否 GET 请求
Req::isPost();              // 是否 POST 请求

// 其他
Req::userAgent();           // User-Agent
Req::referer();             // 来源页面
Req::language();            // Accept-Language
Req::time();                // 请求时间戳
Req::file('avatar');        // 上传文件
Req::server('REQUEST_TIME'); // 服务器变量
```

### Res - 响应构建（链式调用）

```php
// JSON 响应
Res::json(['data' => 'value']);
Res::json(['data' => 'value'], 1);  // 带业务码

// 业务响应（借鉴 Laravel）
Res::success(['id' => 1], '操作成功');
Res::fail('用户名或密码错误', 'E1001');

// HTTP 错误
Res::error(404, 'Not Found');
Res::error(500, 'Internal Server Error', 'E1500');

// 其他响应类型
Res::text('Hello World');
Res::html('<h1>Title</h1>');
Res::xml('<root></root>');
Res::empty();                // 204 空响应
Res::redirect('/login');    // 302 重定向
Res::download('/path/file.pdf');

// 链式调用
Res::success(['data' => $data])
    ->status(201)
    ->header('X-Custom', 'value')
    ->withCors()
    ->withCache(3600)
    ->withSecurity()
    ->send();
```

### App - 应用构建器

```php
use Kode\Http\App;
use Kode\Http\Req;
use Kode\Http\Res;

$app = App::create(debug: true);

// 添加中间件
$app->use(function($req, $next) {
    $start = microtime(true);
    $response = $next->handle($req);
    return $response->withHeader('X-Execution-Time', sprintf('%.2fms', (microtime(true) - $start) * 1000));
});

// 路由注册
$app->get('/api/users', function() {
    return Res::success(['users' => [
        ['id' => 1, 'name' => '张三'],
        ['id' => 2, 'name' => '李四'],
    ]]);
});

$app->post('/api/users', function() {
    $name = Req::json('name');
    $email = Req::json('email');

    if (empty($name)) {
        return Res::fail('用户名不能为空', 'E1001', 400);
    }

    return Res::success(['id' => rand(1000, 9999)], '创建成功');
});

// 路由参数
$app->get('/api/users/{id}', function() {
    $id = Req::attr('id');
    return Res::success(['id' => $id]);
});

$app->delete('/api/users/{id}', function() {
    return Res::success(null, '删除成功');
});

// 路由组
$app->group('/api/v1', function($api) {
    $api->get('/status', fn() => Res::success(['status' => 'ok']));
    $api->post('/action', fn() => Res::success());
});

// HTTP 方法
$app->patch('/api/users/{id}', fn() => Res::success());
$app->options('/api/users', fn() => Res::empty());
$app->any('/api/health', fn() => Res::success());

// 运行
$app->serve(8080);
```

## PSR-7 消息实现

| 类 | 说明 |
|----|------|
| `Request` | HTTP 请求消息，包含方法、URI、头部、协议版本 |
| `Response` | HTTP 响应消息，包含状态码、原因短语、头部、正文 |
| `ServerRequest` | 服务端请求，继承 Request 并添加服务端特性 |
| `Stream` | 流式正文，支持读取、写入、定位等操作（自研实现） |
| `Uri` | URI 实现，支持解析和构建 URI 各部分 |

## PSR-15 中间件

| 中间件 | 说明 |
|--------|------|
| `MiddlewareDispatcher` | 核心中间件调度器，管理中间件栈并执行调度 |
| `MiddlewarePipeline` | 管道实现，支持链式中间件调用 |
| `CallableMiddleware` | 将可调用对象转换为中间件 |
| `CorsMiddleware` | CORS 跨域处理 |
| `RateLimitMiddleware` | 请求限流 |
| `JsonErrorHandlerMiddleware` | JSON 错误处理 |

## 集成组件

| 组件 | 说明 |
|------|------|
| `ProcessWorkerMiddleware` | 进程工作单元，集成 `kode/process`，支持分布式 |
| `FiberCoroutineMiddleware` | Fiber 协程，集成 `kode/fibers`，支持分布式 |
| `ParallelMiddleware` | 并行处理，集成 `kode/parallel`，支持分布式 |

## 分布式部署

### 概述

Kode\Http 支持分布式部署场景，可以通过简单的配置启用分布式模式：

```php
use Kode\Http\Integration\DistributedConfig;
use Kode\Http\Integration\ProcessWorkerMiddleware;
use Kode\Http\Integration\FiberCoroutineMiddleware;
use Kode\Http\Integration\ParallelMiddleware;
```

### 分布式配置

```php
$config = new DistributedConfig('node-1');
$config->setEnabled(true);
$config->setNodes([
    'node-1' => ['host' => '192.168.1.1', 'port' => 8080, 'weight' => 1],
    'node-2' => ['host' => '192.168.1.2', 'port' => 8080, 'weight' => 1],
]);
$config->setLoadBalanceStrategy('round_robin');
$config->setCallTimeout(30.0);
$config->setMaxRetries(3);
```

### 分布式 Worker（kode/process 集成）

```php
$worker = new ProcessWorkerMiddleware(0, true, [
    'pool_size' => 4,
    'enable_stats' => true,
    'distributed' => [
        'enabled' => true,
        'node_id' => 'worker-1',
        'nodes' => [
            'worker-1' => ['host' => '192.168.1.1', 'port' => 8080],
            'worker-2' => ['host' => '192.168.1.2', 'port' => 8080],
        ],
    ],
]);

$app->use($worker);
```

### 分布式 Fiber 协程（kode/fibers 集成）

```php
$fiber = new FiberCoroutineMiddleware(10, 2048, [
    'timeout' => 30,
    'distributed' => [
        'enabled' => true,
        'node_id' => 'fiber-1',
        'nodes' => [
            'fiber-1' => ['host' => '192.168.1.1', 'port' => 8081],
            'fiber-2' => ['host' => '192.168.1.2', 'port' => 8081],
        ],
    ],
]);

$app->use($fiber);
```

### 分布式并行处理（kode/parallel 集成）

```php
$parallel = new ParallelMiddleware(10, [
    'distributed' => [
        'enabled' => true,
        'node_id' => 'parallel-1',
        'nodes' => [
            'parallel-1' => ['host' => '192.168.1.1', 'port' => 8082],
            'parallel-2' => ['host' => '192.168.1.2', 'port' => 8082],
        ],
        'load_balance_strategy' => 'least_load',
    ],
]);

$app->use($parallel);
```

## 项目结构

```
src/
├── Psr7/                          # PSR-7 实现
│   ├── Message/                   # 消息类
│   ├── Factory/                   # PSR-17 工厂
│   └── Trait/                     # 可复用 Trait
├── Middleware/                    # PSR-15 中间件
│   ├── MiddlewareInterface.php
│   ├── MiddlewareDispatcher.php
│   ├── MiddlewarePipeline.php
│   ├── CallableMiddleware.php
│   ├── CorsMiddleware.php
│   ├── RateLimitMiddleware.php
│   └── JsonErrorHandlerMiddleware.php
├── Integration/                   # 集成组件
│   ├── DistributedConfig.php
│   ├── ProcessWorkerMiddleware.php
│   ├── FiberCoroutineMiddleware.php
│   └── ParallelMiddleware.php
├── Server/                       # 服务端适配器
├── Exception/                     # 异常
├── App.php                       # 应用构建器
├── Req.php                       # 请求助手
├── Res.php                       # 响应助手
├── Kode.php                      # 框架入口
└── functions.php                 # 辅助函数
```

## 测试

```bash
./vendor/bin/phpunit
./vendor/bin/phpunit --coverage-html coverage
```

## 与其他 Kode 包的关系

```
kode/http
    │
    ├── kode/context     # 请求上下文传递和管理
    │
    ├── kode/runtime     # 协程运行时抽象
    │
    ├── kode/fibers      # Fiber 协程调度
    │       │
    │       └── kode/parallel  # 并行任务处理
    │
    └── kode/process     # 进程管理和 Worker
            │
            └── kode/http-client  # HTTP 客户端（统一到 PSR-7 抽象）
```

## 版本历史

- **v2.1.0** - 增强 App 应用构建器，支持路由参数提取
- **v2.0.0** - 借鉴 ThinkPHP/Laravel/webman 重构 API
- **v1.5.0** - 增强 Req 请求助手方法
- **v1.4.0** - 新增 App、Req、Res 统一 API
- **v1.3.0** - 适配 kode/exception ^2.0
- **v1.0.0** - 初始版本，PSR-7/15/17 基础实现

## License

Apache-2.0

# Kode\Http

## 现代化、高性能的 PHP HTTP 服务端库

[![PHP Version](https://img.shields.io/badge/PHP-%5E8.1-blue)](https://www.php.net/)
[![PSR-7/15/17](https://img.shields.io/badge/PSR-7%2F15%2F17-brightgreen)](https://www.php-fig.org/)
[![License](https://img.shields.io/badge/License-Apache--2.0-orange)](LICENSE)

> **Kode\Http** 是一个专为 PHP 8.1+ 设计的高性能 HTTP 服务端库，完全兼容 PSR-7/PSR-15/PSR-17 标准。支持 Swoole、Workerman 等协程环境，支持**分布式部署**，深度集成 `kode/process`、`kode/fibers`、`kode/parallel`，打造现代化全栈 PHP 应用。

## 核心特性

- **🎯 PSR-7/15/17 完全兼容**：标准化的 HTTP 消息、中间件和工厂实现
- **⚡ 高性能协程支持**：无缝对接 Swoole/Workerman，支持 Fiber 协程
- **🔄 多运行时适配**：自动检测并适配 FPM、CLI、Swoole、Workerman 环境
- **🌐 分布式部署支持**：支持跨机器 Worker、Fiber、并行任务分发
- **🧩 模块化中间件**：灵活的中间件管道，支持链式调用
- **🔗 深度集成**：与 `kode/context`、`kode/process`、`kode/fibers`、`kode/parallel` 无缝协作
- **🛡️ 企业级特性**：CORS、限流、错误处理、进程管理等开箱即用
- **📦 简洁 API**：统一的 `Req`、`Res`、`App` 助手类，让开发更高效

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

// 创建应用
$app = App::create();

// 注册路由
$app->get('/api/hello', function($request) {
    $name = Req::query($request, 'name', 'World');
    return Res::success(['greeting' => "你好，{$name}！"]);
});

// 启动开发服务器
$app->serve(8080);
```

## 核心组件

### 统一请求响应助手

#### Req - 请求解析

```php
use Kode\Http\Req;

// 获取查询参数
$params = Req::query($request);           // 获取所有
$name = Req::query($request, 'name');     // 获取单个

// 获取请求体参数
$body = Req::body($request);
$id = Req::body($request, 'id');

// 获取 JSON 数据
$json = Req::json($request);
$email = Req::json($request, 'email');

// 获取请求头
$token = Req::header($request, 'Authorization');

// 获取请求属性
$userId = Req::attr($request, 'user_id');

// 获取所有参数（合并 query + body）
$all = Req::params($request);

// 便捷判断
$isAjax = Req::isAjax($request);
$isJson = Req::isJson($request);
$ip = Req::ip($request);
```

#### Res - 响应构建

```php
use Kode\Http\Res;

// JSON 响应
Res::json(['data' => 'value'])->send();
Res::json(['data' => 'value'], 1)->send(); // 带业务码

// 成功响应
Res::success(['id' => 1], '操作成功')->send($request);
Res::success(['user' => ['name' => '张三']])->send($request);

// 失败响应
Res::fail('用户名或密码错误', 'E1001')->send($request);

// 错误响应
Res::error(404, 'Not Found')->send($request);
Res::error(500, 'Internal Server Error', 'E1500')->send($request);

// 文本/HTML 响应
Res::text('Hello World')->send();
Res::html('<h1>Title</h1>')->send();

// 重定向
Res::redirect('/login')->send();

// 链式调用
Res::success(['data' => 'value'])
    ->withHeader('Cache-Control', 'no-cache')
    ->withCache(3600)
    ->withSecurity()
    ->send($request);
```

### App - 应用构建器

```php
use Kode\Http\App;
use Kode\Http\Req;
use Kode\Http\Res;

// 创建应用
$app = App::create(debug: true);

// 添加中间件
$app->use(function($req, $next) {
    $start = microtime(true);
    $response = $next->handle($req);
    return $response->withHeader('X-Execution-Time', sprintf('%.2fms', (microtime(true) - $start) * 1000));
});

// 注册路由
$app->get('/api/users', function($req) {
    return Res::success(['users' => [
        ['id' => 1, 'name' => '张三'],
        ['id' => 2, 'name' => '李四'],
    ]]);
});

$app->post('/api/users', function($req) {
    $name = Req::json($req, 'name');
    $email = Req::json($req, 'email');

    if (empty($name)) {
        return Res::fail('用户名不能为空', 'E1001', 400);
    }

    return Res::success(['id' => rand(1000, 9999)], '创建成功');
});

$app->get('/api/users/{id}', function($req) {
    return Res::success(['id' => 1, 'name' => '张三']);
});

$app->delete('/api/users/{id}', function($req) {
    return Res::success(null, '删除成功');
});

// 路由组
$app->group('/api/v1', function($api) {
    $api->get('/status', fn() => Res::success(['status' => 'ok']));
    $api->post('/action', fn() => Res::success());
});

// 运行
$app->serve(8080);
```

### PSR-7 消息实现

| 类 | 说明 |
|----|------|
| `Request` | HTTP 请求消息，包含方法、URI、头部、协议版本 |
| `Response` | HTTP 响应消息，包含状态码、原因短语、头部、正文 |
| `ServerRequest` | 服务端请求，继承 Request 并添加服务端特性 |
| `Stream` | 流式正文，支持读取、写入、定位等操作（自研实现） |
| `Uri` | URI 实现，支持解析和构建 URI 各部分 |

### PSR-15 中间件

| 中间件 | 说明 |
|--------|------|
| `MiddlewareDispatcher` | 核心中间件调度器，管理中间件栈并执行调度 |
| `MiddlewarePipeline` | 管道实现，支持链式中间件调用 |
| `CallableMiddleware` | 将可调用对象转换为中间件 |
| `CorsMiddleware` | CORS 跨域处理 |
| `RateLimitMiddleware` | 请求限流 |
| `JsonErrorHandlerMiddleware` | JSON 错误处理 |

### 集成组件

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
// 创建分布式配置
$config = new DistributedConfig('node-1');
$config->setEnabled(true);
$config->setNodes([
    'node-1' => ['host' => '192.168.1.1', 'port' => 8080, 'weight' => 1],
    'node-2' => ['host' => '192.168.1.2', 'port' => 8080, 'weight' => 1],
]);
$config->setLoadBalanceStrategy('round_robin'); // round_robin, least_load, random
$config->setCallTimeout(30.0);
$config->setMaxRetries(3);
```

### 分布式 Worker（kode/process 集成）

```php
use Kode\Http\Integration\ProcessWorkerMiddleware;

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
        'load_balance_strategy' => 'round_robin',
        'call_timeout' => 30,
        'max_retries' => 3,
    ],
]);

$app->use($worker);
```

### 分布式 Fiber 协程（kode/fibers 集成）

```php
use Kode\Http\Integration\FiberCoroutineMiddleware;

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
use Kode\Http\Integration\ParallelMiddleware;

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
│   └── Trait/                    # 可复用 Trait
├── Middleware/                    # PSR-15 中间件
│   ├── MiddlewareInterface.php
│   ├── MiddlewareDispatcher.php
│   ├── MiddlewarePipeline.php
│   ├── CallableMiddleware.php
│   ├── CorsMiddleware.php
│   ├── RateLimitMiddleware.php
│   └── JsonErrorHandlerMiddleware.php
├── Integration/                   # 集成组件
│   ├── DistributedConfig.php       # 分布式配置
│   ├── ProcessWorkerMiddleware.php
│   ├── FiberCoroutineMiddleware.php
│   └── ParallelMiddleware.php
├── Server/                       # 服务端适配器
├── Exception/                    # 异常
├── App.php                       # 应用构建器
├── Req.php                       # 请求助手
├── Res.php                       # 响应助手
├── Kode.php                      # 框架入口
└── functions.php                 # 辅助函数
```

## 测试

```bash
# 运行所有测试
./vendor/bin/phpunit

# 运行测试并生成覆盖率报告
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

- **v1.3.0** - 新增 App、Req、Res 统一 API，简化请求响应调用
- **v1.2.0** - 适配 kode/exception ^2.0
- **v1.1.0** - 深度集成 kode/process、kode/fibers、kode/parallel，新增分布式部署支持
- **v1.0.0** - 初始版本，PSR-7/15/17 基础实现

## License

Apache-2.0

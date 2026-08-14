# Kode\Http

## 现代化、高性能的 PHP HTTP 服务端库

[![PHP Version](https://img.shields.io/badge/PHP-%5E8.3-blue)](https://www.php.net/)
[![PSR-7/15/17](https://img.shields.io/badge/PSR-7%2F15%2F17-brightgreen)](https://www.php-fig.org/)
[![License](https://img.shields.io/badge/License-Apache--2.0-orange)](LICENSE)

> **Kode\Http** 是一个专为 PHP 8.3+ 设计的高性能 HTTP 服务端库，完全兼容 PSR-7/PSR-15/PSR-17 标准。支持 Swoole、Workerman 等协程环境，支持**分布式部署**，深度集成 `kode/context`、`kode/exception`、`kode/fibers`、`kode/parallel`、`kode/process`，打造现代化全栈 PHP 应用。
>
> **设计理念**：借鉴 ThinkPHP/Laravel/webman 的简洁风格，提供 `Request`、`Response`、`App` 三大核心 API，让开发者无需心智负担即可快速构建高性能 HTTP 服务。

## 核心特性

- **📦 简洁 API**：`Request`、`Response`、`App` 三剑客
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
| PHP | >= 8.3 |
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
use Kode\Http\Request;
use Kode\Http\Response;

$app = App::create();

$app->get('/api/hello', function() {
    $name = Request::get('name', 'World');
    return Response::success(['greeting' => "你好，{$name}！"]);
});

$app->serve(8080);
```

## 核心 API

### Request - 请求解析（借鉴 webman）

**无需传入 request 参数，直接获取当前请求**

```php
// 参数获取（自动从当前请求获取）
Request::get('name');           // GET 参数
Request::post('name');          // POST 参数
Request::json('name');          // JSON body 参数
Request::header('Authorization'); // 请求头
Request::cookie('session_id');  // Cookie

// 字段选择（借鉴 Laravel）
Request::only('name', 'email');           // 仅获取指定字段
Request::except('password', 'token');     // 排除指定字段

// 判断存在（借鉴 ThinkPHP）
Request::has('name');            // 参数是否存在
Request::missing('token');       // 参数是否缺失

// 获取所有参数
Request::all();                  // 合并 query + body

// 请求信息
Request::ip();                   // 客户端 IP
Request::method();              // 请求方法
Request::path();                // 请求路径
Request::isAjax();              // 是否 AJAX 请求
Request::isJson();              // 是否 JSON 请求
Request::isMobile();            // 是否移动端
Request::isGet();               // 是否 GET 请求
Request::isPost();              // 是否 POST 请求

// 其他
Request::userAgent();           // User-Agent
Request::referer();             // 来源页面
Request::language();            // Accept-Language
Request::time();                // 请求时间戳
Request::file('avatar');        // 上传文件
Request::server('REQUEST_TIME'); // 服务器变量
```

### Response - 响应构建（链式调用，且本身是真实 PSR-7）

> `Kode\Http\Response` 自 v3.3 起**直接继承**真实 PSR-7 实现，工厂方法与辅助方法合二为一：
> `Response::json()` / `error()` / `success()` / `fail()` 返回的**就是真实 PSR-7 响应**，
> 因此中间件/处理器里可**直接 `return`**，无需再调用 `->send()`（`->send()` 保留为向后兼容的空操作）。

```php
// JSON 响应（直接 return，无需 ->send()）
return Response::json(['data' => 'value']);
return Response::json(['data' => 'value'], 1);  // 带业务码

// 业务响应（借鉴 Laravel）
return Response::success(['id' => 1], '操作成功');
return Response::fail('用户名或密码错误', 'E1001');

// HTTP 错误
return Response::error(404, 'Not Found');
return Response::error(500, 'Internal Server Error', 'E1500');

// 其他响应类型
return Response::text('Hello World');
return Response::html('<h1>Title</h1>');
return Response::xml('<root></root>');
return Response::empty();                // 204 空响应
return Response::redirect('/login');    // 302 重定向
return Response::download('/path/file.pdf');

// 链式调用（cookie / CORS / 安全头等都是 PSR-7 上的方法）
return Response::success(['data' => $data])
    ->status(201)
    ->header('X-Custom', 'value')
    ->withCors()
    ->withCache(3600)
    ->withSecurity()
    ->cookie('token', $jwt, httpOnly: true);
```

### App - 应用构建器

```php
use Kode\Http\App;
use Kode\Http\Request;
use Kode\Http\Response;

$app = App::create(debug: true);

// 添加中间件
$app->use(function($req, $next) {
    $start = microtime(true);
    $response = $next->handle($req);
    return $response->withHeader('X-Execution-Time', sprintf('%.2fms', (microtime(true) - $start) * 1000));
});

// 路由注册
$app->get('/api/users', function() {
    return Response::success(['users' => [
        ['id' => 1, 'name' => '张三'],
        ['id' => 2, 'name' => '李四'],
    ]]);
});

$app->post('/api/users', function() {
    $name = Request::json('name');
    $email = Request::json('email');

    if (empty($name)) {
        return Response::fail('用户名不能为空', 'E1001', 400);
    }

    return Response::success(['id' => rand(1000, 9999)], '创建成功');
});

// 路由参数
$app->get('/api/users/{id}', function() {
    $id = Request::param('id');   // 路由参数（等价 Request::attr('id')）
    return Response::success(['id' => $id]);
});

$app->delete('/api/users/{id}', function() {
    return Response::success(null, '删除成功');
});

// 路由组
$app->group('/api/v1', function($api) {
    $api->get('/status', fn() => Response::success(['status' => 'ok']));
    $api->post('/action', fn() => Response::success());
});

// HTTP 方法
$app->patch('/api/users/{id}', fn() => Response::success());
$app->options('/api/users', fn() => Response::empty());
$app->any('/api/health', fn() => Response::success());

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

> **v3.4 起消息语义变更（契约级）**：`Request` / `Response` / `ServerRequest` 的 `with*` 方法
> **原地修改并返回自身**（仿 webman / hyperf），不再克隆。即 `$a === $a->withHeader(...)`，
> 且 `$a->withHeader(...)` 会改 `$a` 本身。这消除了中间件管道逐层改消息时的对象分配。
> 若需独立快照请显式 `clone $msg`。`Uri` 仍保持 PSR-7 不可变语义。
>
> 约定：中间件「只用返回值、不在中间件之间持有消息引用」，避免可变语义导致的隐蔽串改。

## PSR-15 中间件

> v3.4 起中间件管道为**无状态、可重入、零逐请求分配**实现：`MiddlewarePipeline` 在首次 `handle()` 时将中间件栈**预编译**为一个内部闭包链（洋葱模型），之后每请求直接复用，不再逐层 `new` 游标、不再有递归调用栈。管道对象本身只持有「中间件栈 + 最终处理器」，同一实例可在 Swoole 协程 / Fiber 并发环境下安全复用。

| 中间件 | 说明 |
|--------|------|
| `MiddlewareDispatcher` | 核心中间件调度器，管理中间件栈并执行调度 |
| `MiddlewarePipeline` | 无状态管道实现，首次 handle 预编译闭包链、支持链式调用 |
| `CallableMiddleware` | 将可调用对象转换为中间件 |
| `CorsMiddleware` | CORS 跨域处理 |
| `RateLimitMiddleware` | 请求限流 |
| `JsonErrorHandlerMiddleware` | JSON 错误处理 |
| `BodyParser` | 自动解析 JSON / 表单 / XML 请求体（PHP 8.3 `json_validate`） |
| `RequestId` | 生成 / 复用请求 ID（`X-Request-Id`），便于链路追踪 |
| `ResponseTime` | 注入 `X-Response-Time` 响应耗时头（`hrtime` 纳秒计时） |
| `Compression` | 按 `Accept-Encoding` 协商 gzip / deflate 压缩响应体 |
| `SecurityHeaders` | 注入 X-Content-Type-Options / X-Frame-Options / Referrer-Policy 等安全头 |

## 集成组件

| 组件 | 说明 |
|------|------|
| `ProcessWorkerMiddleware` | 进程工作单元，集成 `kode/process`（≥5.x），支持分布式，可接管真实进程池 |
| `FiberCoroutineMiddleware` | Fiber 协程，集成 `kode/fibers`（≥4.x）作为统一并发引擎，支持分布式 |
| `ParallelMiddleware` | 并行处理，集成 `kode/parallel`（≥1.x），支持分布式 |
| `QueueMiddleware` | 队列派发，集成 `kode/queue`（≥2.x），处理器返回后统一派发收集的任务 |

> **并发引擎优先级（均可优雅降级）**：`ParallelMiddleware` 优先使用 `kode/parallel`（需 ZTS + ext-parallel 真多线程），其次 `kode/fibers` 统一并发门面，最后回退原生 `\Fiber`；`FiberCoroutineMiddleware` 优先 `kode/fibers::concurrent` + 逐任务重试，回退原生 `\Fiber`。`kode/fibers` / `kode/parallel` / `kode/process` / `kode/queue` 均为可选依赖（已纳入 `require-dev` 与 `suggest`），未安装时自动降级，不影响基础功能。

### 服务容器与门面（kode/facade 集成）

`Kode` 本身实现 PSR-11 容器接口，可无缝接入 `kode/facade` 的 `FacadeProxy`，并启用 **context-safe 模式**，在 Swoole / Fiber 协程环境下按请求（Context 作用域）隔离服务解析，避免跨协程串号：

```php
use Kode\Http\Kode;
use Kode\Http\Support\ServiceFacade;

Kode::register('cache', new Cache());
Kode::enableFacades();           // 将 Kode 设为 kode/facade 后端容器并启用协程安全

final class Cache extends ServiceFacade
{
    protected static function id(): string { return 'cache'; }
}

Cache::get('key');               // 经 Kode 容器解析，协程安全
```

### 队列派发（kode/queue 集成）

在路由处理器中收集后台任务，由 `QueueMiddleware` 在响应返回后统一派发，避免阻塞响应；任务收集基于 `kode/context` 请求作用域，天然协程安全：

```php
use Kode\Http\Integration\QueueMiddleware;
use Kode\Http\Queue\Queue;

// bootstrap 中注入管理器（也可用 Queue::setManagerFromContainer($psr11)）
Queue::setManager(\Kode\Queue\QueueManager::make([/* 连接配置 */]));
$app->pipe(QueueMiddleware::fromContainer(Kode::container()));

// 路由处理器内
Queue::push(SendMail::class, ['to' => $email]);   // 仅收集，不阻塞响应
```

未配置 `QueueManager` 时自动懒加载内存驱动，便于本地开发与测试。

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
│   ├── Message/                   # 消息类（Request/Response/ServerRequest）
│   ├── Factory/                   # PSR-17 工厂（含 Psr17Factory 聚合工厂）
│   ├── Trait/                     # 可复用 Trait（RequestTrait/ResponseTrait）
│   ├── Stream.php                 # 自研流实现
│   ├── Uri.php                    # URI 实现
│   └── UploadedFile.php           # PSR-7 上传文件
├── Routing/                       # 路由子系统
│   ├── Router.php                 # 静态哈希 + 动态正则两级匹配，区分 404/405
│   ├── Route.php                  # 路由定义（参数约束 / 可选参数 / 命名）
│   ├── RouteResult.php            # 匹配结果（FOUND/NOT_FOUND/METHOD_NOT_ALLOWED）
│   └── RouteRunner.php            # 路由执行器（最终处理器，参数注入 + 返回值归一化）
├── Middleware/                    # PSR-15 中间件
│   ├── MiddlewareInterface.php
│   ├── MiddlewareDispatcher.php
│   ├── MiddlewarePipeline.php      # 首次 handle 预编译为闭包链（洋葱模型），零逐请求分配
│   ├── CallableMiddleware.php
│   ├── CorsMiddleware.php
│   ├── RateLimitMiddleware.php
│   ├── JsonErrorHandlerMiddleware.php
│   ├── BodyParser.php             # 请求体解析
│   ├── RequestId.php              # 请求 ID
│   ├── ResponseTime.php           # 响应耗时
│   ├── Compression.php            # 响应压缩
│   └── SecurityHeaders.php        # 安全响应头
├── Integration/                   # 集成组件
│   ├── DistributedConfig.php
│   ├── ProcessWorkerMiddleware.php
│   ├── FiberCoroutineMiddleware.php
│   ├── ParallelMiddleware.php
│   └── QueueMiddleware.php         # 队列派发（kode/queue）
├── Queue/                         # 队列门面封装（kode/queue）
│   └── Queue.php                   # 按请求收集、统一派发的队列门面
├── Support/                       # 支持组件
│   └── ServiceFacade.php           # 协程安全的服务门面基类（kode/facade）
├── Server/                       # 服务端适配器
├── Exception/                     # 异常
├── App.php                       # 应用构建器
├── Request.php                   # 请求助手（kode/context 隔离）
├── Response.php                  # 响应助手（链式 + 返回值归一化）
├── Emitter.php                   # PSR-7 响应发射器（分块输出）
├── Status.php                    # HTTP 状态码枚举（类型安全 + 原因短语）
├── Method.php                    # HTTP 方法枚举（ROUTABLE/isSafe/isIdempotent）
├── Kode.php                      # 框架入口
└── functions.php                 # 辅助函数（指向 Psr17Factory）
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
    ├── kode/context     # 请求上下文传递和管理（按请求隔离，协程安全）
    │
    ├── kode/exception   # 异常体系（错误码 / 链路追踪头）
    │
    ├── kode/runtime     # 协程运行时抽象
    │
    ├── kode/facade      # 服务门面（context-safe 协程安全解析，Kode 容器已接入）
    │
    ├── kode/fibers      # Fiber 协程调度
    │       │
    │       └── kode/parallel  # 并行任务处理
    │
    ├── kode/process     # 进程管理和 Worker
    │       │
    │       └── kode/http-client  # HTTP 客户端（统一到 PSR-7 抽象）
    │
    └── kode/queue       # 后台任务队列（QueueMiddleware 响应后统一派发）
```

## 版本历史

- **v3.4.0** - 性能重构（落地框架侧三方案）：**B** `MiddlewarePipeline` 预编译为闭包链、零逐请求分配（删除 `PipelineRunner`）；**C** `RouteRunner` 按路由缓存已解析 handler + 路由级管道；**A** 请求/响应消息改为**可变**（`with*` 原地修改并返回自身，仿 webman / hyperf），移除 PSR-7 不可变语义。详见 `CHANGELOG.md`
- **v3.3.0** - **合并 Response 工厂与真实 PSR-7**：`Kode\Http\Response` 现在直接继承 `Psr7\Message\Response`，`json()`/`error()`/`success()`/`fail()` 返回的就是真实 PSR-7 响应，中间件/处理器可 `return Response::json(...)` 而无需 `->send()`（保留为向后兼容空操作）；Cookie 走 `Set-Cookie` 头、链式辅助方法（cookie/withCors/withSecurity…）全部保留；`MiddlewarePipeline` 出管道时经 `Response::resolve()` 归一化
- **v3.2.0** - 接入最新版 `kode/facade`(^3.2) 与 `kode/queue`(^2.2)：`Kode` 实现 PSR-11 容器并接入 `FacadeProxy`（context-safe 协程安全服务解析），新增 `Support/ServiceFacade` 基础门面；新增 `Queue/Queue` 门面封装（按请求 Context 作用域收集、响应后统一派发、未配置优雅降级）与 `Integration/QueueMiddleware`；所有 kode 依赖锁定到最新稳定版
- **v3.1.0** - 全面接入最新版 kode 生态：`kode/context` 升到 `^3.1`、`kode/exception` 升到 `^3.0`，并接入 `kode/fibers`(^4.10)/`kode/parallel`(^1.18)/`kode/process`(^5.2)；`Integration` 中间件改用最新版并发/进程引擎（Fibers 门面优先，parallel/process 可用时接管）并保留优雅降级；`Request` 将入站 `X-Request-Id`/`traceparent`/`X-Trace-Id` 写入 `kode/context` 3.x 链路追踪；`JsonErrorHandlerMiddleware` 透传 `X-Trace-Id`/`X-Span-Id` 链路头；修复 `extension_loaded('fibers')` 误判导致 Fiber 任务不执行的问题
- **v3.0.0** - PHP 8.3+ 最低支持；重写路由器（静态哈希 + 动态正则、区分 404/405、命名路由 URL 生成）、无状态可重入中间件管道；新增 `Status`/`Method` 枚举、`Emitter`、BodyParser/RequestId/ResponseTime/Compression/SecurityHeaders 中间件；修复 PSR-7 大小写不敏感头查找告警
- **v2.1.0** - 增强 App 应用构建器，支持路由参数提取
- **v2.0.0** - 借鉴 ThinkPHP/Laravel/webman 重构 API
- **v1.5.0** - 增强 Request 请求助手方法
- **v1.4.0** - 新增 App、Request、Response 统一 API
- **v1.3.0** - 适配 kode/exception ^2.0
- **v1.0.0** - 初始版本，PSR-7/15/17 基础实现

## License

Apache-2.0

# kode/http Version Mismatch

## Issue

Framework currently references kode/http 3.4.10 patch rewritten to v3.4.9 (commit a0a6d9d, 4 files +38/-9), but version bump in composer.json was only partially applied.

## Details

- **Commit a0a6d9d** (v3.4.9): "热路径优化——traceWritten 按需清理 / errMW 自研响应短路 / isJsonContentType 轻量判定（headerNames 精确映射})"
  - Changed 4 files: `src/Middleware/JsonErrorHandlerMiddleware.php`, `src/Request.php`, `src/Response.php`, `tests/ResponseBuilderTest.php`
  - +38/-9 lines

- **Current state**: composer.json version is `3.4.13`, but the version bump was only partially applied, creating a mismatch between the referenced version and the actual committed changes.

- **Impact**: Minor - framework's HttpServer.php already works with both via compatibility shims

## Resolution

Ensure composer.json version accurately reflects the intended release, and all changes from relevant commits are properly applied and documented.
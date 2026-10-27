# Laravel Gitee / GitHub Webhook 自动更新扩展包

通过 Gitee 或 GitHub Webhook 安全触发 Laravel 项目的 Git 更新，支持 Laravel 8 及以上版本。

## 安装

当前 Packagist 收录版本为 `dev-main`，使用以下命令安装：

```bash
composer require zzktz/laravel-githook:dev-main
php artisan vendor:publish --tag=githook-config
```

发布稳定版本后，可将 `dev-main` 替换为对应的稳定版本号。

Service Provider 支持 Laravel Composer 自动发现。若项目关闭了自动发现，请在 `config/app.php` 的 `providers` 中注册 `Zzktz\LaravelGithook\GithookServiceProvider::class`。

## 配置

在 `.env` 中设置：

```dotenv
GITHOOK_ENABLED=true
GITHOOK_SECRET=请替换为随机长密钥
GITHOOK_GITHUB_ENABLED=true
GITHOOK_GITHUB_SECRET=请替换为另一随机长密钥
GITHOOK_ROUTE_PREFIX=githook
GITHOOK_ROUTE_URI=webhook
GITHOOK_GITHUB_ROUTE_URI=github
GITHOOK_LOG_CHANNEL=stack
```

`GITHOOK_ENABLED` 是总开关，GitHub 入口还需要启用 `GITHOOK_GITHUB_ENABLED`。Gitee 使用 `GITHOOK_SECRET`（兼容旧配置）或 `GITHOOK_GITEE_SECRET`；GitHub 使用单独的 `GITHOOK_GITHUB_SECRET`。

在 `config/githook.php` 配置仓库。`path` 必须是服务器上已存在的 Git checkout 绝对路径；包只接受配置的仓库和分支。示例：

```php
'repositories' => [
    'production' => [
        'gitee_repository' => 'your-team/your-project',
        'github_repository' => 'your-org/your-project',
        'remote' => 'git@github.com:your-org/your-project.git',
        'path' => base_path(),
        'branches' => ['main'],
        'secrets' => [
            'gitee' => env('GITHOOK_PRODUCTION_GITEE_SECRET'),
            'github' => env('GITHOOK_PRODUCTION_GITHUB_SECRET'),
        ],
    ],
],
```

仓库名按 provider 分别匹配：Gitee 使用 `gitee_repository`，GitHub 使用 `github_repository`；省略时使用通用 `repository` 字段或配置键。仓库级 `secrets.gitee` / `secrets.github` 优先于各自的全局密钥。旧版的仓库 `secret` 仍作为 Gitee 专属密钥。

Gitee Webhook 使用 `X-Gitee-Token` 和 `X-Gitee-Event` 校验。GitHub Webhook 使用 `X-GitHub-Event: push`，并以配置的 Secret 校验原始请求体的 `X-Hub-Signature-256` HMAC-SHA256 签名。

默认要求工作树干净，只执行 `fetch` 和快进更新；本地有未提交文件或分支发生分叉时会拒绝部署，不会执行强制重置。请确保 PHP-FPM/应用用户有目录写权限，并能通过 Git remote 配置的 SSH key 或 HTTPS 凭据访问仓库。Web 服务器应使用 HTTPS。

默认 Gitee API 地址为 `POST /githook/webhook`，GitHub 地址为 `POST /githook/github`。分别在 Gitee 选择 Push Hook，在 GitHub Webhooks 中选择 `Just the push event`，Content type 选择 `application/json`，并填写 GitHub Secret。可通过 `GITHOOK_ROUTE_PREFIX`、`GITHOOK_ROUTE_URI`、`GITHOOK_GITHUB_ROUTE_URI` 和 `githook.route.middleware` 修改路径及中间件。默认限制 JSON 请求体为 1 MiB；来源 IP 白名单分别配置在 `providers.gitee.allowed_ips` 和 `providers.github.allowed_ips`。

## 部署行为

部署前会检查服务开关、JSON、事件类型、来源 IP（若启用）、仓库身份、共享密钥、分支白名单、工作目录、Git worktree、remote 和工作树状态。相同目录的并发请求通过文件锁串行保护；Git 操作和每条部署命令默认超时 120 秒，可通过 `deployment.timeout` 调整。命令达到超时后会被终止并作为部署失败返回，避免网络等待无限期挂起。

首版在 webhook HTTP 请求中同步部署。若更新步骤可能超过 webhook 提供方的请求等待时限，应缩短命令耗时或在后续集成队列异步处理；超时后的 webhook 重试可能再次触发部署，因此部署命令应保持幂等。

代码更新后默认执行 `composer install --no-interaction --prefer-dist`，根据仓库中的 `composer.lock` 同步 A 项目的依赖，因此通常无需登录服务器手动安装依赖。开发时修改依赖后，应在开发环境更新并提交 `composer.lock`，否则部署无法保证安装到预期版本。还需确保运行 Laravel 的用户能执行 Composer、写入 `vendor` 目录。若不需要默认安装或要调整命令，可在 `deployment.commands` 中覆盖配置。命令必须写成参数数组，不能来自 webhook payload，例如：

```php
'deployment' => [
    'commands' => [
        ['composer', 'install', '--no-dev', '--prefer-dist', '--no-interaction'],
        ['php', 'artisan', 'config:cache'],
    ],
    'health_check' => [
        'url' => 'https://example.com/health',
        'timeout' => 5,
    ],
],
```

健康检查 URL 和部署命令只应由项目管理员配置。数据库迁移默认不执行；确认项目迁移流程可安全自动化后，才应将其作为受控的部署命令配置。

部署命令成功或失败都会写入 Laravel 配置的日志 channel（默认 `stack`，通常记录在 `storage/logs/laravel.log`）。失败日志包含退出码、超时状态和截断后的命令错误输出；日志会对常见 URL 凭据和令牌格式进行脱敏。Webhook 响应只返回状态、稳定错误码、request ID 和必要的 commit 信息，不会返回命令输出。

## 本地开发

```bash
composer install
composer test
```

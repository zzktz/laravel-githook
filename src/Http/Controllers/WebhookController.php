<?php

namespace Zzktz\LaravelGithook\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Zzktz\LaravelGithook\Exceptions\DeploymentException;
use Zzktz\LaravelGithook\Services\DeploymentService;

class WebhookController
{
    public function handle(Request $request, DeploymentService $deploymentService)
    {
        $requestId = (string) Str::uuid();

        if (!config('githook.enabled', false)) {
            return $this->error('disabled', 404, $requestId);
        }

        if (!$request->isJson()) {
            return $this->error('invalid_content_type', 415, $requestId);
        }

        $maxBytes = (int) config('githook.max_payload_bytes', 1048576);
        $body = $request->getContent();
        if (strlen($body) > $maxBytes) {
            return $this->error('payload_too_large', 413, $requestId);
        }

        $payload = json_decode($body, true);
        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            return $this->error('invalid_json', 400, $requestId);
        }

        $event = strtolower(trim((string) $request->header('X-Gitee-Event', '')));
        $allowedEvents = array_map('strtolower', (array) config('githook.allowed_events', ['push hook']));
        if ($event === '' || !in_array($event, $allowedEvents, true)) {
            return $this->error('event_not_allowed', 403, $requestId);
        }

        $clientIp = $request->ip();
        $allowedIps = (array) config('githook.allowed_ips', []);
        if (!empty($allowedIps) && !in_array($clientIp, $allowedIps, true)) {
            return $this->error('source_not_allowed', 403, $requestId);
        }

        $repositoryPayload = isset($payload['repository']) && is_array($payload['repository'])
            ? $payload['repository']
            : [];
        $repositoryName = isset($repositoryPayload['full_name']) ? $repositoryPayload['full_name'] : null;
        if (!is_string($repositoryName) || $repositoryName === '') {
            return $this->error('repository_invalid', 422, $requestId);
        }

        $repository = null;
        $alias = null;
        foreach ((array) config('githook.repositories', []) as $key => $candidate) {
            $expectedName = isset($candidate['gitee_repository']) ? $candidate['gitee_repository'] : $key;
            if ($expectedName === $repositoryName) {
                $repository = $candidate;
                $alias = $key;
                break;
            }
        }

        if ($repository === null) {
            # 对未配置仓库和错误密钥返回相同结果，避免暴露仓库配置。
            return $this->error('unauthorized', 401, $requestId);
        }

        $secret = isset($repository['secret']) ? $repository['secret'] : config('githook.secret');
        $token = (string) $request->header('X-Gitee-Token', '');
        if (!is_string($secret) || $secret === '' || $token === '' || !hash_equals((string) $secret, $token)) {
            return $this->error('unauthorized', 401, $requestId);
        }

        $branch = $this->branchFromPayload(isset($payload['ref']) ? $payload['ref'] : null);
        $branches = isset($repository['branches']) ? (array) $repository['branches'] : [];
        if ($branch === null || !in_array($branch, $branches, true)) {
            return $this->error('branch_not_allowed', 403, $requestId);
        }

        try {
            $result = $deploymentService->deploy($repository, $branch, $alias);
        } catch (DeploymentException $exception) {
            $this->logResult('warning', $exception->errorCode(), $requestId, $alias, $branch);
            return $this->error($exception->errorCode(), $exception->httpStatus(), $requestId);
        } catch (\Throwable $exception) {
            $this->logResult('error', 'deployment_failed', $requestId, $alias, $branch);
            return $this->error('deployment_failed', 500, $requestId);
        }

        $this->logResult('info', 'deployment_succeeded', $requestId, $alias, $branch, $result['new_commit']);

        return response()->json([
            'status' => 'success',
            'request_id' => $requestId,
            'repository' => $alias,
            'branch' => $branch,
            'commit' => $result['new_commit'],
        ]);
    }

    private function branchFromPayload($ref)
    {
        if (!is_string($ref) || strpos($ref, 'refs/heads/') !== 0) {
            return null;
        }

        $branch = substr($ref, strlen('refs/heads/'));
        if ($branch === '' || preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) !== 1
            || strpos($branch, '..') !== false || strpos($branch, '//') !== false
            || strpos($branch, '/') === 0 || substr($branch, -1) === '/'
            || strpos($branch, '-') === 0 || strpos($branch, '@{') !== false) {
            return null;
        }

        return $branch;
    }

    private function error($code, $status, $requestId)
    {
        return response()->json([
            'status' => 'error',
            'error' => $code,
            'request_id' => $requestId,
        ], $status);
    }

    private function logResult($level, $code, $requestId, $repository, $branch, $commit = null)
    {
        $context = [
            'request_id' => $requestId,
            'repository' => $repository,
            'branch' => $branch,
            'code' => $code,
        ];

        if ($commit !== null) {
            $context['commit'] = $commit;
        }

        $channel = config('githook.logging.channel', 'stack');
        Log::channel($channel)->{$level}('Gitee webhook deployment', $context);
    }
}

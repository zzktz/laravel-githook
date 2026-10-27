<?php

namespace Zzktz\LaravelGithook\Services;

use RuntimeException;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GitClient
{
    public function deploy($path, $remote, $branch, $requireClean, $timeout)
    {
        $workingDirectory = $this->validateWorkingDirectory($path);
        $this->run(['git', 'rev-parse', '--is-inside-work-tree'], $workingDirectory, $timeout);
        $repositoryRoot = trim($this->run(['git', 'rev-parse', '--show-toplevel'], $workingDirectory, $timeout));
        if (realpath($repositoryRoot) !== $workingDirectory) {
            throw new RuntimeException('deployment_path_not_repository_root');
        }

        $configuredRemote = trim($this->run(['git', 'remote', 'get-url', 'origin'], $workingDirectory, $timeout));
        if (!$this->sameRemote($configuredRemote, $remote)) {
            throw new RuntimeException('remote_mismatch');
        }

        if ($requireClean) {
            $status = $this->run(['git', 'status', '--porcelain', '--untracked-files=normal'], $workingDirectory, $timeout);
            if (trim($status) !== '') {
                throw new RuntimeException('worktree_not_clean');
            }
        }

        $oldCommit = trim($this->run(['git', 'rev-parse', 'HEAD'], $workingDirectory, $timeout));
        $this->run([
            'git', 'fetch', '--no-tags', 'origin',
            'refs/heads/' . $branch . ':refs/remotes/origin/' . $branch,
        ], $workingDirectory, $timeout);

        $localBranch = $this->run(
            ['git', 'show-ref', '--verify', '--quiet', 'refs/heads/' . $branch],
            $workingDirectory,
            $timeout,
            true
        );

        if ($localBranch === null) {
            $this->run(['git', 'checkout', '--track', '-b', $branch, 'origin/' . $branch], $workingDirectory, $timeout);
        } else {
            $this->run(['git', 'checkout', $branch], $workingDirectory, $timeout);
            $this->run(['git', 'merge', '--ff-only', 'origin/' . $branch], $workingDirectory, $timeout);
        }

        $newCommit = trim($this->run(['git', 'rev-parse', 'HEAD'], $workingDirectory, $timeout));

        return [
            'path' => $workingDirectory,
            'old_commit' => $oldCommit,
            'new_commit' => $newCommit,
        ];
    }

    public function runCommand(array $command, $workingDirectory, $timeout, array $context = [])
    {
        if (empty($command) || !is_string($command[0])) {
            throw new RuntimeException('invalid_deployment_command');
        }

        foreach ($command as $argument) {
            if (!is_string($argument) && !is_numeric($argument)) {
                throw new RuntimeException('invalid_deployment_command');
            }
        }

        $name = basename(str_replace('\\', '/', $command[0]));
        $startedAt = microtime(true);
        $process = null;

        try {
            $process = new Process(array_values($command), $workingDirectory, null, null, (float) $timeout);
            $process->run();
        } catch (\Throwable $exception) {
            $timedOut = $exception instanceof ProcessTimedOutException;
            if ($timedOut) {
                $process = $exception->getProcess();
            }

            $this->logDeploymentCommand('error', 'Deployment command failed', array_merge($context, [
                'command' => $name,
                'code' => $timedOut ? 'command_timeout' : 'command_process_failed',
                'timed_out' => $timedOut,
                'exit_code' => $process ? $process->getExitCode() : null,
                'duration_seconds' => round(microtime(true) - $startedAt, 3),
                'output' => $process
                    ? $this->sanitizeLogOutput($process->getErrorOutput() . $process->getOutput())
                    : '',
            ]));

            throw new RuntimeException($timedOut ? 'command_timeout' : 'command_process_failed');
        }

        $duration = round(microtime(true) - $startedAt, 3);
        if (!$process->isSuccessful()) {
            $this->logDeploymentCommand('error', 'Deployment command failed', array_merge($context, [
                'command' => $name,
                'code' => 'deployment_command_failed',
                'exit_code' => $process->getExitCode(),
                'timed_out' => false,
                'duration_seconds' => $duration,
                'output' => $this->sanitizeLogOutput($process->getErrorOutput() . $process->getOutput()),
            ]));

            throw new RuntimeException('deployment_command_failed');
        }

        $this->logDeploymentCommand('info', 'Deployment command succeeded', array_merge($context, [
            'command' => $name,
            'duration_seconds' => $duration,
        ]));

        return $process->getOutput();
    }

    private function logDeploymentCommand($level, $message, array $context)
    {
        $channel = function_exists('config') ? config('githook.logging.channel', 'stack') : 'stack';
        Log::channel($channel)->{$level}($message, $context);
    }

    private function sanitizeLogOutput($output)
    {
        $output = preg_replace('~(https?://)[^/\\s@]+@~i', '$1[redacted]@', (string) $output);
        $output = preg_replace('~\\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,}|glpat-[A-Za-z0-9_-]{20,})\\b~', '[redacted-token]', $output);
        $output = preg_replace('~([?&](?:access_token|token|password|secret|key)=)[^&\\s]+~i', '$1[redacted]', $output);
        $output = preg_replace('~\\b(access_token|token|password|secret|api_key)\\s*([:=])\\s*[^\\s,;]+~i', '$1$2[redacted]', $output);
        $output = preg_replace('~(authorization\\s*:\\s*)\\S+(?:\\s+\\S+)?~i', '$1[redacted]', $output);

        if (strlen($output) > 12000) {
            $output = substr($output, 0, 12000) . ' [output truncated]';
        }

        return trim($output);
    }

    private function validateWorkingDirectory($path)
    {
        if (!is_string($path) || $path === '' || $path[0] !== DIRECTORY_SEPARATOR) {
            throw new RuntimeException('invalid_deployment_path');
        }

        $realPath = realpath($path);
        if ($realPath === false || $realPath === DIRECTORY_SEPARATOR || !is_dir($realPath)) {
            throw new RuntimeException('invalid_deployment_path');
        }

        return $realPath;
    }

    private function sameRemote($actual, $expected)
    {
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return rtrim($actual, '/') === rtrim($expected, '/');
    }

    private function run(array $command, $workingDirectory, $timeout, $allowFailure = false)
    {
        try {
            $process = new Process($command, $workingDirectory, null, null, (float) $timeout);
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            throw new RuntimeException('command_timeout');
        } catch (\Throwable $exception) {
            # 不把进程启动异常中的命令、目录或远程地址返回给调用方。
            throw new RuntimeException('git_command_failed');
        }

        if (!$process->isSuccessful()) {
            if ($allowFailure) {
                return null;
            }

            # 不返回进程原始输出，Git 错误可能包含带认证信息的远程地址。
            throw new RuntimeException($process->isTimedOut() ? 'command_timeout' : 'git_command_failed');
        }

        return $process->getOutput();
    }
}

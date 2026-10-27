<?php

namespace Zzktz\LaravelGithook\Services;

use RuntimeException;
use Symfony\Component\Process\Process;

class GitClient
{
    public function deploy($path, $remote, $branch, $requireClean, $timeout)
    {
        $workingDirectory = $this->validateWorkingDirectory($path);
        $this->run(['git', 'rev-parse', '--is-inside-work-tree'], $workingDirectory, $timeout);

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

    public function runCommand(array $command, $workingDirectory, $timeout)
    {
        if (empty($command) || !is_string($command[0])) {
            throw new RuntimeException('invalid_deployment_command');
        }

        foreach ($command as $argument) {
            if (!is_string($argument) && !is_numeric($argument)) {
                throw new RuntimeException('invalid_deployment_command');
            }
        }

        $this->run(array_values($command), $workingDirectory, $timeout);
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
        $process = new Process($command, $workingDirectory, null, null, (float) $timeout);
        $process->run();

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

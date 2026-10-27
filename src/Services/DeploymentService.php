<?php

namespace Zzktz\LaravelGithook\Services;

use RuntimeException;
use Zzktz\LaravelGithook\Exceptions\DeploymentException;

class DeploymentService
{
    private $git;

    public function __construct(GitClient $git)
    {
        $this->git = $git;
    }

    public function deploy(array $repository, $branch, $alias)
    {
        $deployment = config('githook.deployment', []);
        $timeout = isset($deployment['timeout']) ? (int) $deployment['timeout'] : 120;
        $lockPath = isset($deployment['lock_path']) ? $deployment['lock_path'] : storage_path('framework/cache/laravel-githook');
        $lock = new DeploymentLock();

        try {
            $lock->acquire($lockPath, isset($repository['path']) ? $repository['path'] : $alias);
        } catch (RuntimeException $exception) {
            throw new DeploymentException($exception->getMessage(), $exception->getMessage() === 'deployment_in_progress' ? 409 : 500);
        }

        try {
            if (empty($repository['path']) || empty($repository['remote'])) {
                throw new DeploymentException('repository_configuration_invalid', 500);
            }

            $result = $this->git->deploy(
                $repository['path'],
                $repository['remote'],
                $branch,
                !isset($deployment['require_clean_worktree']) || (bool) $deployment['require_clean_worktree'],
                $timeout
            );

            foreach (isset($deployment['commands']) ? $deployment['commands'] : [] as $command) {
                $this->git->runCommand($command, $result['path'], $timeout);
            }

            $this->checkHealth($deployment, $timeout);

            return [
                'repository' => $alias,
                'branch' => $branch,
                'old_commit' => $result['old_commit'],
                'new_commit' => $result['new_commit'],
            ];
        } catch (DeploymentException $exception) {
            throw $exception;
        } catch (RuntimeException $exception) {
            $code = $exception->getMessage();
            $status = $code === 'deployment_in_progress' ? 409 : 422;
            throw new DeploymentException($code, $status);
        } finally {
            $lock->release();
        }
    }

    private function checkHealth(array $deployment, $defaultTimeout)
    {
        $health = isset($deployment['health_check']) ? $deployment['health_check'] : [];
        if (empty($health['url'])) {
            return;
        }

        $timeout = isset($health['timeout']) ? (int) $health['timeout'] : $defaultTimeout;
        try {
            $response = \Illuminate\Support\Facades\Http::timeout($timeout)->get($health['url']);
        } catch (\Throwable $exception) {
            throw new DeploymentException('health_check_failed', 502);
        }

        if (!$response->successful()) {
            throw new DeploymentException('health_check_failed', 502);
        }
    }
}

<?php

namespace Zzktz\LaravelGithook\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;
use Zzktz\LaravelGithook\Services\GitClient;

class GitClientTest extends TestCase
{
    private $directory;
    private $remote;
    private $checkout;
    private $client;

    protected function setUp(): void
    {
        parent::setUp();

        if (!$this->commandExists('git')) {
            $this->markTestSkipped('Git is required for integration tests.');
        }

        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'laravel-githook-' . uniqid('', true);
        mkdir($this->directory, 0755, true);
        $this->remote = $this->directory . '/origin.git';
        $this->checkout = $this->directory . '/checkout';
        $this->runGit(['git', 'init', '--bare', $this->remote]);
        $this->runGit(['git', 'clone', $this->remote, $this->checkout]);
        $this->runGit(['git', 'config', 'user.email', 'githook-test@example.invalid'], $this->checkout);
        $this->runGit(['git', 'config', 'user.name', 'Githook Test'], $this->checkout);
        file_put_contents($this->checkout . '/app.txt', 'initial');
        $this->runGit(['git', 'add', 'app.txt'], $this->checkout);
        $this->runGit(['git', 'commit', '-m', 'initial'], $this->checkout);
        $this->runGit(['git', 'branch', '-M', 'main'], $this->checkout);
        $this->runGit(['git', 'push', '-u', 'origin', 'main'], $this->checkout);
        $this->runGit(['git', 'symbolic-ref', 'HEAD', 'refs/heads/main'], $this->remote);

        # 重新克隆以隔离部署工作目录和提交代码的工作目录。
        $this->runGit(['git', 'clone', '--branch', 'main', $this->remote, $this->directory . '/deployed']);
        $this->checkout = $this->directory . '/deployed';
        $this->client = new GitClient();
    }

    protected function tearDown(): void
    {
        if ($this->directory && is_dir($this->directory)) {
            $this->removeDirectory($this->directory);
        }

        parent::tearDown();
    }

    public function testItFetchesAndFastForwardUpdatesTheConfiguredBranch()
    {
        $this->runGit(['git', 'config', 'user.email', 'githook-test@example.invalid'], $this->directory . '/checkout');
        $this->runGit(['git', 'config', 'user.name', 'Githook Test'], $this->directory . '/checkout');
        file_put_contents($this->directory . '/checkout/app.txt', 'updated');
        $this->runGit(['git', 'commit', '-am', 'update'], $this->directory . '/checkout');
        $this->runGit(['git', 'push', 'origin', 'main'], $this->directory . '/checkout');

        $result = $this->client->deploy($this->checkout, $this->remote, 'main', true, 10);

        $this->assertNotSame($result['old_commit'], $result['new_commit']);
        $this->assertSame('updated', file_get_contents($this->checkout . '/app.txt'));
    }

    public function testItRejectsAWorktreeWithLocalChanges()
    {
        file_put_contents($this->checkout . '/app.txt', 'local change');

        try {
            $this->client->deploy($this->checkout, $this->remote, 'main', true, 10);
            $this->fail('Expected dirty worktree to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('worktree_not_clean', $exception->getMessage());
        }
    }

    public function testItRejectsARemoteThatDoesNotMatchConfiguration()
    {
        try {
            $this->client->deploy($this->checkout, $this->directory . '/other.git', 'main', true, 10);
            $this->fail('Expected remote mismatch to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('remote_mismatch', $exception->getMessage());
        }
    }

    public function testItRejectsAPathInsideTheRepositoryInsteadOfItsRoot()
    {
        $nestedPath = $this->checkout . '/nested';
        mkdir($nestedPath);

        try {
            $this->client->deploy($nestedPath, $this->remote, 'main', true, 10);
            $this->fail('Expected a non-root working directory to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('deployment_path_not_repository_root', $exception->getMessage());
        }
    }

    private function runGit(array $command, $workingDirectory = null)
    {
        $process = new Process($command, $workingDirectory, [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
        ], null, 10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput() . $process->getOutput());
        }
    }

    private function commandExists($command)
    {
        $process = new Process([$command, '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    private function removeDirectory($directory)
    {
        $items = scandir($directory);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}

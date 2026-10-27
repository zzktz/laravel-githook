<?php

namespace Zzktz\LaravelGithook\Tests;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zzktz\LaravelGithook\Services\GitClient;

class DeploymentCommandLoggingTest extends TestCase
{
    private $container;
    private $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new class {
            public $records = [];

            public function channel($name)
            {
                return $this;
            }

            public function info($message, array $context = [])
            {
                $this->records[] = ['level' => 'info', 'message' => $message, 'context' => $context];
            }

            public function error($message, array $context = [])
            {
                $this->records[] = ['level' => 'error', 'message' => $message, 'context' => $context];
            }
        };

        $this->container = new Container();
        $this->container->instance('config', new class {
            public function get($key, $default = null)
            {
                return $key === 'githook.logging.channel' ? 'stack' : $default;
            }
        });
        $this->container->instance('log', $this->logger);
        Facade::setFacadeApplication($this->container);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('log');
        Facade::setFacadeApplication(null);

        parent::tearDown();
    }

    public function testItLogsSuccessfulDeploymentCommands()
    {
        $client = new GitClient();
        $output = $client->runCommand([PHP_BINARY, '-r', 'fwrite(STDOUT, "installed");'], __DIR__, 5, [
            'repository' => 'app',
            'branch' => 'main',
        ]);

        $this->assertSame('installed', $output);
        $this->assertSame('info', $this->logger->records[0]['level']);
        $this->assertSame('Deployment command succeeded', $this->logger->records[0]['message']);
        $this->assertSame('app', $this->logger->records[0]['context']['repository']);
    }

    public function testItLogsCommandFailureOutputAndRedactsTokens()
    {
        $client = new GitClient();

        try {
            $client->runCommand([PHP_BINARY, '-r', 'fwrite(STDERR, "network failed token=secret-value"); exit(7);'], __DIR__, 5);
            $this->fail('Expected the command to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('deployment_command_failed', $exception->getMessage());
        }

        $record = $this->logger->records[0];
        $this->assertSame('error', $record['level']);
        $this->assertSame(7, $record['context']['exit_code']);
        $this->assertStringContainsString('network failed', $record['context']['output']);
        $this->assertStringNotContainsString('secret-value', $record['context']['output']);
    }

    public function testItStopsAndLogsACommandThatExceedsItsTimeout()
    {
        $client = new GitClient();

        try {
            $client->runCommand([PHP_BINARY, '-r', 'sleep(3);'], __DIR__, 0.1);
            $this->fail('Expected the command to time out.');
        } catch (RuntimeException $exception) {
            $this->assertSame('command_timeout', $exception->getMessage());
        }

        $record = $this->logger->records[0];
        $this->assertSame('error', $record['level']);
        $this->assertSame('command_timeout', $record['context']['code']);
        $this->assertTrue($record['context']['timed_out']);
    }
}

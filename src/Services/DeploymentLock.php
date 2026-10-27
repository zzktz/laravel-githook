<?php

namespace Zzktz\LaravelGithook\Services;

use RuntimeException;

class DeploymentLock
{
    private $handle;

    public function acquire($directory, $key)
    {
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('lock_unavailable');
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sha1($key) . '.lock';
        $this->handle = @fopen($path, 'c');

        if (!$this->handle) {
            throw new RuntimeException('lock_unavailable');
        }

        if (!@flock($this->handle, LOCK_EX | LOCK_NB)) {
            $this->release();
            throw new RuntimeException('deployment_in_progress');
        }
    }

    public function release()
    {
        if (is_resource($this->handle)) {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}

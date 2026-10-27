<?php

namespace Zzktz\LaravelGithook\Exceptions;

use RuntimeException;

class DeploymentException extends RuntimeException
{
    private $errorCode;
    private $httpStatus;

    public function __construct($errorCode, $httpStatus = 422)
    {
        parent::__construct($errorCode);
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
    }

    public function errorCode()
    {
        return $this->errorCode;
    }

    public function httpStatus()
    {
        return $this->httpStatus;
    }
}

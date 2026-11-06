<?php

namespace Ux1\UxStudio\Core;

\defined('_JEXEC') or die;

/**
 * Thrown by API handlers to produce a clean JSON error with a given HTTP status.
 */
class ApiException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $message, int $status = 400, string $errorCode = 'bad_request', ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
        $this->errorCode = $errorCode;
    }

    public function status(): int
    {
        return $this->getCode();
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

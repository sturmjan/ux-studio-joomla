<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\User\User;

\defined('_JEXEC') or die;

/**
 * Immutable view of one API call, handed to module route handlers.
 */
final class ApiRequest
{
    /**
     * @param string               $method  Upper-case HTTP method.
     * @param string               $path    Route path inside the module (without the module id).
     * @param array<string,string> $params  Path placeholders ({id}).
     * @param array<string,mixed>  $query   Query string values.
     * @param array<string,mixed>  $body    Decoded JSON body.
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $params,
        private array $query,
        private array $body,
        private User $user
    ) {
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function user(): User
    {
        return $this->user;
    }

    /** Path placeholder value, e.g. param('id') for route 'GET items/{id}'. */
    public function param(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    /** Query string value. */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /** Whole decoded JSON body. */
    public function body(): array
    {
        return $this->body;
    }

    /** JSON body value (falls back to the query string). */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->input($key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        $v = $this->input($key, $default);

        return \is_scalar($v) ? (string) $v : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->input($key, $default), FILTER_VALIDATE_BOOLEAN);
    }
}

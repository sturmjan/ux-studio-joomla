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
     * @param string               $raw     Raw request body (public routes only; needed to verify signatures of your own).
     * @param array<string,array<int,array{name:string,type:string,size:int,tmp_name:string,error:int}>> $files Uploaded files (multipart public routes).
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $params,
        private array $query,
        private array $body,
        private User $user,
        private string $raw = '',
        private array $files = []
    ) {
    }

    /**
     * Files uploaded with a multipart/form-data request to a public route that declares 'max_files'. Key = input name,
     * nested names joined with dots (files[photo] / files[photo][] => "files.photo"); every value is a list of
     * {name, type, size, tmp_name, error}. Already checked: really uploaded by PHP, no error, within the route limits.
     * The client supplied name and type are untrusted: validate the content yourself.
     *
     * @return array<string,array<int,array{name:string,type:string,size:int,tmp_name:string,error:int}>>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * Uploaded files of one input group: filesIn('files') returns ['photo' => [..files..]] for files[photo] / files[photo][].
     * An input called exactly like the group (files / files[]) is returned under the key ''.
     *
     * @return array<string,array<int,array{name:string,type:string,size:int,tmp_name:string,error:int}>>
     */
    public function filesIn(string $group): array
    {
        $out = [];
        foreach ($this->files as $key => $list) {
            if ($key === $group) {
                $out[''] = $list;
            } elseif (str_starts_with($key, $group . '.')) {
                $out[substr($key, \strlen($group) + 1)] = $list;
            }
        }

        return $out;
    }

    /** Raw request body as received (public routes only, empty for administrator routes). */
    public function raw(): string
    {
        return $this->raw;
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

    /** Whole query string as an array (public routes: without option / task / format / route). */
    public function queryAll(): array
    {
        return $this->query;
    }

    /** Everything the client sent: query string values overridden by the JSON body. */
    public function all(): array
    {
        return $this->body + $this->query;
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

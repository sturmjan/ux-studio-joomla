<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;

\defined('_JEXEC') or die;

/**
 * The single PUBLIC (site) JSON endpoint:
 *   index.php?option=com_uxstudio&task=public.dispatch&format=json&route=<module>/<path>
 * Serves BaseModule::publicRoutes(). Pipeline: module known + enabled, route + method, client IP rate limit,
 * body size cap, Origin check (auth "none"), authentication (none | token | hmac), then the handler.
 * There is no session / ACL / CSRF here by design: the handler validates its input itself.
 */
final class PublicApi
{
    private const DEFAULT_LIMIT    = [30, 60];
    private const DEFAULT_MAX_BODY = 65536;
    private const HMAC_TOLERANCE   = 300;

    public function __construct(private Kernel $kernel)
    {
    }

    /** @return array{0:int,1:array<string,mixed>,2:array<string,string>} HTTP status, payload, extra headers */
    public function handle(CMSApplicationInterface $app): array
    {
        try {
            Lang::loadCore($app);
            $input  = $app->getInput();
            $method = strtoupper((string) $input->server->get('REQUEST_METHOD', 'GET', 'string'));
            $route  = trim((string) $input->get('route', '', 'string'), '/');
            if (!preg_match('#^[a-z][a-z0-9-]*(/[A-Za-z0-9_.{}-]+)*$#', $route)) {
                throw new ApiException(Text::_('UXS_CORE_ERR_NOT_FOUND'), 404, 'not_found');
            }

            $this->kernel->migrator()->ensureCore();

            [$target, $path] = array_pad(explode('/', $route, 2), 2, '');
            $registry        = $this->kernel->registry();
            $module          = $registry->has($target) && $registry->isEnabled($target) ? $registry->module($target) : null;
            if (!$module) {
                // Do not reveal whether a module exists but is disabled.
                throw new ApiException(Text::_('UXS_CORE_ERR_NOT_FOUND'), 404, 'not_found');
            }
            Lang::loadModule($app, $target, $module->path());

            [$pattern, $def, $params, $pathExists] = $this->match($module->publicRoutes(), $method, $path);
            if ($def === null) {
                throw $pathExists
                    ? new ApiException(Text::_('UXS_CORE_ERR_METHOD'), 405, 'method_not_allowed')
                    : new ApiException(Text::_('UXS_CORE_ERR_NOT_FOUND'), 404, 'not_found');
            }

            // Rate limit first: cheap and protects the checks below.
            $ip = ClientIp::current();
            [$max, $window] = array_pad(array_map('intval', (array) ($def['limit'] ?? self::DEFAULT_LIMIT)), 2, 60);
            $retry = (new RateLimiter($this->kernel->db()))->hit($target . '|' . $pattern . '|' . $ip, max(1, $max), max(1, $window));
            if ($retry > 0) {
                throw new ApiException(Text::_('UXS_CORE_ERR_RATE_LIMIT'), 429, 'rate_limited', null, ['Retry-After' => (string) $retry]);
            }

            $maxBody = (int) ($def['max_body'] ?? self::DEFAULT_MAX_BODY);
            $raw     = $this->readBody($input, $method, $maxBody);

            $auth = (string) ($def['auth'] ?? 'none');
            match ($auth) {
                'none'  => $this->checkOrigin($input),
                'token' => $this->checkToken($input, $def),
                'hmac'  => $this->checkHmac($input, $def, $raw),
                default => throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden'),
            };

            $body = [];
            if ($raw !== '') {
                $json = json_decode($raw, true);
                if (!\is_array($json)) {
                    throw new ApiException(Text::_('UXS_CORE_ERR_BODY'), 400, 'invalid_json');
                }
                $body = $json;
            }

            $query = $input->getArray();
            unset($query['option'], $query['task'], $query['format'], $query['route'], $query['view'], $query['controller']);

            $handler = $def['handler'] ?? null;
            if (!\is_callable($handler)) {
                throw new ApiException(Text::_('UXS_CORE_ERR_NOT_FOUND'), 404, 'not_found');
            }
            $data = $handler(new ApiRequest($method, $path, $params, $query, $body, $app->getIdentity(), $raw));

            return [200, ['ok' => true, 'data' => $data], []];
        } catch (ApiException $e) {
            return [$e->status() ?: 400, ['ok' => false, 'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]], $e->headers()];
        } catch (\Throwable $e) {
            Log::add(sprintf('Public API error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()), Log::ERROR, 'uxstudio');

            return [500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => Text::_('UXS_CORE_ERR_SERVER')]], []];
        }
    }

    /**
     * @param array<string,array<string,mixed>> $routes
     * @return array{0:string,1:?array<string,mixed>,2:array<string,string>,3:bool} pattern, definition, path params, path exists for another method
     */
    private function match(array $routes, string $method, string $path): array
    {
        $pathExists = false;
        foreach ($routes as $pattern => $def) {
            [$m, $p] = array_pad(explode(' ', (string) $pattern, 2), 2, '');
            $regex = '#^' . preg_replace('/\\\{([a-z_]+)\\\}/', '(?P<$1>[^/]+)', preg_quote(trim($p, '/'), '#')) . '$#';
            if (!preg_match($regex, $path, $found)) {
                continue;
            }
            $pathExists = true;
            if (strtoupper($m) === $method) {
                // A bare callable is accepted as shorthand for an unauthenticated route with default limits.
                if (!isset($def['handler']) && \is_callable($def)) {
                    $def = ['handler' => $def];
                }

                return [(string) $pattern, (array) $def, array_filter($found, 'is_string', ARRAY_FILTER_USE_KEY), true];
            }
        }

        return ['', null, [], $pathExists];
    }

    private function readBody($input, string $method, int $max): string
    {
        if ($method === 'GET' || $method === 'HEAD') {
            return '';
        }
        $length = (int) $input->server->get('CONTENT_LENGTH', 0, 'int');
        if ($length > $max) {
            throw new ApiException(Text::_('UXS_CORE_ERR_TOO_LARGE'), 413, 'payload_too_large');
        }
        $raw = (string) file_get_contents('php://input', false, null, 0, $max + 1);
        if (\strlen($raw) > $max) {
            throw new ApiException(Text::_('UXS_CORE_ERR_TOO_LARGE'), 413, 'payload_too_large');
        }

        return trim($raw);
    }

    /** Browsers send Origin on cross-site POST / fetch: if it is present it must be this site. Non-browser clients send none. */
    private function checkOrigin($input): void
    {
        $origin = (string) $input->server->get('HTTP_ORIGIN', '', 'string');
        if ($origin === '' || $origin === 'null') {
            return;
        }
        $host = (string) parse_url($origin, PHP_URL_HOST);
        $self = (string) parse_url(\Joomla\CMS\Uri\Uri::root(), PHP_URL_HOST);
        if ($host === '' || strcasecmp($host, $self) !== 0) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }
    }

    private function secret(array $def): string
    {
        $secret = $def['secret'] ?? '';
        $secret = \is_callable($secret) ? $secret() : $secret;

        return \is_string($secret) ? $secret : '';
    }

    private function checkToken($input, array $def): void
    {
        $secret = $this->secret($def);
        $header = (string) $input->server->get('HTTP_AUTHORIZATION', '', 'string');
        if ($header === '') {
            $header = (string) $input->server->get('REDIRECT_HTTP_AUTHORIZATION', '', 'string');
        }
        if ($header === '' && is_callable('getallheaders')) {
            // Some Apache setups hide Authorization from $_SERVER but not from the raw request headers.
            foreach (getallheaders() ?: [] as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = (string) $value;
                }
            }
        }
        $token = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) ? $m[1] : '';
        if ($token === '') {
            // Fallback header for hosts that strip Authorization completely.
            $token = (string) $input->server->get('HTTP_X_UXS_TOKEN', '', 'string');
        }
        if ($secret === '' || $token === '' || !hash_equals($secret, $token)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }
    }

    private function checkHmac($input, array $def, string $raw): void
    {
        $secret    = $this->secret($def);
        $timestamp = (string) $input->server->get('HTTP_X_UXS_TIMESTAMP', '', 'string');
        $signature = strtolower((string) $input->server->get('HTTP_X_UXS_SIGNATURE', '', 'string'));

        if ($secret === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::HMAC_TOLERANCE) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
        }
    }
}

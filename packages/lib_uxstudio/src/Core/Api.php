<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;

\defined('_JEXEC') or die;

/**
 * The single JSON API endpoint:
 *   administrator/index.php?option=com_uxstudio&task=api.dispatch&format=json&route=<module>/<path>
 * Checks (in this order): login, core.manage on com_uxstudio, CSRF token (header X-CSRF-Token),
 * module existence / enabled state, module ACL, HTTP method.
 * Responses: {"ok":true,"data":...} or {"ok":false,"error":{"code","message"}} with a matching HTTP status.
 */
final class Api
{
    public const COMPONENT = 'com_uxstudio';

    public function __construct(private Kernel $kernel)
    {
    }

    /**
     * Runs the whole pipeline.
     *
     * @return array{0:int,1:array<string,mixed>,2:array<string,string>} HTTP status, payload, extra headers
     */
    public function handle(CMSApplicationInterface $app): array
    {
        try {
            Lang::loadCore($app);
            $input = $app->getInput();
            $user  = $app->getIdentity();

            if (!$user || $user->guest) {
                throw new ApiException(Text::_('UXS_CORE_ERR_UNAUTHENTICATED'), 401, 'unauthenticated');
            }
            if (!$user->authorise('core.manage', self::COMPONENT)) {
                throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
            }

            $sent = (string) $input->server->get('HTTP_X_CSRF_TOKEN', '', 'string');
            if ($sent === '' || !hash_equals(Session::getFormToken(), $sent)) {
                throw new ApiException(Text::_('UXS_CORE_ERR_TOKEN'), 403, 'invalid_token');
            }

            $method = strtoupper((string) $input->server->get('REQUEST_METHOD', 'GET', 'string'));
            $route  = trim((string) $input->get('route', '', 'string'), '/');
            if (!preg_match('#^[a-z][a-z0-9-]*(/[A-Za-z0-9_.{}-]+)*$#', $route)) {
                throw new ApiException(Text::_('UXS_CORE_ERR_NOT_FOUND'), 404, 'not_found');
            }

            $this->kernel->migrator()->ensureCore();

            [$target, $path] = array_pad(explode('/', $route, 2), 2, '');
            $registry        = $this->kernel->registry();

            if ($target === 'core') {
                $handlers = (new CoreApi($this->kernel))->routes();
                $acl      = 'core.manage';
            } else {
                $module = $registry->has($target) ? $registry->module($target) : null;
                if (!$module) {
                    throw new ApiException(Text::_('UXS_CORE_ERR_MODULE_UNKNOWN'), 404, 'unknown_module');
                }
                if (!$registry->isEnabled($target)) {
                    throw new ApiException(Text::_('UXS_CORE_ERR_MODULE_DISABLED'), 409, 'module_disabled');
                }
                Lang::loadModule($app, $target, $module->path());
                $handlers = $module->routes();
                $acl      = $module->acl();
            }

            if (!$user->authorise($acl, self::COMPONENT)) {
                throw new ApiException(Text::_('UXS_CORE_ERR_FORBIDDEN'), 403, 'forbidden');
            }

            [$handler, $params, $allowed] = $this->match($handlers, $method, $path);
            if (!$handler) {
                if ($allowed) {
                    throw new ApiException(Text::_('UXS_CORE_ERR_METHOD'), 405, 'method_not_allowed');
                }
                throw new ApiException(Text::_('UXS_CORE_ERR_NOT_FOUND'), 404, 'not_found');
            }

            $request = new ApiRequest($method, $path, $params, $this->query($input), $this->body($input, $method), $user);
            $data    = $handler($request);

            return [200, ['ok' => true, 'data' => $data], []];
        } catch (ApiException $e) {
            $headers = $e->status() === 405 ? ['Allow' => 'GET, POST'] : [];

            return [$e->status() ?: 400, ['ok' => false, 'error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]], $headers];
        } catch (\Throwable $e) {
            Log::add(sprintf('API error: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()), Log::ERROR, 'uxstudio');
            $debug   = \defined('JDEBUG') && JDEBUG;
            $message = $debug ? $e->getMessage() : Text::_('UXS_CORE_ERR_SERVER');

            return [500, ['ok' => false, 'error' => ['code' => 'server_error', 'message' => $message]], []];
        }
    }

    /**
     * @param array<string,callable> $handlers
     * @return array{0:?callable,1:array<string,string>,2:bool} handler, path params, whether the path exists for another method
     */
    private function match(array $handlers, string $method, string $path): array
    {
        $pathExists = false;
        foreach ($handlers as $pattern => $handler) {
            [$m, $p] = array_pad(explode(' ', (string) $pattern, 2), 2, '');
            $regex = '#^' . preg_replace('/\\\{([a-z_]+)\\\}/', '(?P<$1>[^/]+)', preg_quote(trim($p, '/'), '#')) . '$#';
            if (!preg_match($regex, $path, $found)) {
                continue;
            }
            $pathExists = true;
            if (strtoupper($m) === $method) {
                $params = array_filter($found, 'is_string', ARRAY_FILTER_USE_KEY);

                return [$handler, $params, true];
            }
        }

        return [null, [], $pathExists];
    }

    /** @return array<string,mixed> */
    private function query($input): array
    {
        $query = $input->getArray();
        unset($query['option'], $query['task'], $query['format'], $query['route'], $query['view'], $query['controller']);

        return $query;
    }

    /** @return array<string,mixed> */
    private function body($input, string $method): array
    {
        if ($method === 'GET' || $method === 'HEAD') {
            return [];
        }
        $raw = (string) file_get_contents('php://input');
        if (trim($raw) === '') {
            return [];
        }
        $json = json_decode($raw, true);
        if (!\is_array($json)) {
            throw new ApiException(Text::_('UXS_CORE_ERR_BODY'), 400, 'invalid_json');
        }

        return $json;
    }
}

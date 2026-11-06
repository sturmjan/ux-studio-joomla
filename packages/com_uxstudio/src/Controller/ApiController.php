<?php

namespace Ux1\Component\UxStudio\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;
use Ux1\UxStudio\Core\Api;
use Ux1\UxStudio\Core\Kernel;

\defined('_JEXEC') or die;

/**
 * task=api.dispatch - the JSON endpoint of the SPA and of bin/jadmin.mjs. All checks live in Core\Api.
 */
class ApiController extends BaseController
{
    public function dispatch(): void
    {
        $app = $this->app;

        [$status, $payload, $headers] = (new Api(Kernel::instance()))->handle($app);

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

        $app->setHeader('status', (string) $status, true);
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->setHeader('Cache-Control', 'no-store, max-age=0', true);
        $app->setHeader('X-Content-Type-Options', 'nosniff', true);
        foreach ($headers as $name => $value) {
            $app->setHeader($name, $value, true);
        }
        $app->sendHeaders();

        echo json_encode($payload, $flags);
        $app->close();
    }
}

<?php

namespace Ux1\Component\UxStudio\Site\Dispatcher;

use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\PublicApi;

\defined('_JEXEC') or die;

/**
 * Site dispatcher: the only thing the front end of com_uxstudio does is the public JSON endpoint
 * (task=public.dispatch, see Core\PublicApi and BaseModule::publicRoutes()). Everything else is a 404.
 */
class Dispatcher extends ComponentDispatcher
{
    public function dispatch(): void
    {
        $app = $this->app;

        if (!class_exists(Kernel::class) || $app->getInput()->getCmd('task') !== 'public.dispatch') {
            throw new \Joomla\CMS\Router\Exception\RouteNotFoundException('Page not found', 404);
        }

        [$status, $payload, $headers] = (new PublicApi(Kernel::instance()))->handle($app);

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

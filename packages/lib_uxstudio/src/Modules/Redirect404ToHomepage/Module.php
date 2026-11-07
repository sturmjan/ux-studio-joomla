<?php

namespace Ux1\UxStudio\Modules\Redirect404ToHomepage;

use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Redirects 404 pages to the homepage unless another redirect already answered the request: a rule of the UX Studio
 * Redirects module (including its 410 Gone rules, which must never turn into a homepage redirect) or Joomla's own
 * Redirect plugin (it redirects and exits before this listener runs).
 *
 * Mass redirects to the homepage are treated as soft 404s by Google; the module exists for sites that want it anyway.
 */
final class Module extends BaseModule
{
    /** Extensions of static files: a missing image must stay a 404 and not become an HTML homepage. */
    private const ASSET_EXTENSIONS = 'jpe?g|png|gif|webp|avif|svg|ico|bmp|css|js|map|json|xml|txt|woff2?|ttf|eot|otf|mp4|webm|mp3|pdf|zip';

    public function boot(ModuleContext $ctx): void
    {
        // Lowest priority: every other listener (rules, Joomla's Redirect plugin) gets the 404 first.
        if ($ctx->isSite()) {
            $ctx->on('onError', [$this, 'onError'], Priority::MIN);
        }
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key' => 'code', 'type' => 'select', 'label' => 'UXS_REDIRECT_404_TO_HOMEPAGE_CODE', 'help' => 'UXS_REDIRECT_404_TO_HOMEPAGE_CODE_HELP', 'default' => '301',
                'options' => ['301' => 'UXS_REDIRECT_404_TO_HOMEPAGE_CODE_301', '302' => 'UXS_REDIRECT_404_TO_HOMEPAGE_CODE_302'],
            ],
            ['key' => 'skip_assets', 'type' => 'toggle', 'label' => 'UXS_REDIRECT_404_TO_HOMEPAGE_SKIP_ASSETS', 'help' => 'UXS_REDIRECT_404_TO_HOMEPAGE_SKIP_ASSETS_HELP', 'default' => true],
        ];
    }

    public function onError(Event $event): void
    {
        if (!$event instanceof ErrorEvent || (int) $event->getError()->getCode() !== 404) {
            return;
        }
        $ctx   = $this->ctx();
        $app   = $ctx->app();
        $input = $app->getInput();
        $verb  = strtoupper((string) $input->server->getString('REQUEST_METHOD', 'GET'));
        if (($verb !== 'GET' && $verb !== 'HEAD') || $input->getCmd('format', 'html') !== 'html') {
            return;
        }
        if ($this->handledByRedirects()) {
            return;
        }

        $request = $this->request((string) $input->server->get('REQUEST_URI', '/', 'raw'));
        if ($request['path'] === '/' && $request['query'] === '') {
            return; // The homepage itself is missing: redirecting would loop.
        }
        if ((bool) $ctx->setting('skip_assets', true) && preg_match('~\.(' . self::ASSET_EXTENSIONS . ')$~i', $request['path'])) {
            return;
        }

        $code = (string) $ctx->setting('code', '301') === '302' ? 302 : 301;
        $app->redirect(Uri::root(), $code);
    }

    /**
     * Path (relative to the site root, without "/index.php", decoded, lowercase, no trailing slash) and raw query of the
     * request. Local on purpose: this module must not depend on the classes of the Redirects module.
     *
     * @return array{path:string,query:string}
     */
    private function request(string $uri): array
    {
        $parts = parse_url('http://x' . ($uri !== '' && $uri[0] === '/' ? '' : '/') . $uri) ?: [];
        $path  = (string) ($parts['path'] ?? '/');
        $home  = rtrim(Uri::root(true), '/');
        if ($home !== '' && stripos($path, $home) === 0 && (\strlen($path) === \strlen($home) || $path[\strlen($home)] === '/')) {
            $path = substr($path, \strlen($home)) ?: '/';
        }
        $path = (string) preg_replace('#^/index\.php(?=/|$)#i', '', $path);
        $path = '/' . trim((string) preg_replace('~/{2,}~', '/', mb_strtolower(rawurldecode($path), 'UTF-8')), '/');

        return ['path' => $path, 'query' => (string) ($parts['query'] ?? '')];
    }

    /** Whether a rule of the Redirects module already answered this request (redirect or 410). */
    private function handledByRedirects(): bool
    {
        $engine = '\\Ux1\\UxStudio\\Modules\\Redirects\\Engine';

        return class_exists($engine, false) && $engine::handled();
    }
}

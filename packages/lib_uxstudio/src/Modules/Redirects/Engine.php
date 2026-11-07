<?php

namespace Ux1\UxStudio\Modules\Redirects;

use Joomla\CMS\Event\ErrorEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Front-end redirect engine.
 *
 * Two entry points, because Joomla fails in two different places:
 *  - onAfterRoute: the address was routed (a menu item, an article ...). A rule still wins, so a redirect or a 410 can
 *    replace content that exists (the old page was re-used for something else);
 *  - onError (404): the router or the component refused the address. Rules are tried here too, and the 404 is logged.
 *
 * Only plain front-end GET/HEAD requests are touched, never the administrator, the API, the CLI or a POST (a 301 on a
 * POST would drop the body).
 *
 * SEO behaviour: 301/308 are permanent, 302/307 temporary; 410 says the content is gone for good (faster de-indexing
 * than 404), rendered through the template's error page with `noindex`; the visitor's query string (utm_*, gclid) is
 * carried over unless the rule matched on a specific query; permanent redirects get a bounded browser cache (default
 * 1 h) so a wrong rule can still be fixed, a bare 301 is cached by browsers forever.
 */
final class Engine
{
    /** Whether this request was answered by a rule (read by Redirect404ToHomepage). */
    private static bool $handled = false;

    /** Whether the rules were already tried for this request. */
    private static bool $tried = false;

    public function __construct(private ModuleContext $ctx)
    {
    }

    /** Whether a rule handled the current request (redirect or 410). */
    public static function handled(): bool
    {
        return self::$handled;
    }

    public function register(): void
    {
        // Run before everything else: rules beat plugins that react to the same event (language filter, page cache ...).
        $this->ctx->on('onAfterRoute', [$this, 'onAfterRoute'], \Joomla\Event\Priority::MAX);
        $this->ctx->on('onError', [$this, 'onError'], \Joomla\Event\Priority::MAX);
    }

    public function onAfterRoute(Event $event): void
    {
        $this->process();
    }

    public function onError(Event $event): void
    {
        if (!$event instanceof ErrorEvent || !$this->isFrontGet()) {
            return;
        }
        $code = (int) $event->getError()->getCode();
        if ($code !== 404 || self::$handled) {
            return;
        }
        $this->process($event);
        if (!self::$handled && (bool) $this->ctx->setting('log_404', true)) {
            $this->log404();
        }
    }

    /** Matches the request against the rules and acts on the first hit. */
    private function process(?ErrorEvent $error = null): void
    {
        if (self::$tried || self::$handled || !$this->isFrontGet()) {
            return;
        }
        self::$tried = true;

        try {
            $request = $this->request();
            if (Url::isReserved($request['path'])) {
                return;
            }
            $match = Repository::match($request['path'], $request['query'], $request['path_cs']);
        } catch (\Throwable $e) {
            // A broken table must never take the website down.
            Log::add('Redirects: ' . $e->getMessage(), Log::ERROR, 'uxstudio');

            return;
        }
        if (!$match) {
            return;
        }

        $rule = $match['rule'];
        $code = (int) $rule['code'];

        if ((bool) $this->ctx->setting('track_hits', true)) {
            try {
                Repository::recordHit((int) $rule['id']);
            } catch (\Throwable) {
                // Counting is a nicety, the redirect matters more.
            }
        }

        $app = $this->ctx->app();

        if ($code === 410) {
            self::$handled = true;
            $app->setHeader('X-Robots-Tag', 'noindex');
            // Rendered by the template's error page; the exception code becomes the HTTP status.
            $gone = new \RuntimeException(Text::_('UXS_REDIRECTS_GONE_MESSAGE'), 410);
            if ($error !== null) {
                // Already inside the error handler: swap the error instead of throwing out of it.
                $error->setError($gone);

                return;
            }
            throw $gone;
        }

        $target = $match['target'];
        if ($target === '') {
            return;
        }

        // Carry the visitor's query over (campaign tags) unless the rule matched on its own query or the target already
        // defines one. The rule's own query mode wins over the module setting.
        $ruleHasQuery = str_contains((string) $rule['source_key'], '?');
        $rawQuery     = (string) ($this->split($this->requestUri())['query'] ?? '');
        $pass         = self::passesQuery((string) ($rule['query_mode'] ?? ''), (bool) $this->ctx->setting('pass_query', true));
        if ($rawQuery !== '' && !$ruleHasQuery && $rule['match_type'] !== 'regex' && $pass) {
            $target .= (str_contains($target, '?') ? '&' : '?') . $rawQuery;
        }

        // Last-line loop guard: never redirect a URL to itself (a case sensitive rule may send /Foo to /foo).
        $sensitive = !empty($rule['case_sensitive']) && $rule['match_type'] === 'exact';
        $targetKey = Url::targetKey($target, $sensitive);
        if ($targetKey !== '' && Url::key($sensitive ? $request['path_cs'] : $request['path'], $request['query']) === $targetKey) {
            return;
        }

        self::$handled = true;

        if (\in_array($code, [301, 308], true)) {
            $ttl = max(0, (int) $this->ctx->setting('permanent_cache_ttl', 3600));
            if ($ttl > 0) {
                $app->allowCache(true);
                $app->setHeader('Expires', gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT', true);
                $app->setHeader('Cache-Control', 'public, max-age=' . $ttl, true);
            }
        }
        $app->setHeader('X-Redirect-By', 'UX Studio Redirects', true);
        $app->redirect($target, $code);
    }

    /**
     * Whether the visitor's query string is appended to the target: the rule's own query mode wins over the module
     * setting ("pass" always, "ignore" and "exact" never, default / empty follows the setting).
     */
    public static function passesQuery(string $queryMode, bool $setting): bool
    {
        return $queryMode === 'pass' || ($queryMode === '' && $setting);
    }

    private function log404(): void
    {
        try {
            $request = $this->request();
            $input   = $this->ctx->app()->getInput()->server;
            NotFoundLog::record($request['path'], $request['query'], (string) $input->getString('HTTP_REFERER', ''), (string) $input->getString('HTTP_USER_AGENT', ''));
        } catch (\Throwable) {
            // Logging must never break the error page.
        }
    }

    /** Plain site GET/HEAD request. */
    public function isFrontGet(): bool
    {
        $app = $this->ctx->app();
        if (!$app->isClient('site')) {
            return false;
        }
        $method = strtoupper((string) $app->getInput()->server->getString('REQUEST_METHOD', 'GET'));

        return $method === 'GET' || $method === 'HEAD';
    }

    /** @return array{path:string,path_cs:string,query:string} */
    private function request(): array
    {
        return Url::request($this->requestUri());
    }

    private function requestUri(): string
    {
        return (string) $this->ctx->app()->getInput()->server->get('REQUEST_URI', '/', 'raw');
    }

    /** @return array{host:string,path:string,query:string,fragment:string}|null */
    private function split(string $uri): ?array
    {
        return Url::split($uri);
    }
}

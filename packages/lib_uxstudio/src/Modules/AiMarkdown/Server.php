<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\Priority;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Front-end side: answers /llms.txt, /llms-full.txt and /ai-sitemap.md directly, and serves the markdown version of an
 * article or category requested as ?format=md, as a ".md" URL suffix or with "Accept: text/markdown".
 *
 * The virtual files are answered while the module boots (before routing), so they work with and without SEF/rewrite and
 * never turn into a Joomla 404. A ".md" suffix is stripped from the request path before routing, so Joomla resolves the
 * page normally; onAfterRoute then sees which article it is and answers with markdown instead of the template.
 * Only content an anonymous visitor may read is ever served (see Content).
 */
final class Server
{
    private const VIRTUAL = ['llms.txt' => 'llms', 'llms-full.txt' => 'llms-full', 'ai-sitemap.md' => 'sitemap'];

    private bool $suffix = false;

    public function __construct(private ModuleContext $ctx, private Generator $gen, private Content $content, private AccessLog $log)
    {
    }

    private function flag(string $key, bool $default = true): bool
    {
        return filter_var($this->ctx->setting($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function register(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method !== 'GET' && $method !== 'HEAD') {
            return;
        }

        $rel = $this->relativePath();
        if (isset(self::VIRTUAL[$rel])) {
            $this->serveVirtual(self::VIRTUAL[$rel]);
        } elseif ($this->flag('enable_markdown') && \strlen($rel) > 3 && strtolower(substr($rel, -3)) === '.md') {
            // Resolve the page without the suffix, onAfterRoute answers it.
            $uri = Uri::getInstance();
            $uri->setPath(substr($uri->getPath(), 0, -3));
            $this->suffix = true;
        }

        $this->ctx->on('onAfterRoute', [$this, 'onAfterRoute'], Priority::MAX);
        $this->ctx->on('onBeforeCompileHead', [$this, 'onBeforeCompileHead']);
    }

    /** Request path below the site root, without a leading "index.php/". */
    private function relativePath(): string
    {
        $path = rawurldecode((string) Uri::getInstance()->getPath());
        $base = Uri::base(true);
        if ($base !== '' && str_starts_with($path, $base . '/')) {
            $path = substr($path, \strlen($base));
        } elseif ($base !== '' && $path === $base) {
            $path = '';
        }
        $path = trim($path, '/');
        if (str_starts_with($path, 'index.php/')) {
            $path = substr($path, 10);
        }

        return $path;
    }

    // ---- Virtual files --------------------------------------------------------------------------------------------

    private function serveVirtual(string $kind): void
    {
        $enabled = match ($kind) {
            'llms'      => $this->flag('enable_llms_txt'),
            'llms-full' => $this->flag('enable_llms_full'),
            default     => $this->flag('enable_sitemap'),
        };
        if (!$enabled) {
            return;
        }
        try {
            $doc = $this->gen->aggregate($kind);
        } catch (RateLimited $e) {
            $this->tooMany($e->retryAfter);

            return;
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('ai-markdown: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'uxstudio');

            return;
        }
        $type = $kind === 'sitemap' ? 'text/markdown' : 'text/plain';
        $name = array_search($kind, self::VIRTUAL, true);
        $this->record(str_replace('-', '_', $kind), 0, 'file');
        $this->respond(200, $doc['content'], $type);
    }

    // ---- Articles and categories ----------------------------------------------------------------------------------

    public function onAfterRoute(Event $event): void
    {
        $app = $this->ctx->app();
        if (!$this->ctx->isSite()) {
            return;
        }
        $input    = $app->getInput();
        $format   = strtolower((string) $input->getCmd('format', ''));
        $explicit = $this->suffix || $format === 'md' || $format === 'markdown';
        $option   = (string) $input->getCmd('option', '');
        $view     = (string) $input->getCmd('view', '');
        $method   = strtoupper((string) $input->getMethod());

        if (!$this->flag('enable_markdown') || ($method !== 'GET' && $method !== 'HEAD')) {
            return;
        }
        $negotiated = !$explicit && $this->flag('enable_accept') && $this->wantsMarkdown();
        $isContent  = $option === 'com_content' && ($view === 'article' || $view === 'category') && $input->getInt('id', 0) > 0;

        if (!$explicit && !$negotiated) {
            if ($isContent && $this->flag('enable_accept')) {
                // The same URL can answer with HTML or markdown, caches must keep them apart.
                $app->setHeader('Vary', 'Accept', false);
            }

            return;
        }
        if (!$isContent) {
            if ($explicit) {
                $this->notFound();
            }

            return;
        }

        $id  = $input->getInt('id', 0);
        $via = $this->suffix ? 'suffix' : ($negotiated ? 'accept' : 'query');
        try {
            $doc = $view === 'article' ? $this->gen->article($id) : $this->gen->category($id);
        } catch (RateLimited $e) {
            $this->tooMany($e->retryAfter);

            return;
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('ai-markdown: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'uxstudio');
            $doc = null;
        }
        if (!$doc) {
            if ($explicit) {
                $this->notFound();
            }

            return;
        }

        $this->record($view, $id, $via);
        $row       = $view === 'article' ? $this->content->article($id) : null;
        $canonical = $row ? $this->content->articleUrl($row) : '';
        $extra = [];
        if ($canonical !== '') {
            $extra['Link'] = '<' . $canonical . '>; rel="canonical"';
        }
        if ($negotiated) {
            $extra['Vary'] = 'Accept';
        }
        $this->respond(200, $doc['content'], 'text/markdown', $extra);
    }

    /** True when the client prefers text/markdown over HTML (browsers never send it). */
    private function wantsMarkdown(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        if ($accept === '' || stripos($accept, 'markdown') === false) {
            return false;
        }
        $md = 0.0;
        $html = 0.0;
        $star = 0.0;
        foreach (explode(',', $accept) as $part) {
            $bits = array_map('trim', explode(';', $part));
            $type = strtolower(array_shift($bits));
            $q    = 1.0;
            foreach ($bits as $b) {
                if (preg_match('/^q=([0-9.]+)$/i', $b, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($type === 'text/markdown') {
                $md = max($md, $q);
            } elseif ($type === 'text/html' || $type === 'application/xhtml+xml') {
                $html = max($html, $q);
            } elseif ($type === '*/*' || $type === 'text/*') {
                $star = max($star, $q);
            }
        }

        return $md > 0 && $md >= max($html, $star);
    }

    public function onBeforeCompileHead(Event $event): void
    {
        try {
            $app = $this->ctx->app();
            $doc = $app->getDocument();
            if (!$doc instanceof HtmlDocument || !$this->flag('enable_markdown') || !$this->flag('advertise_alternate')) {
                return;
            }
            $input = $app->getInput();
            if ($input->getCmd('option') !== 'com_content' || $input->getCmd('view') !== 'article' || $input->getInt('id') < 1) {
                return;
            }
            $page = Uri::current();
            $href = str_ends_with($page, 'index.php')
                ? $page . '?option=com_content&view=article&id=' . $input->getInt('id') . '&format=md'
                : $this->content->markdownUrl($page);
            $doc->addHeadLink($href, 'alternate', 'rel', ['type' => 'text/markdown']);
        } catch (\Throwable) {
            // A head link is a nicety.
        }
    }

    // ---- Output ---------------------------------------------------------------------------------------------------

    /** Logs a bot hit with the full requested URL (query string included). */
    private function record(string $kind, int $refId, string $via): void
    {
        if ($this->flag('enable_logging')) {
            $this->log->record($kind, $refId, Uri::getInstance()->toString(['scheme', 'host', 'port', 'path', 'query']), $via);
        }
    }

    private function tooMany(int $retryAfter): void
    {
        $this->respond(429, "# Too many requests

Try again later.
", 'text/markdown', ['Retry-After' => (string) max(1, $retryAfter)]);
    }

    private function notFound(): void
    {
        $this->respond(404, "# Not found\n\nThere is no markdown version of this address.\n", 'text/markdown');
    }

    /** @param array<string,string> $extra */
    private function respond(int $status, string $body, string $type, array $extra = []): void
    {
        $etag = '"' . md5($body) . '"';
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if ($status === 200 && isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string) $_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            header_remove('Set-Cookie');
            http_response_code(304);
            header('ETag: ' . $etag);
            $this->ctx->app()->close();
        }
        // A public document must not set a session cookie (it would also stop shared caches from storing it).
        header_remove('Set-Cookie');
        http_response_code($status);
        header('Content-Type: ' . $type . '; charset=utf-8');
        header('X-Robots-Tag: noindex');
        header('X-Content-Type-Options: nosniff');
        if ($status === 200) {
            header('ETag: ' . $etag);
            header('Cache-Control: public, max-age=300');
        } else {
            header('Cache-Control: no-store');
        }
        foreach ($extra as $name => $value) {
            header($name . ': ' . str_replace(["\r", "\n"], '', $value), $name !== 'Vary');
        }
        header('Content-Length: ' . \strlen($body));
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo $body;
        }
        $this->ctx->app()->close();
    }
}

<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/** Administrator routes of the AI Markdown module. */
final class Api
{
    private Content $content;

    private Cache $cache;

    private AccessLog $log;

    public function __construct(private ModuleContext $ctx, private Generator $gen)
    {
        $this->content = new Content($ctx);
        $this->cache   = new Cache($ctx->db());
        $this->log     = new AccessLog($ctx->db());
    }

    private function flag(string $key): bool
    {
        return filter_var($this->ctx->setting($key, true), FILTER_VALIDATE_BOOLEAN);
    }

    /** GET ai-markdown/status */
    public function status(?ApiRequest $r = null): array
    {
        // An administrator is on the real host: remember it as the root that documents may be cached for.
        try {
            $this->content->rememberRoot();
        } catch (\Throwable) {
            // Not essential.
        }

        return [
            'urls'       => [
                'llms_txt'  => $this->flag('enable_llms_txt') ? $this->content->virtualUrl('llms.txt') : '',
                'llms_full' => $this->flag('enable_llms_full') ? $this->content->virtualUrl('llms-full.txt') : '',
                'sitemap'   => $this->flag('enable_sitemap') ? $this->content->virtualUrl('ai-sitemap.md') : '',
            ],
            'rewrite'    => $this->content->rewriteActive(),
            'markdown'   => $this->flag('enable_markdown'),
            'accept'     => $this->flag('enable_markdown') && $this->flag('enable_accept'),
            'public'     => $this->content->eligibleCount(),
            'cached'     => $this->cache->count(),
            'log'        => $this->log->stats(),
            'link_style' => (string) $this->ctx->setting('link_style', 'query'),
        ];
    }

    /** GET ai-markdown/articles?page=&per_page=&search= : public articles with their cache state. */
    public function articles(ApiRequest $r): array
    {
        $db      = $this->ctx->db();
        $page    = max(1, $r->int('page', 1));
        $perPage = max(1, min(100, $r->int('per_page', 20)));
        $search  = trim($r->string('search'));
        [$slice, $total] = $this->content->articlePage(($page - 1) * $perPage, $perPage, $search);
        $ids   = array_map(static fn (array $a): int => (int) $a['id'], $slice);
        $state = [];
        if ($ids) {
            $q = $db->createQuery()->select($db->quoteName(['ref_id', 'generated_at']))->from($db->quoteName('#__uxstudio_ai_markdown_cache'))
                ->where($db->quoteName('kind') . ' = ' . $db->quote('article'))->whereIn($db->quoteName('ref_id'), $ids);
            foreach ($db->setQuery($q)->loadAssocList() ?: [] as $c) {
                $state[(int) $c['ref_id']] = (string) $c['generated_at'];
            }
        }
        $items = [];
        foreach ($slice as $a) {
            $url     = $this->content->articleUrl($a);
            $items[] = [
                'id'           => (int) $a['id'],
                'title'        => (string) $a['title'],
                'category'     => (string) $a['cat_title'],
                'url'          => $url,
                'md_url'       => $this->content->markdownUrl($url),
                'generated_at' => $state[(int) $a['id']] ?? null,
            ];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** GET ai-markdown/preview?kind=llms|llms-full|sitemap  or  ?id=<article id> : the generated document. */
    public function preview(ApiRequest $r): array
    {
        $kind = $r->string('kind');
        if ($kind !== '') {
            if (!\in_array($kind, ['llms', 'llms-full', 'sitemap'], true)) {
                throw new ApiException('Unknown document.', 422, 'invalid_value');
            }
            $doc = $this->gen->aggregate($kind);
        } else {
            $id  = $r->int('id', 0);
            $doc = $id > 0 ? $this->gen->article($id) : null;
            if (!$doc) {
                throw new ApiException('This article is not public, so it has no markdown version.', 404, 'not_found');
            }
        }

        return ['content' => $doc['content'], 'generated_at' => $doc['generated_at'], 'bytes' => \strlen($doc['content'])];
    }

    /** POST ai-markdown/regenerate/{id} */
    public function regenerate(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        if ($id < 1 || !$this->gen->article($id, true)) {
            throw new ApiException('This article is not public, so it has no markdown version.', 404, 'not_found');
        }
        $this->cache->forget(null, true);

        return ['id' => $id];
    }

    /** POST ai-markdown/regenerate-all */
    public function regenerateAll(ApiRequest $r): array
    {
        // Converting every article in one request can exceed max_execution_time: drop the cache and let the
        // scheduler job rebuild it in the background (documents also rebuild lazily on the first request).
        $this->cache->forget();
        $this->ctx->runJobSoon('refresh');

        return ['regenerated' => $this->content->eligibleCount(), 'queued' => true];
    }

    /** POST ai-markdown/cache/clear : empties the cache, documents are rebuilt on the next request. */
    public function clearCache(ApiRequest $r): array
    {
        $this->cache->forget();

        return ['cleared' => true];
    }

    /** GET ai-markdown/log?page=&per_page=&search=&bot=&kind= */
    public function log(ApiRequest $r): array
    {
        return $this->log->list([
            'search' => mb_substr($r->string('search'), 0, 100),
            'bot'    => mb_substr($r->string('bot'), 0, 100),
            'kind'   => preg_replace('/[^a-z_]/', '', $r->string('kind')) ?? '',
        ], $r->int('page', 1), $r->int('per_page', 30));
    }

    public function logStats(ApiRequest $r): array
    {
        return $this->log->stats();
    }

    /** POST ai-markdown/log/delete {ids:[]} */
    public function logDelete(ApiRequest $r): array
    {
        $ids = $r->input('ids', []);

        return ['deleted' => $this->log->delete(\is_array($ids) ? $ids : [])];
    }

    /** POST ai-markdown/log/clear (core.admin) */
    public function logClear(ApiRequest $r): array
    {
        return ['deleted' => $this->log->clear()];
    }

    /** GET ai-markdown/robots?policy=allow_all|block_training|block_all */
    public function robots(ApiRequest $r): array
    {
        $policy = $r->string('policy', 'allow_all');
        if (!\in_array($policy, Robots::POLICIES, true)) {
            throw new ApiException('Unknown policy.', 422, 'invalid_value');
        }

        return Robots::analyze() + ['policy' => $policy, 'snippet' => Robots::snippet($policy)];
    }

    /** Payload of the Central App feed. */
    public function feed(): array
    {
        $s = $this->status();

        return [
            'endpoints'    => $s['urls'],
            'markdown'     => $s['markdown'],
            'public_items' => $s['public'],
            'cached_items' => $s['cached'],
            'bot_requests' => ['total' => $s['log']['total'], 'last_24h' => $s['log']['last_24h'], 'by_bot' => $s['log']['bots']],
        ];
    }
}

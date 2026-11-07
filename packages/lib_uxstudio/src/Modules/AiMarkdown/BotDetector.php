<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

\defined('_JEXEC') or die;

/**
 * Maps a user agent to a known AI / search bot. Only requests from these bots are written to the access log.
 * "purpose": training = collects content for model training, search = fetches pages to answer a user or build an AI search index.
 */
final class BotDetector
{
    /**
     * User agent token (case-insensitive substring) => [name, purpose]. First match wins, so a specific token
     * (Applebot-Extended) must come before the generic one (Applebot).
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const BOTS = [
        'GPTBot'            => ['OpenAI GPTBot', 'training'],
        'ChatGPT-User'      => ['ChatGPT', 'search'],
        'OAI-SearchBot'     => ['OpenAI Search', 'search'],
        'ClaudeBot'         => ['Anthropic Claude', 'training'],
        'Claude-Web'        => ['Anthropic Claude', 'search'],
        'Claude-User'       => ['Anthropic Claude', 'search'],
        'PerplexityBot'     => ['Perplexity', 'search'],
        'Perplexity-User'   => ['Perplexity', 'search'],
        'Google-Extended'   => ['Google AI', 'training'],
        'Googlebot'         => ['Google', 'search'],
        'Bingbot'           => ['Bing', 'search'],
        'Bytespider'        => ['ByteDance', 'training'],
        'CCBot'             => ['Common Crawl', 'training'],
        'FacebookBot'       => ['Meta AI', 'training'],
        'Meta-ExternalAgent' => ['Meta AI', 'training'],
        'cohere-ai'         => ['Cohere', 'training'],
        'Applebot-Extended' => ['Apple AI', 'training'],
        'Applebot'          => ['Apple', 'search'],
        'YouBot'            => ['You.com', 'search'],
        'Diffbot'           => ['Diffbot', 'training'],
        'ImagesiftBot'      => ['Imagesift', 'training'],
        'Amazonbot'         => ['Amazon', 'search'],
    ];

    /** Bot name for a user agent, or null when it is not a known bot. */
    public static function detect(string $userAgent): ?string
    {
        if ($userAgent === '') {
            return null;
        }
        foreach (self::BOTS as $token => $info) {
            if (stripos($userAgent, $token) !== false) {
                return $info[0];
            }
        }

        return null;
    }
}

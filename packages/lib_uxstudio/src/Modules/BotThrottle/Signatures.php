<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

\defined('_JEXEC') or die;

/**
 * Bot signature database: categories, User-Agent patterns and the reverse-DNS suffixes of the engines that can be verified.
 * Search engines are never blocked (deindexing risk); AI training crawlers and SEO tools are throttled hard by default.
 */
final class Signatures
{
    /**
     * Category id => [label (language key), default_action, bots => [[name, pattern]]]. A pattern is a case-insensitive
     * regex fragment matched against the User-Agent.
     *
     * @return array<string,array{label:string,default_action:string,bots:array<int,array{name:string,pattern:string}>}>
     */
    public static function categories(): array
    {
        return [
            'search_engines' => [
                'label' => 'UXS_BOT_THROTTLE_CAT_SEARCH_ENGINES', 'default_action' => 'pass_with_min_delay',
                'bots' => [
                    ['name' => 'Googlebot', 'pattern' => '(?:^|[^a-z])Googlebot(?:[^a-z]|$)'],
                    ['name' => 'Googlebot-Image', 'pattern' => 'Googlebot-Image'],
                    ['name' => 'Googlebot-News', 'pattern' => 'Googlebot-News'],
                    ['name' => 'Googlebot-Video', 'pattern' => 'Googlebot-Video'],
                    ['name' => 'Bingbot', 'pattern' => 'bingbot'],
                    ['name' => 'Slurp', 'pattern' => 'Slurp'],
                    ['name' => 'DuckDuckBot', 'pattern' => 'DuckDuckBot'],
                    ['name' => 'Baiduspider', 'pattern' => 'Baiduspider'],
                    ['name' => 'YandexBot', 'pattern' => 'YandexBot'],
                    ['name' => 'SeznamBot', 'pattern' => 'SeznamBot'],
                    ['name' => 'Applebot', 'pattern' => 'Applebot(?!-Extended)'],
                    ['name' => 'Sogou', 'pattern' => 'Sogou'],
                    ['name' => 'Naver', 'pattern' => 'Yeti.*NaverBot|Naverbot'],
                ],
            ],
            'ai_assistants' => [
                'label' => 'UXS_BOT_THROTTLE_CAT_AI_ASSISTANTS', 'default_action' => 'throttle_light',
                'bots' => [
                    ['name' => 'ChatGPT-User', 'pattern' => 'ChatGPT-User'],
                    ['name' => 'OAI-SearchBot', 'pattern' => 'OAI-SearchBot'],
                    ['name' => 'PerplexityBot', 'pattern' => 'PerplexityBot'],
                    ['name' => 'Perplexity-User', 'pattern' => 'Perplexity-User'],
                    ['name' => 'Claude-User', 'pattern' => 'Claude-User'],
                    ['name' => 'Claude-SearchBot', 'pattern' => 'Claude-SearchBot'],
                    ['name' => 'YouBot', 'pattern' => 'YouBot'],
                    ['name' => 'Phindbot', 'pattern' => 'Phindbot'],
                ],
            ],
            'ai_training' => [
                'label' => 'UXS_BOT_THROTTLE_CAT_AI_TRAINING', 'default_action' => 'throttle_aggressive',
                'bots' => [
                    ['name' => 'GPTBot', 'pattern' => 'GPTBot'],
                    ['name' => 'ClaudeBot', 'pattern' => 'ClaudeBot'],
                    ['name' => 'Claude-Web', 'pattern' => 'Claude-Web'],
                    ['name' => 'anthropic-ai', 'pattern' => 'anthropic-ai'],
                    ['name' => 'CCBot', 'pattern' => 'CCBot'],
                    ['name' => 'Google-Extended', 'pattern' => 'Google-Extended'],
                    ['name' => 'Applebot-Extended', 'pattern' => 'Applebot-Extended'],
                    ['name' => 'Bytespider', 'pattern' => 'Bytespider'],
                    ['name' => 'Diffbot', 'pattern' => 'Diffbot'],
                    ['name' => 'FacebookBot', 'pattern' => 'FacebookBot|meta-externalagent'],
                    ['name' => 'ImagesiftBot', 'pattern' => 'ImagesiftBot'],
                    ['name' => 'Omgili', 'pattern' => 'Omgili|omgilibot'],
                    ['name' => 'Timpibot', 'pattern' => 'Timpibot'],
                    ['name' => 'Cohere-AI', 'pattern' => 'cohere-ai'],
                    ['name' => 'Amazonbot', 'pattern' => 'Amazonbot'],
                ],
            ],
            'seo_tools' => [
                'label' => 'UXS_BOT_THROTTLE_CAT_SEO_TOOLS', 'default_action' => 'throttle_aggressive',
                'bots' => [
                    ['name' => 'AhrefsBot', 'pattern' => 'AhrefsBot'],
                    ['name' => 'SemrushBot', 'pattern' => 'SemrushBot'],
                    ['name' => 'MJ12bot', 'pattern' => 'MJ12bot'],
                    ['name' => 'DotBot', 'pattern' => 'DotBot'],
                    ['name' => 'BLEXBot', 'pattern' => 'BLEXBot'],
                    ['name' => 'SerpstatBot', 'pattern' => 'SerpstatBot'],
                    ['name' => 'rogerbot', 'pattern' => 'rogerbot'],
                    ['name' => 'PetalBot', 'pattern' => 'PetalBot'],
                    ['name' => 'DataForSeoBot', 'pattern' => 'DataForSeoBot'],
                ],
            ],
            'social' => [
                'label' => 'UXS_BOT_THROTTLE_CAT_SOCIAL', 'default_action' => 'pass_with_min_delay',
                'bots' => [
                    ['name' => 'facebookexternalhit', 'pattern' => 'facebookexternalhit'],
                    ['name' => 'Twitterbot', 'pattern' => 'Twitterbot'],
                    ['name' => 'LinkedInBot', 'pattern' => 'LinkedInBot'],
                    ['name' => 'Slackbot', 'pattern' => 'Slackbot'],
                    ['name' => 'Discordbot', 'pattern' => 'Discordbot'],
                    ['name' => 'TelegramBot', 'pattern' => 'TelegramBot'],
                    ['name' => 'WhatsApp', 'pattern' => 'WhatsApp'],
                    ['name' => 'Pinterestbot', 'pattern' => 'Pinterestbot'],
                ],
            ],
            'archivers' => [
                'label' => 'UXS_BOT_THROTTLE_CAT_ARCHIVERS', 'default_action' => 'throttle_light',
                'bots' => [
                    ['name' => 'ia_archiver', 'pattern' => 'ia_archiver'],
                    ['name' => 'archive.org_bot', 'pattern' => 'archive\.org_bot'],
                    ['name' => 'Wayback', 'pattern' => 'Wayback'],
                ],
            ],
            'generic_crawlers' => [
                'label' => 'UXS_BOT_THROTTLE_CAT_GENERIC', 'default_action' => 'throttle_aggressive',
                'bots' => [
                    ['name' => 'generic-bot', 'pattern' => '(?:^|\s)(?:bot|crawler|spider|scraper)(?:\s|$|/)'],
                ],
            ],
        ];
    }

    /** Reverse-DNS suffixes of engines that can be verified. A UA claiming to be one of them from another host is spoofed. @return array<string,string[]> */
    public static function verifiableRdns(): array
    {
        return [
            'Googlebot'   => ['.googlebot.com', '.google.com'],
            'Bingbot'     => ['.search.msn.com'],
            'YandexBot'   => ['.yandex.ru', '.yandex.net', '.yandex.com'],
            'DuckDuckBot' => ['.duckduckgo.com'],
            'Applebot'    => ['.applebot.apple.com'],
            'SeznamBot'   => ['.seznam.cz'],
        ];
    }
}

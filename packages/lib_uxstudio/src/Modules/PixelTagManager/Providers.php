<?php

namespace Ux1\UxStudio\Modules\PixelTagManager;

\defined('_JEXEC') or die;

/**
 * Catalogue of supported tracking providers: setting key, ID format, consent category and the hosts a Content
 * Security Policy has to allow. IDs are validated strictly because they end up in a script URL / init call.
 */
final class Providers
{
    /** @return array<string,array{label:string,pattern:string,category:string,hosts:string[]}> */
    public static function all(): array
    {
        return [
            'ga4'       => ['label' => 'Google Analytics 4', 'pattern' => '/^(G|GT|UA)-[A-Z0-9-]{4,30}$/', 'category' => 'analytics', 'hosts' => ['www.googletagmanager.com', '*.google-analytics.com', '*.analytics.google.com', '*.googletagmanager.com']],
            'clarity'   => ['label' => 'Microsoft Clarity', 'pattern' => '/^[a-z0-9]{8,12}$/i', 'category' => 'analytics', 'hosts' => ['*.clarity.ms', 'c.bing.com']],
            'ads'       => ['label' => 'Google Ads', 'pattern' => '/^AW-[0-9]{6,15}$/', 'category' => 'marketing', 'hosts' => ['www.googletagmanager.com', 'www.google.com', 'www.googleadservices.com', '*.g.doubleclick.net']],
            'gtm'       => ['label' => 'Google Tag Manager', 'pattern' => '/^GTM-[A-Z0-9]{4,10}$/', 'category' => 'marketing', 'hosts' => ['www.googletagmanager.com']],
            'meta'      => ['label' => 'Meta Pixel', 'pattern' => '/^[0-9]{5,20}$/', 'category' => 'marketing', 'hosts' => ['connect.facebook.net', 'www.facebook.com']],
            'pinterest' => ['label' => 'Pinterest Tag', 'pattern' => '/^[0-9]{5,20}$/', 'category' => 'marketing', 'hosts' => ['s.pinimg.com', 'ct.pinterest.com']],
            'seznam'    => ['label' => 'Seznam retargeting (Sklik)', 'pattern' => '/^[0-9]{3,12}$/', 'category' => 'marketing', 'hosts' => ['c.seznam.cz', 'h.seznam.cz']],
        ];
    }

    /** Setting key of the ID field of a provider. */
    public static function settingKey(string $provider): string
    {
        return $provider . '_id';
    }

    /** Normalised ID of the provider or '' when empty/invalid. */
    public static function clean(string $provider, mixed $raw): string
    {
        $def = self::all()[$provider] ?? null;
        if (!$def || !\is_string($raw)) {
            return '';
        }
        $id = trim($raw);
        if (\in_array($provider, ['ga4', 'ads', 'gtm'], true)) {
            $id = strtoupper($id);
        }

        return preg_match($def['pattern'], $id) === 1 ? $id : '';
    }
}

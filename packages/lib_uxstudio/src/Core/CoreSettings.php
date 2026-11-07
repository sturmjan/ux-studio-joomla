<?php

namespace Ux1\UxStudio\Core;

\defined('_JEXEC') or die;

/**
 * Settings of the core itself (module id "core" in #__uxstudio_settings, edited on the "Settings" screen of UX Studio).
 * Read them with CoreSettings::get('client_ip_mode').
 */
final class CoreSettings
{
    public const ID = 'core';

    /** Legacy location (settings of the security-optimization module before the resolver moved to the core). */
    private const LEGACY = [
        'client_ip_mode'  => ['security-optimization', 'ip_firewall_proxy'],
        'trusted_proxies' => ['security-optimization', 'ip_trusted_proxies'],
    ];

    /** @return array<int,array<string,mixed>> */
    public static function schema(): array
    {
        return [
            ['key' => 'client_ip_mode', 'type' => 'select', 'label' => 'UXS_CORE_SET_IP_MODE', 'help' => 'UXS_CORE_SET_IP_MODE_HELP', 'default' => 'cloudflare',
             'section' => 'UXS_CORE_SET_SEC_NETWORK', 'options' => [
                 'none'       => 'UXS_CORE_SET_IP_NONE',
                 'cloudflare' => 'UXS_CORE_SET_IP_CLOUDFLARE',
                 'xff'        => 'UXS_CORE_SET_IP_XFF',
             ]],
            ['key' => 'trusted_proxies', 'type' => 'textarea', 'label' => 'UXS_CORE_SET_TRUSTED', 'help' => 'UXS_CORE_SET_TRUSTED_HELP', 'default' => '', 'maxlength' => 2000,
             'section' => 'UXS_CORE_SET_SEC_NETWORK', 'show_if' => ['key' => 'client_ip_mode', 'in' => ['cloudflare', 'xff']]],
        ];
    }

    /** One core setting; never throws (before the first migration the default is returned). */
    public static function get(string $key, mixed $default = null): mixed
    {
        try {
            $settings = Kernel::instance()->settings();
            if (!$settings->hasStored(self::ID, $key) && isset(self::LEGACY[$key]) && $settings->hasStored(self::LEGACY[$key][0], self::LEGACY[$key][1])) {
                return $settings->storedValue(self::LEGACY[$key][0], self::LEGACY[$key][1]);
            }

            return $settings->get(self::ID, $key, $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }
}

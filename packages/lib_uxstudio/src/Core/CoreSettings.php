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

    /**
     * One-time copy of the legacy security-optimization IP settings into the core document (core migration 4).
     * Existing core values are never overwritten. Works on raw rows so it does not depend on the Kernel.
     */
    public static function migrateLegacy(\Joomla\Database\DatabaseInterface $db): void
    {
        try {
            $read = static function (string $module) use ($db): ?array {
                $json = $db->setQuery($db->createQuery()->select($db->quoteName('data'))->from($db->quoteName('#__uxstudio_settings'))
                    ->where($db->quoteName('module') . ' = :m')->bind(':m', $module))->loadResult();
                $data = json_decode((string) $json, true);

                return \is_array($data) ? $data : null;
            };
            $core   = $read(self::ID);
            $next   = $core ?? [];
            $copied = false;
            foreach (self::LEGACY as $key => [$module, $legacyKey]) {
                $old = $read($module);
                if ($old !== null && \array_key_exists($legacyKey, $old) && !\array_key_exists($key, $next)) {
                    $next[$key] = $old[$legacyKey];
                    $copied     = true;
                }
            }
            if (!$copied) {
                return;
            }
            $json = json_encode($next, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $now  = \Joomla\CMS\Factory::getDate()->toSql();
            $id   = self::ID;
            if ($core !== null) {
                $q = $db->createQuery()->update($db->quoteName('#__uxstudio_settings'))->set($db->quoteName('data') . ' = :d')->set($db->quoteName('updated_at') . ' = :ts')
                    ->where($db->quoteName('module') . ' = :m')->bind(':d', $json)->bind(':ts', $now)->bind(':m', $id);
            } else {
                $q = $db->createQuery()->insert($db->quoteName('#__uxstudio_settings'))->columns($db->quoteName(['module', 'data', 'updated_at']))
                    ->values(':m, :d, :ts')->bind(':m', $id)->bind(':d', $json)->bind(':ts', $now);
            }
            $db->setQuery($q)->execute();
        } catch (\Throwable $e) {
            // Best effort; the read fallback in get() still covers an unmigrated site.
        }
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

<?php

namespace Ux1\UxStudio\Modules\BotThrottle;

use Joomla\CMS\Factory;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * Reusable IP burst guard for other modules that protect a narrow endpoint (a beacon, a form, an import hook), so they do
 * not need their own rate-limit / ban / notify plumbing:
 *
 *   if (\Ux1\UxStudio\Modules\BotThrottle\Guard::exceeded('analytics_hit', 30, 10, 900)) { return http 429; }
 *
 * Once an address trips the burst limit it is banned for $banFor seconds in that context; further calls short-circuit on a
 * single indexed read. Respects the module: when Bot Throttle is turned off in UX1 Studio, nothing here acts.
 */
final class Guard
{
    /**
     * @param string $context     caller defined identifier, e.g. 'analytics_hit' (bans are scoped to it)
     * @param int    $burstMax    max calls within the window
     * @param int    $burstWindow window length in seconds
     * @param int    $banFor      ban length in seconds
     * @return bool true when the address is (now or already) banned
     */
    public static function exceeded(string $context, int $burstMax, int $burstWindow, int $banFor): bool
    {
        try {
            $kernel = Kernel::instance();
            if (!$kernel->registry()->isEnabled('bot-throttle')) {
                return false;
            }
            $module = $kernel->registry()->module('bot-throttle');
            if (!$module instanceof Module) {
                return false;
            }

            return $module->burst($context, $burstMax, $burstWindow, $banFor);
        } catch (\Throwable $e) {
            // A guard that fails must not take the protected endpoint down with it.
            return false;
        }
    }

    /** Hash helper shared with Module (keyed with the site secret, never the raw address). */
    public static function hash(string $ip): string
    {
        return hash_hmac('sha256', $ip, (string) Factory::getApplication()->get('secret', 'uxs'));
    }

    public static function limiter(): RateLimiter
    {
        return new RateLimiter(Kernel::instance()->db());
    }
}

<?php

namespace Ux1\UxStudio\Modules\Analytics;

use Ux1\UxStudio\Core\ClientIp as SharedClientIp;

\defined('_JEXEC') or die;

/**
 * Client IP for visitor hashing. REMOTE_ADDR by default. When the admin confirms the site runs behind a proxy / CDN,
 * the shared resolver of the Security module is used (same proxy mode and trusted list as the firewall and BotThrottle):
 * forwarded headers are honoured only when the TCP peer is a Cloudflare edge or a listed proxy, and X-Forwarded-For is
 * walked from the right, so a client cannot spoof its address.
 */
final class ClientIp
{
    public static function get(bool $trustProxy): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        if (!$trustProxy) {
            return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '';
        }

        return SharedClientIp::current();
    }
}

<?php

namespace Ux1\UxStudio\Modules\SecurityOptimization;

use Ux1\UxStudio\Core\ClientIp as CoreClientIp;

\defined('_JEXEC') or die;

/**
 * Thin alias kept for compatibility: the resolver moved to Core\ClientIp (shared by PublicApi, RateLimiter and all modules).
 */
final class ClientIp extends CoreClientIp
{
}

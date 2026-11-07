<?php

namespace Ux1\UxStudio\Modules\AiMarkdown;

\defined('_JEXEC') or die;

/** Thrown when a public request would rebuild a document too often (see Generator::cached). */
final class RateLimited extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Too many requests.');
    }
}

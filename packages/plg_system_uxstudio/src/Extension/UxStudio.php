<?php

namespace Ux1\Plugin\System\UxStudio\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Ux1\UxStudio\Core\Kernel;

\defined('_JEXEC') or die;

/**
 * Boots the UX1 Studio kernel (and through it every enabled module) on every request.
 */
final class UxStudio extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return ['onAfterInitialise' => 'onAfterInitialise'];
    }

    public function onAfterInitialise(Event $event): void
    {
        // The library is a separate extension: never fatal when it was removed.
        if (!class_exists(Kernel::class)) {
            return;
        }

        Kernel::instance()->boot($this->getApplication());
    }
}

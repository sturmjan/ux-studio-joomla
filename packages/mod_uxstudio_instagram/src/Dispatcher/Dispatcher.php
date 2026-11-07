<?php

namespace Ux1\Module\UxStudioInstagram\Site\Dispatcher;

use Joomla\CMS\Dispatcher\AbstractModuleDispatcher;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Modules\InstagramFeed\Module as InstagramFeed;

\defined('_JEXEC') or die;

/**
 * Front-end module of the UX Studio "Instagram Feed": posts, defaults and the connection come from UX Studio (module
 * instagram-feed); the module parameters only override what differs for this position.
 */
class Dispatcher extends AbstractModuleDispatcher
{
    protected function getLayoutData(): array
    {
        $data         = parent::getLayoutData();
        $data['html'] = '';

        // UX Studio library missing or the Instagram Feed is switched off: render nothing.
        if (!class_exists(Kernel::class)) {
            return $data;
        }
        $registry = Kernel::instance()->registry();
        if (!$registry->isEnabled('instagram-feed')) {
            return $data;
        }
        $module = $registry->module('instagram-feed');
        if (!$module instanceof InstagramFeed) {
            return $data;
        }

        /** @var \Joomla\Registry\Registry $params */
        $params = $data['params'];
        try {
            $data['html'] = $module->renderFront([
                'heading'      => (string) $params->get('heading', ''),
                'layout'       => (string) $params->get('layout_mode', ''),
                'count'        => (int) $params->get('count', 0) ?: '',
                'columns'      => (string) $params->get('columns', ''),
                'columns_tablet' => (string) $params->get('columns_tablet', ''),
                'columns_mobile' => (string) $params->get('columns_mobile', ''),
                'show_likes'   => (string) $params->get('show_likes', ''),
                'show_caption' => (string) $params->get('show_caption', ''),
                'show_follow'  => (string) $params->get('show_follow', ''),
                'hashtags'     => (string) $params->get('hashtags', ''),
                'css_class'    => trim((string) $params->get('moduleclass_sfx', '')),
            ]);
        } catch (\Throwable $e) {
            $data['html'] = '';
        }

        return $data;
    }
}

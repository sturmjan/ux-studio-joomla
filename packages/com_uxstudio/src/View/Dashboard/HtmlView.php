<?php

namespace Ux1\Component\UxStudio\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Ux1\UxStudio\Core\Kernel;

\defined('_JEXEC') or die;

/**
 * Mounts the React SPA: one root element plus the assets registered in media/com_uxstudio/joomla.asset.json.
 */
class HtmlView extends BaseHtmlView
{
    public function display($tpl = null): void
    {
        $app      = Factory::getApplication();
        $document = $app->getDocument();
        $user     = $app->getIdentity();

        ToolbarHelper::title(Text::_('COM_UXSTUDIO'), 'puzzle-piece');
        if ($user->authorise('core.admin', 'com_uxstudio') || $user->authorise('core.options', 'com_uxstudio')) {
            ToolbarHelper::preferences('com_uxstudio');
        }

        $document->addScriptOptions('uxstudio', [
            'apiUrl'  => 'index.php?option=com_uxstudio&task=api.dispatch&format=json',
            'csrf'    => Session::getFormToken(),
            'locale'  => $app->getLanguage()->getTag(),
            'version' => Kernel::version(),
            'user'    => ['id' => (int) $user->id, 'name' => (string) $user->name],
        ]);

        $wa = $document->getWebAssetManager();
        $wa->getRegistry()->addExtensionRegistryFile('com_uxstudio');
        $wa->useScript('com_uxstudio.app')->useStyle('com_uxstudio.app');

        parent::display($tpl);
    }
}

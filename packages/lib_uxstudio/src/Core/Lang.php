<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Application\CMSApplicationInterface;

\defined('_JEXEC') or die;

/**
 * Loads core and per-module PHP language files.
 * Module strings live in src/Modules/<Name>/language/<tag>/uxs_<id>.ini.
 */
final class Lang
{
    /** @var array<string,bool> */
    private static array $loaded = [];

    public static function loadCore(CMSApplicationInterface $app): void
    {
        self::load($app, 'lib_uxstudio', JPATH_LIBRARIES . '/uxstudio');
    }

    public static function loadModule(CMSApplicationInterface $app, string $moduleId, string $moduleDir): void
    {
        self::load($app, 'uxs_' . $moduleId, $moduleDir);
    }

    private static function load(CMSApplicationInterface $app, string $extension, string $basePath): void
    {
        $lang = $app->getLanguage();
        $key  = $lang->getTag() . '|' . $extension;
        if (isset(self::$loaded[$key])) {
            return;
        }
        self::$loaded[$key] = true;
        $lang->load($extension, $basePath);
    }
}

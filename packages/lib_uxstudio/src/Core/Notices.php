<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\Event\Event;

\defined('_JEXEC') or die;

/**
 * Notices that modules show in the Joomla administration (for example "the vulnerability scanner found something new").
 * A module raises one with $ctx->notice('key', 'Message', 'warning', 'index.php?option=com_uxstudio#/module?id=my-module') and
 * withdraws it with $ctx->clearNotice('key') once the reason is gone. They are kept in the module's Store, listed on the
 * Joomla control panel (com_cpanel) and on the UX Studio screen as ordinary system messages, only for users who may manage
 * UX Studio, and only while the raising module is enabled. Nothing is rendered on other admin pages.
 */
final class Notices
{
    public const LEVELS = ['info' => 'info', 'warning' => 'warning', 'error' => 'error'];

    private const PREFIX = 'notice:';

    /** Registered by the Kernel on administrator requests. */
    public static function register(CMSApplicationInterface $app): void
    {
        if ($app->isClient('administrator')) {
            $app->getDispatcher()->addListener('onAfterDispatch', [self::class, 'show']);
        }
    }

    public static function raise(string $moduleId, string $key, string $message, string $level = 'warning', string $link = ''): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $key)) {
            throw new \InvalidArgumentException('Invalid notice key.');
        }
        $link = self::safeLink($link);
        Store::forModule($moduleId)->set(self::PREFIX . $key, [
            'level'   => self::LEVELS[$level] ?? 'warning',
            'message' => mb_substr(trim(strip_tags($message)), 0, 500),
            'link'    => $link,
            'created' => time(),
        ]);
    }

    public static function clear(string $moduleId, string $key): void
    {
        Store::forModule($moduleId)->delete(self::PREFIX . $key);
    }

    /** @return array<int,array{module:string,key:string,level:string,message:string,link:string,created:int}> notices of enabled modules */
    public static function all(): array
    {
        $kernel = Kernel::instance();
        $out    = [];
        foreach ($kernel->registry()->enabledIds() as $id) {
            $store = Store::forModule($id);
            foreach ($store->keys(self::PREFIX) as $storeKey) {
                $row = $store->get($storeKey);
                if (\is_array($row) && !empty($row['message'])) {
                    $out[] = ['module' => $id, 'key' => substr($storeKey, \strlen(self::PREFIX)), 'level' => (string) $row['level'], 'message' => (string) $row['message'],
                              'link' => (string) ($row['link'] ?? ''), 'created' => (int) ($row['created'] ?? 0)];
                }
            }
        }
        usort($out, static fn (array $a, array $b): int => $b['created'] <=> $a['created']);

        return $out;
    }

    /** onAfterDispatch (administrator): list the notices on the control panel and the UX Studio screen. */
    public static function show(Event $event): void
    {
        try {
            $app = Kernel::instance()->app();
            $option = (string) $app->getInput()->getCmd('option', '');
            if (!\in_array($option, ['com_cpanel', 'com_uxstudio'], true) || $app->getInput()->getCmd('format', 'html') !== 'html') {
                return;
            }
            $user = $app->getIdentity();
            if (!$user || !$user->authorise('core.manage', Api::COMPONENT)) {
                return;
            }
            Lang::loadCore($app);
            foreach (self::all() as $notice) {
                $text = htmlspecialchars($notice['message'], ENT_QUOTES, 'UTF-8');
                if ($notice['link'] !== '') {
                    $text .= ' <a href="' . htmlspecialchars($notice['link'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars(Text::_('UXS_CORE_NOTICE_OPEN'), ENT_QUOTES, 'UTF-8') . '</a>';
                }
                $app->enqueueMessage('UX Studio: ' . $text, self::LEVELS[$notice['level']] ?? 'warning');
            }
        } catch (\Throwable $e) {
            // A broken notice must never break the administration.
        }
    }

    /** Only links inside this administration: index.php?... (optionally with a #hash). */
    private static function safeLink(string $link): string
    {
        $link = trim($link);

        return preg_match('/^index\.php\?[A-Za-z0-9_=&%.\-\/#?,:]*$/', $link) ? $link : '';
    }
}

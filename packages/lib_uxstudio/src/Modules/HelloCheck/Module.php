<?php

namespace Ux1\UxStudio\Modules\HelloCheck;

use Joomla\CMS\Factory;
use Joomla\CMS\Version;
use Joomla\Database\ParameterType;
use Joomla\Event\Event;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Reference module: copy this folder to start a new module (see docs/MODULE-GUIDE.md).
 * Shows: settings (text + encrypted password), one API route, one migration, one scheduler job,
 * a Joomla event listener and log retention.
 */
final class Module extends BaseModule
{
    public function boot(ModuleContext $ctx): void
    {
        // Demonstrates event wiring: add an HTML comment with the greeting to every site page.
        if ($ctx->isSite()) {
            $ctx->on('onAfterRender', [$this, 'stamp']);
            $ctx->on('onBeforeCompileHead', [$this, 'assets']);
        }
    }

    /** onBeforeCompileHead listener: front-end asset from media/lib_uxstudio/modules/hello-check/. */
    public function assets(Event $event): void
    {
        $this->ctx()->useScript('hello-check.js');
    }

    public function settingsSchema(): array
    {
        return [
            [
                'key'       => 'greeting',
                'type'      => 'text',
                'label'     => 'UXS_HELLO_CHECK_GREETING',
                'help'      => 'UXS_HELLO_CHECK_GREETING_HELP',
                'default'   => 'Hello from UX Studio',
                'maxlength' => 120,
            ],
            [
                'key'   => 'secret_token',
                'type'  => 'password',
                'label' => 'UXS_HELLO_CHECK_SECRET',
                'help'  => 'UXS_HELLO_CHECK_SECRET_HELP',
            ],
        ];
    }

    public function routes(): array
    {
        return [
            'GET ping' => [$this, 'ping'],
            // Per-route ACL example: reading needs core.manage (module default), this needs core.admin.
            'POST heartbeat' => ['handler' => [$this, 'beat'], 'acl' => 'core.admin'],
        ];
    }

    /** Public (site) routes: index.php?option=com_uxstudio&task=public.dispatch&format=json&route=hello-check/hello */
    public function publicRoutes(): array
    {
        return [
            'GET hello' => ['handler' => [$this, 'hello'], 'auth' => 'none', 'limit' => [20, 60]],
            // Signed server-to-server call (X-UXS-Timestamp + X-UXS-Signature), secret = the encrypted "secret_token" setting.
            'POST webhook' => ['handler' => [$this, 'webhook'], 'auth' => 'hmac', 'secret' => fn (): string => (string) $this->ctx()->setting('secret_token', ''), 'limit' => [60, 60], 'max_body' => 4096],
            // Bearer token variant of the same secret.
            'GET status' => ['handler' => [$this, 'hello'], 'auth' => 'token', 'secret' => fn (): string => (string) $this->ctx()->setting('secret_token', '')],
        ];
    }

    public function webhook(ApiRequest $r): array
    {
        return ['bytes' => \strlen($r->raw()), 'keys' => array_keys($r->body())];
    }

    public function hello(ApiRequest $r): array
    {
        return ['greeting' => (string) $this->ctx()->setting('greeting', '')];
    }

    /** POST hello-check/heartbeat - writes one heartbeat row now (core.admin only). */
    public function beat(ApiRequest $r): array
    {
        return ['message' => $this->heartbeat()];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_hello_check` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `note` VARCHAR(190) NOT NULL DEFAULT \'\', '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function tasks(): array
    {
        return [
            'heartbeat' => [
                'label'    => 'UXS_HELLO_CHECK_JOB_HEARTBEAT',
                'callable' => [$this, 'heartbeat'],
                'interval' => 'PT6H',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_hello_check', 'column' => 'created', 'days' => 30]];
    }

    public function dashboardWidget(): ?array
    {
        return ['title' => 'Hello check', 'value' => $this->count(), 'text' => 'heartbeats', 'status' => 'ok'];
    }

    /** GET hello-check/ping - read-only state of the module; the secret is never returned. */
    public function ping(ApiRequest $r): array
    {
        $ctx = $this->ctx();

        return [
            'greeting'   => (string) $ctx->setting('greeting', ''),
            'secret_set' => (string) $ctx->setting('secret_token', '') !== '',
            'time'       => Factory::getDate()->toSql(),
            'user'       => $r->user()->username,
            'joomla'     => (new Version())->getShortVersion(),
            'php'        => PHP_VERSION,
            'heartbeats' => $this->count(),
            'latest'     => $this->latest(),
        ];
    }

    /** Scheduler job hello-check.heartbeat */
    public function heartbeat(): string
    {
        $db   = $this->ctx()->db();
        $now  = Factory::getDate()->toSql();
        $note = 'scheduler';
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_hello_check'))
                ->columns($db->quoteName(['created', 'note']))->values(':c, :n')
                ->bind(':c', $now)->bind(':n', $note)
        )->execute();

        return 'heartbeat #' . $this->count();
    }

    /** onAfterRender listener. */
    public function stamp(Event $event): void
    {
        $app  = $this->ctx()->app();
        $body = $app->getBody();
        if (!\is_string($body) || stripos($body, '</body>') === false || $app->getDocument()?->getType() !== 'html') {
            return;
        }
        $text = str_replace('--', '- -', htmlspecialchars((string) $this->ctx()->setting('greeting', ''), ENT_QUOTES, 'UTF-8'));
        $app->setBody(str_ireplace('</body>', '<!-- UX Studio hello-check: ' . $text . ' -->' . "\n</body>", $body));
    }

    private function count(): int
    {
        $db = $this->ctx()->db();

        return (int) $db->setQuery($db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_hello_check')))->loadResult();
    }

    /** @return array<int,array<string,mixed>> */
    private function latest(): array
    {
        $db = $this->ctx()->db();

        return $db->setQuery(
            $db->createQuery()->select($db->quoteName(['id', 'created', 'note']))->from($db->quoteName('#__uxstudio_hello_check'))
                ->order($db->quoteName('id') . ' DESC'),
            0,
            10
        )->loadAssocList() ?: [];
    }
}

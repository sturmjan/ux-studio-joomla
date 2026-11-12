<?php

namespace Ux1\UxStudio\Modules\EmailLog;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\MailHub;

\defined('_JEXEC') or die;

/**
 * Log of every e-mail sent by Joomla and its extensions. Capturing is done by the shared MailHub (Joomla has no "mail
 * sent" event, so the mailer factory service of the DI container is decorated); this module owns the table, the screen
 * and the Central App feed. Message bodies may contain one-time links (password reset), so the module is admin only.
 */
final class Module extends BaseModule
{
    public function boot(ModuleContext $ctx): void
    {
        MailHub::install();
        MailHub::addObserver(new Logger($ctx->db(), (bool) $ctx->setting('detect_source', true), (bool) $ctx->setting('store_body', true), (bool) $ctx->setting('mask_sensitive', true)));
    }

    public function onDisable(): void
    {
        MailHub::removeObserver(Logger::class);
    }

    public function acl(): string
    {
        return 'core.admin';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'retention_days', 'type' => 'number', 'label' => 'UXS_EMAIL_LOG_RETENTION', 'help' => 'UXS_EMAIL_LOG_RETENTION_HELP', 'default' => 30, 'min' => 0, 'max' => 3650],
            ['key' => 'store_body', 'type' => 'toggle', 'label' => 'UXS_EMAIL_LOG_STORE_BODY', 'help' => 'UXS_EMAIL_LOG_STORE_BODY_HELP', 'default' => true],
            ['key' => 'mask_sensitive', 'type' => 'toggle', 'label' => 'UXS_EMAIL_LOG_MASK_SENSITIVE', 'help' => 'UXS_EMAIL_LOG_MASK_SENSITIVE_HELP', 'default' => true],
            ['key' => 'detect_source', 'type' => 'toggle', 'label' => 'UXS_EMAIL_LOG_DETECT_SOURCE', 'help' => 'UXS_EMAIL_LOG_DETECT_SOURCE_HELP', 'default' => true],
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_email_log` ('
                . '`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, '
                . '`to_email` VARCHAR(255) NOT NULL DEFAULT \'\', `from_email` VARCHAR(255) NOT NULL DEFAULT \'\', '
                . '`subject` VARCHAR(255) NOT NULL DEFAULT \'\', `status` VARCHAR(20) NOT NULL DEFAULT \'pending\', '
                . '`error_message` TEXT NULL, `source` VARCHAR(191) NOT NULL DEFAULT \'\', `mailer` VARCHAR(40) NOT NULL DEFAULT \'\', '
                . '`attempts` TEXT NULL, `message` MEDIUMTEXT NULL, `headers` TEXT NULL, `attachments` TEXT NULL, `meta` MEDIUMTEXT NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_created` (`created`), KEY `idx_status` (`status`)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_email_log', 'column' => 'created', 'days' => fn (): int => (int) $this->ctx()->setting('retention_days', 30)]];
    }

    public function routes(): array
    {
        return [
            'GET entries'               => [$this, 'entries'],
            'GET entries/{id}'          => [$this, 'entry'],
            'POST entries/{id}/resend'  => [$this, 'resend'],
            'POST entries/{id}/delete'  => [$this, 'delete'],
            'POST clear'                => [$this, 'clear'],
            'GET stats'                 => [$this, 'stats'],
            'GET export'                => [$this, 'export'],
        ];
    }

    public function dashboardWidget(): ?array
    {
        try {
            $s = $this->store()->stats();
        } catch (\Throwable $e) {
            return null;
        }

        return [
            'title'  => Text::_('UXS_EMAIL_LOG_NAME'),
            'value'  => $s['last_24h'],
            'text'   => Text::sprintf('UXS_EMAIL_LOG_WIDGET', $s['failed_24h']),
            'status' => $s['failed_24h'] > 0 ? 'warn' : 'ok',
        ];
    }

    /** Central App feed: route "email-log" => {items,total}, see docs/CA-INTEGRACE.md. */
    public function caFeed(string $route, array $query, array $body): array
    {
        if ($route !== 'email-log') {
            return [];
        }

        return $this->store()->forHub($query);
    }

    /* ---- API ---- */

    /** GET email-log/entries?page=&per_page=&status=&search=&date_from=&date_to= */
    public function entries(ApiRequest $r): array
    {
        return $this->store()->list($r->int('page', 1), $r->int('per_page', 20), [
            'status'    => $r->string('status'),
            'search'    => $r->string('search'),
            'date_from' => $r->string('date_from'),
            'date_to'   => $r->string('date_to'),
        ]);
    }

    /** GET email-log/entries/{id}: full detail including body and headers. */
    public function entry(ApiRequest $r): array
    {
        return $this->require((int) $r->param('id'));
    }

    /**
     * POST email-log/entries/{id}/resend: sends the stored message again as a NEW log row (source "resend of #id").
     * Attachments were stored by name only, so they are not attached again.
     */
    public function resend(ApiRequest $r): array
    {
        $id    = (int) $r->param('id');
        $entry = $this->require($id);
        $meta  = (array) $entry['meta'];
        $to    = (array) ($meta['to'] ?? []);
        if (!$to || (string) $entry['message'] === '') {
            throw new ApiException(Text::_('UXS_EMAIL_LOG_ERR_NO_BODY'), 422, 'not_resendable');
        }

        MailHub::overrideSource('UX1 Studio: ' . Text::sprintf('UXS_EMAIL_LOG_RESEND_OF', $id));
        $error = '';
        $ok    = false;
        try {
            $mail = MailHub::createMailer();
            $from = (array) ($meta['from'] ?? []);
            if (!empty($from['email'])) {
                $mail->setFrom((string) $from['email'], (string) ($from['name'] ?? ''), false);
            }
            foreach ($to as $a) {
                $mail->addRecipient((string) $a['email'], (string) ($a['name'] ?? ''));
            }
            foreach ((array) ($meta['cc'] ?? []) as $a) {
                $mail->addCc((string) $a['email'], (string) ($a['name'] ?? ''));
            }
            foreach ((array) ($meta['bcc'] ?? []) as $a) {
                $mail->addBcc((string) $a['email'], (string) ($a['name'] ?? ''));
            }
            foreach ((array) ($meta['reply_to'] ?? []) as $a) {
                $mail->addReplyTo((string) $a['email'], (string) ($a['name'] ?? ''));
            }
            $mail->isHtml(!empty($meta['html']));
            $mail->setSubject((string) $entry['subject']);
            $mail->setBody((string) $entry['message']);
            if (!empty($meta['alt_body'])) {
                $mail->AltBody = (string) $meta['alt_body'];
            }
            $ok = (bool) $mail->Send();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            MailHub::overrideSource('');
        }
        if (!$ok) {
            throw new ApiException($error !== '' ? $error : Text::_('UXS_EMAIL_LOG_ERR_RESEND'), 502, 'resend_failed');
        }

        return ['id' => $id, 'message' => Text::_('UXS_EMAIL_LOG_RESENT')];
    }

    /** POST email-log/entries/{id}/delete */
    public function delete(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        if (!$this->store()->delete($id)) {
            throw new ApiException(Text::_('UXS_EMAIL_LOG_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return ['id' => $id];
    }

    /** POST email-log/clear */
    public function clear(ApiRequest $r): array
    {
        $this->store()->clear();

        return ['cleared' => true];
    }

    /** GET email-log/stats */
    public function stats(ApiRequest $r): array
    {
        return $this->store()->stats() + ['retention_days' => (int) $this->ctx()->setting('retention_days', 30)];
    }

    /** GET email-log/export: CSV text of the log (bodies are left out). */
    public function export(ApiRequest $r): array
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['Date (UTC)', 'To', 'From', 'Subject', 'Status', 'Error', 'Source', 'Mailer'], ',', '"', '');
        foreach ($this->store()->export() as $row) {
            fputcsv($out, array_map(self::csvSafe(...), [
                (string) $row['created'], (string) $row['to_email'], (string) $row['from_email'], (string) $row['subject'],
                (string) $row['status'], (string) ($row['error_message'] ?? ''), (string) $row['source'], (string) $row['mailer'],
            ]), ',', '"', '');
        }
        rewind($out);
        $csv = "\xEF\xBB\xBF" . stream_get_contents($out);
        fclose($out);

        return ['filename' => 'email-log-' . Factory::getDate()->format('Y-m-d') . '.csv', 'csv' => $csv];
    }

    /** Neutralises spreadsheet formula injection (subjects and errors come from arbitrary senders). */
    private static function csvSafe(string $v): string
    {
        return $v !== '' && str_contains("=+-@\t\r", $v[0]) ? "'" . $v : $v;
    }

    private function store(): LogStore
    {
        return new LogStore($this->ctx()->db());
    }

    /** @return array<string,mixed> */
    private function require(int $id): array
    {
        $entry = $this->store()->get($id);
        if ($entry === null) {
            throw new ApiException(Text::_('UXS_EMAIL_LOG_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return $entry;
    }
}

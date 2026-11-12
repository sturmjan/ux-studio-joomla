<?php

namespace Ux1\UxStudio\Modules\EmailLog;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\MailHub;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\MailObserver;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\UxMail;

\defined('_JEXEC') or die;

/** MailHub observer: one row per message, written as "pending" before sending and finished afterwards. */
final class Logger implements MailObserver
{
    public function __construct(private DatabaseInterface $db, private bool $detectSource, private bool $storeBody, private bool $maskSensitive = true)
    {
    }

    public function begin(UxMail $mail, array $snapshot): mixed
    {
        $to     = implode(', ', array_map(static fn (array $a): string => $a['email'], $snapshot['to']));
        $detected = $this->detectSource || $this->maskSensitive ? self::detectSource() : '';
        $source   = MailHub::sourceOverride() !== '' ? MailHub::sourceOverride() : ($this->detectSource ? $detected : '');
        // Password reset / username reminder / MFA mails carry one-time codes: whoever reads the database must not get them.
        $masked = $this->maskSensitive && MailHub::sourceOverride() === '' && self::isSensitiveSource($detected);
        $store  = $this->storeBody && !$masked;
        $now    = Factory::getDate()->toSql();
        $status = 'pending';

        $headers = self::headerText($snapshot);
        $body    = $store ? $this->clean($snapshot['body']) : '';
        $subject = mb_substr($this->clean($snapshot['subject']), 0, 255);
        $toCol   = mb_substr($this->clean($to), 0, 255);
        $from    = mb_substr($this->clean($snapshot['from']['email']), 0, 255);
        $source  = mb_substr($source, 0, 191);
        $attach  = json_encode($snapshot['attachments'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        $meta    = json_encode([
            'from'     => $snapshot['from'],
            'to'       => $snapshot['to'],
            'cc'       => $snapshot['cc'],
            'bcc'      => $snapshot['bcc'],
            'reply_to' => $snapshot['reply_to'],
            'html'     => $snapshot['html'],
            'alt_body' => $store ? $this->clean($snapshot['alt_body']) : '',
            'body_masked' => $masked,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';

        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_email_log'))
                ->columns($db->quoteName(['created', 'to_email', 'from_email', 'subject', 'status', 'source', 'message', 'headers', 'attachments', 'meta']))
                ->values(':created, :to, :from, :subject, :status, :source, :message, :headers, :attachments, :meta')
                ->bind(':created', $now)->bind(':to', $toCol)->bind(':from', $from)->bind(':subject', $subject)
                ->bind(':status', $status)->bind(':source', $source)->bind(':message', $body)->bind(':headers', $headers)
                ->bind(':attachments', $attach)->bind(':meta', $meta)
        )->execute();

        return (int) $db->insertid();
    }

    public function end(mixed $handle, array $result): void
    {
        $id = (int) $handle;
        if ($id <= 0) {
            return;
        }
        $status   = $result['ok'] ? 'success' : 'error';
        $error    = $result['ok'] ? null : mb_substr($this->clean($result['error']), 0, 2000);
        $mailer   = mb_substr($result['mailer'], 0, 40);
        $attempts = json_encode($result['attempts'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]';

        $db = $this->db;
        $db->setQuery(
            $db->createQuery()->update($db->quoteName('#__uxstudio_email_log'))
                ->set($db->quoteName('status') . ' = :status')->set($db->quoteName('error_message') . ' = :error')
                ->set($db->quoteName('mailer') . ' = :mailer')->set($db->quoteName('attempts') . ' = :attempts')
                ->where($db->quoteName('id') . ' = :id')
                ->bind(':status', $status)->bind(':error', $error)->bind(':mailer', $mailer)->bind(':attempts', $attempts)->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER)
        )->execute();
    }

    /** Origins whose mail contains credentials or one-time codes (see detectSource() for the format). */
    private static function isSensitiveSource(string $source): bool
    {
        return $source === 'component: com_users' || str_starts_with($source, 'plugin: multifactorauth/') || str_starts_with($source, 'plugin: twofactorauth/');
    }

    /** Valid UTF-8 only (a binary or latin-1 subject would make the INSERT fail). */
    private function clean(string $s): string
    {
        return mb_scrub(str_replace("\0", '', $s), 'UTF-8');
    }

    /** @param array<string,mixed> $s */
    private static function headerText(array $s): string
    {
        $fmt   = static fn (array $a): string => $a['name'] !== '' ? $a['name'] . ' <' . $a['email'] . '>' : $a['email'];
        $lines = ['From: ' . $fmt($s['from'])];
        foreach (['to' => 'To', 'cc' => 'Cc', 'bcc' => 'Bcc', 'reply_to' => 'Reply-To'] as $key => $label) {
            if ($s[$key]) {
                $lines[] = $label . ': ' . implode(', ', array_map($fmt, $s[$key]));
            }
        }
        $lines[] = 'Content-Type: ' . ($s['html'] ? 'text/html' : 'text/plain') . '; charset=UTF-8';
        foreach ($s['custom_headers'] as $h) {
            $lines[] = trim((string) ($h[0] ?? '')) . ': ' . trim((string) ($h[1] ?? ''));
        }

        return implode("\n", $lines);
    }

    /** Best-effort origin of the message: the first caller outside Joomla libraries and this module. */
    public static function detectSource(): string
    {
        $root = str_replace('\\', '/', JPATH_ROOT);
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if ($file === '' || preg_match('#/Modules/(SmtpEmail|EmailLog|EmailHealth)/#', $file)) {
                continue;
            }
            // Another UX1 Studio module (forms, notifications ...) that sends mail itself.
            if (preg_match('#/Modules/([A-Za-z0-9]+)/#', $file, $m)) {
                return 'UX1 Studio: ' . $m[1];
            }
            if (str_contains($file, '/libraries/') || str_contains($file, '/lib_uxstudio/')) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $file), '/');
            if (preg_match('#(?:^|/)plugins/([^/]+)/([^/]+)/#', $rel, $m)) {
                return 'plugin: ' . $m[1] . '/' . $m[2];
            }
            if (preg_match('#(?:^|/)components/(com_[^/]+)/#', $rel, $m)) {
                return 'component: ' . $m[1];
            }
            if (preg_match('#(?:^|/)modules/(mod_[^/]+)/#', $rel, $m)) {
                return 'module: ' . $m[1];
            }
            if (preg_match('#(?:^|/)templates/([^/]+)/#', $rel, $m)) {
                return 'template: ' . $m[1];
            }

            return basename($file);
        }

        return 'Joomla';
    }
}

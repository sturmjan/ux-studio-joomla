<?php

namespace Ux1\UxStudio\Modules\Forms;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;

\defined('_JEXEC') or die;

/**
 * The public submit pipeline: spam checks (honeypot, minimum fill time, rate limit, optional Turnstile),
 * server-side validation, file handling, storage, e-mail notification and confirmation.
 */
final class Submitter
{
    public const TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(private ModuleContext $ctx)
    {
    }

    /** Signed render-time token embedded in the form: "<unix time>.<hmac>". */
    public static function timeToken(int $formId): string
    {
        $now = time();

        return $now . '.' . self::sign($now, $formId);
    }

    /**
     * @return array<string,mixed> {sent:bool, message?, redirect?, errors?}
     */
    public function handle(ApiRequest $r): array
    {
        $ctx    = $this->ctx;
        $formId = $r->int('form_id');
        $forms  = new Forms($ctx->db());
        $form   = $formId > 0 ? $forms->get($formId) : null;
        if ($form === null || $form['status'] !== 'published') {
            throw new ApiException(Text::_('UXS_FORMS_ERR_NO_FORM'), 404, 'form_not_found');
        }
        $s = $form['settings'];

        // 1) Honeypot: bots fill every input. Answer with a normal-looking success and do nothing.
        if ($r->string('hp') !== '') {
            return ['sent' => true, 'message' => $this->successMessage($s), 'redirect' => ''];
        }

        // 2) Signed render time: the form must have been rendered by this site and not submitted instantly.
        $elapsed = $this->elapsed($r->string('ts'), $formId);
        if ($elapsed === null) {
            throw new ApiException(Text::_('UXS_FORMS_ERR_SPAM'), 400, 'spam');
        }
        if ($elapsed < (int) $s['min_time']) {
            throw new ApiException(Text::_('UXS_FORMS_ERR_TOO_FAST'), 429, 'too_fast');
        }

        $ip = (string) Factory::getApplication()->getInput()->server->get('REMOTE_ADDR', '', 'string');

        // 4) Turnstile (when the form asks for it and the keys are configured).
        if (!empty($s['turnstile']) && (string) $ctx->setting('turnstile_secret', '') !== '') {
            if (!$this->verifyTurnstile($r->string('turnstile'), $ip)) {
                throw new ApiException(Text::_('UXS_FORMS_ERR_CAPTCHA'), 400, 'captcha_failed');
            }
        }

        // 5) Validation.
        $input = $r->input('fields', []);
        $input = \is_array($input) ? $input : [];
        $files = $r->input('files', []);
        $files = \is_array($files) ? $files : [];

        // Visibility depends on the raw values of other fields.
        $values = [];
        foreach ($form['fields'] as $f) {
            if (!\in_array($f['type'], Fields::LAYOUT, true)) {
                $values[$f['key']] = $input[$f['key']] ?? '';
            }
        }

        $errors   = [];
        $clean    = [];
        $prepared = [];
        $maxMb    = max(1, min(5, (int) $ctx->setting('max_upload_mb', 5)));
        $totalMb  = 0;
        foreach ($form['fields'] as $f) {
            $key = $f['key'];
            if (\in_array($f['type'], Fields::LAYOUT, true) || !Fields::isVisible($f, $values)) {
                continue;
            }
            if ($f['type'] === 'file') {
                $list = $files[$key] ?? [];
                $list = \is_array($list) && isset($list['data']) ? [$list] : (\is_array($list) ? array_values($list) : []);
                $limit = !empty($f['multiple']) ? 5 : 1;
                if (\count($list) > $limit) {
                    $errors[$key] = Text::sprintf('UXS_FORMS_ERR_FILES_COUNT', $limit);
                    continue;
                }
                if (!$list) {
                    if (!empty($f['required'])) {
                        $errors[$key] = Text::_('UXS_FORMS_ERR_REQUIRED');
                    }
                    continue;
                }
                foreach ($list as $item) {
                    try {
                        $prepared[$key][] = FileStore::inspect($f, $item, $maxMb);
                    } catch (\InvalidArgumentException $e) {
                        $errors[$key] = $e->getMessage();
                        break;
                    }
                }
                continue;
            }
            [$value, $error] = $this->normalize($f, $input[$key] ?? null);
            if ($error !== null) {
                $errors[$key] = $error;
                continue;
            }
            $clean[$key] = $value;
        }
        if ($errors) {
            return ['sent' => false, 'errors' => $errors, 'message' => Text::_('UXS_FORMS_ERR_FIX')];
        }

        // 5b) Hourly per-IP, per-form limit; counted only for valid submissions, so typos do not burn the quota.
        $perHour = max(1, min(1000, (int) $ctx->setting('rate_limit', 10)));
        $retry   = (new RateLimiter($ctx->db()))->hit('forms:submit:' . $formId . ':' . $ip, $perHour, 3600);
        if ($retry > 0) {
            throw new ApiException(Text::_('UXS_FORMS_ERR_RATE'), 429, 'rate_limited', null, ['Retry-After' => (string) $retry]);
        }

        // 6) Store files first (the entry references them), then the submission.
        $stored = [];
        try {
            foreach ($prepared as $key => $list) {
                foreach ($list as $file) {
                    $stored[$key][] = FileStore::write($formId, $file);
                }
            }
        } catch (\Throwable $e) {
            FileStore::deleteAll($stored);
            Log::add('Forms: cannot store an upload: ' . $e->getMessage(), Log::ERROR, 'uxstudio');
            throw new ApiException(Text::_('UXS_FORMS_ERR_STORE'), 500, 'store_failed');
        }

        $submissionId = 0;
        $repo         = new Submissions($ctx->db());
        if (!empty($s['store'])) {
            $pageUrl      = $r->string('page_url');
            $pageUrl      = preg_match('#^https?://[^\s"<>]+$#i', $pageUrl) ? $pageUrl : '';
            $ua           = (string) Factory::getApplication()->getInput()->server->get('HTTP_USER_AGENT', '', 'string');
            $submissionId = $repo->insert($formId, $clean, $stored, $ip, $ua, $pageUrl);
        }

        // 7) E-mails. A mail failure must not lose the (already stored) submission.
        $mailOk = true;
        $sent   = false;
        if (!empty($s['send_notification'])) {
            $sent   = true;
            $mailOk = $this->notify($form, $clean, $stored, $submissionId);
        }
        if (!empty($s['confirm_enabled']) && $s['confirm_field'] !== '') {
            $to = (string) ($clean[$s['confirm_field']] ?? '');
            if ($to !== '' && filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $sent   = true;
                $mailOk = $this->confirm($form, $clean, $to, $submissionId) && $mailOk;
            }
        }
        if ($submissionId > 0 && $sent) {
            $repo->setMailStatus($submissionId, $mailOk ? 'sent' : 'failed');
        }
        if (!$mailOk && $submissionId === 0) {
            // Nothing stored and nothing delivered: do not tell the visitor it worked.
            FileStore::deleteAll($stored);
            throw new ApiException(Text::_('UXS_FORMS_ERR_MAIL'), 500, 'mail_failed');
        }
        if ($submissionId === 0) {
            // The notification mail was the only record: files are not kept when nothing references them.
            FileStore::deleteAll($stored);
        }

        $redirect = (string) $s['redirect_url'];
        if ($redirect !== '' && str_starts_with($redirect, '/')) {
            $redirect = \Joomla\CMS\Uri\Uri::root() . ltrim($redirect, '/');
        }

        return ['sent' => true, 'message' => $this->successMessage($s), 'redirect' => $redirect];
    }

    private function successMessage(array $settings): string
    {
        return (string) ($settings['success_message'] !== '' ? $settings['success_message'] : Text::_('UXS_FORMS_SUCCESS_DEFAULT'));
    }

    /**
     * Validates and normalizes one non-file field.
     *
     * @param  array<string,mixed> $f
     * @return array{0:string|array<int,string>,1:?string} value, error message
     */
    private function normalize(array $f, mixed $raw): array
    {
        $type     = $f['type'];
        $required = !empty($f['required']);
        $req      = Text::_('UXS_FORMS_ERR_REQUIRED');

        if (\in_array($type, ['checkbox', 'acceptance'], true)) {
            $on = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            if ($required && !$on) {
                return ['', $type === 'acceptance' ? Text::_('UXS_FORMS_ERR_ACCEPT') : $req];
            }

            return [$on ? Text::_('JYES') : Text::_('JNO'), null];
        }

        if ($type === 'checkbox_group') {
            $allowed = array_column($f['options'] ?? [], 'value');
            $list    = [];
            foreach (\is_array($raw) ? $raw : [] as $v) {
                $v = \is_scalar($v) ? (string) $v : '';
                if ($v !== '' && \in_array($v, $allowed, true)) {
                    $list[$v] = $v;
                } elseif ($v !== '') {
                    return ['', Text::_('UXS_FORMS_ERR_CHOICE')];
                }
            }
            if ($required && !$list) {
                return ['', $req];
            }

            return [array_values($list), null];
        }

        if (\is_array($raw)) {
            return ['', Text::_('UXS_FORMS_ERR_INVALID')];
        }
        $max   = $type === 'textarea' ? 10000 : 1000;
        $value = Fields::text($raw ?? '', $f['maxlength'] ?? $max);
        if ($type !== 'textarea') {
            $value = trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $value));
        }
        if ($value === '') {
            return ['', $required ? $req : null];
        }

        switch ($type) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 254) {
                    return ['', Text::_('UXS_FORMS_ERR_EMAIL')];
                }
                break;
            case 'tel':
                if (!preg_match('/^\+?[0-9 ()\/.\-]{5,30}$/', $value)) {
                    return ['', Text::_('UXS_FORMS_ERR_TEL')];
                }
                break;
            case 'url':
                if (!preg_match('#^https?://#i', $value) || !filter_var($value, FILTER_VALIDATE_URL)) {
                    return ['', Text::_('UXS_FORMS_ERR_URL')];
                }
                break;
            case 'number':
                if (!is_numeric($value)) {
                    return ['', Text::_('UXS_FORMS_ERR_NUMBER')];
                }
                if (isset($f['min']) && (float) $value < (float) $f['min']) {
                    return ['', Text::sprintf('UXS_FORMS_ERR_MIN', (string) $f['min'])];
                }
                if (isset($f['max']) && (float) $value > (float) $f['max']) {
                    return ['', Text::sprintf('UXS_FORMS_ERR_MAX', (string) $f['max'])];
                }
                break;
            case 'date':
                $d = \DateTime::createFromFormat('!Y-m-d', $value);
                if (!$d || $d->format('Y-m-d') !== $value) {
                    return ['', Text::_('UXS_FORMS_ERR_DATE')];
                }
                break;
            case 'select':
            case 'radio':
                if (!\in_array($value, array_column($f['options'] ?? [], 'value'), true)) {
                    return ['', Text::_('UXS_FORMS_ERR_CHOICE')];
                }
                break;
        }
        if (isset($f['minlength']) && $f['minlength'] > 0 && mb_strlen($value) < (int) $f['minlength']) {
            return ['', Text::sprintf('UXS_FORMS_ERR_MINLENGTH', (int) $f['minlength'])];
        }

        return [$value, null];
    }

    private function elapsed(string $token, int $formId): ?int
    {
        if (!preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', $token, $m)) {
            return null;
        }
        if (!hash_equals(self::sign((int) $m[1], $formId), $m[2])) {
            return null;
        }

        $age = time() - (int) $m[1];
        if ($age > 86400 * 2) {
            return null; // too old: a bot could otherwise reuse one token forever
        }

        return max(0, $age);
    }

    private static function sign(int $time, int $formId): string
    {
        $secret = (string) Factory::getApplication()->get('secret', '');

        return hash_hmac('sha256', 'uxs-forms|' . $time . '|' . $formId, $secret);
    }

    private function verifyTurnstile(string $token, string $ip): bool
    {
        if ($token === '' || \strlen($token) > 2048) {
            return false;
        }
        try {
            $res = $this->ctx->http()->post(self::TURNSTILE_VERIFY, ['secret' => (string) $this->ctx->setting('turnstile_secret', ''), 'response' => $token, 'remoteip' => $ip], ['timeout' => 8]);
            $json = json_decode($res['body'], true);

            return $res['status'] === 200 && \is_array($json) && !empty($json['success']);
        } catch (\Throwable $e) {
            Log::add('Forms: Turnstile verification failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');

            return false;
        }
    }

    /**
     * @param array<string,mixed> $form
     * @param array<string,mixed> $values
     * @param array<string,array<int,array<string,mixed>>> $files
     */
    private function notify(array $form, array $values, array $files, int $submissionId): bool
    {
        $s  = $form['settings'];
        $to = array_filter(array_map('trim', explode(',', (string) $s['to'])));
        if (!$to) {
            $to = array_filter(array_map('trim', explode(',', (string) $this->ctx->setting('default_to', ''))));
        }
        if (!$to) {
            $to = [(string) Factory::getApplication()->get('mailfrom', '')];
        }
        $to = array_values(array_filter($to, static fn ($m) => filter_var($m, FILTER_VALIDATE_EMAIL)));
        if (!$to) {
            Log::add('Forms: no valid recipient for form ' . $form['id'], Log::WARNING, 'uxstudio');

            return false;
        }

        $subject = $s['subject'] !== '' ? $s['subject'] : Text::sprintf('UXS_FORMS_MAIL_SUBJECT_DEFAULT', $form['title']);
        $subject = $this->subject($this->fill($subject, $form, $values, $files, $submissionId, false));

        $body = '<p>' . Text::sprintf('UXS_FORMS_MAIL_INTRO', htmlspecialchars((string) $form['title'], ENT_QUOTES, 'UTF-8')) . '</p>'
            . $this->table($form, $values, $files);

        try {
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            foreach ($to as $addr) {
                $mailer->addRecipient($addr);
            }
            $replyField = (string) $s['reply_to_field'];
            $reply      = $replyField !== '' ? (string) ($values[$replyField] ?? '') : '';
            if ($reply !== '' && filter_var($reply, FILTER_VALIDATE_EMAIL)) {
                $mailer->addReplyTo($reply);
            }
            $mailer->setSubject($subject);
            $mailer->isHtml(true);
            $mailer->setBody($body);
            if (!empty($s['attach_files'])) {
                foreach ($files as $list) {
                    foreach ($list as $entry) {
                        $path = FileStore::resolve((string) $entry['path']);
                        if ($path !== null) {
                            $mailer->addAttachment($path, (string) $entry['name']);
                        }
                    }
                }
            }

            return $mailer->send() === true;
        } catch (\Throwable $e) {
            Log::add('Forms: notification e-mail failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');

            return false;
        }
    }

    /** @param array<string,mixed> $form @param array<string,mixed> $values */
    private function confirm(array $form, array $values, string $to, int $submissionId): bool
    {
        $s       = $form['settings'];
        $site    = (string) Factory::getApplication()->get('sitename', '');
        $subject = $s['confirm_subject'] !== '' ? $s['confirm_subject'] : Text::sprintf('UXS_FORMS_CONFIRM_SUBJECT_DEFAULT', $site);
        $message = $s['confirm_message'] !== '' ? $s['confirm_message'] : Text::_('UXS_FORMS_CONFIRM_MESSAGE_DEFAULT');
        $subject = $this->subject($this->fill($subject, $form, $values, [], $submissionId, false));
        $html    = nl2br($this->fill($message, $form, $values, [], $submissionId, true), false);

        try {
            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($to);
            $mailer->setSubject($subject);
            $mailer->isHtml(true);
            $mailer->setBody('<div>' . $html . '</div>');
            return $mailer->send() === true;
        } catch (\Throwable $e) {
            Log::add('Forms: confirmation e-mail failed: ' . $e->getMessage(), Log::ERROR, 'uxstudio');

            return false;
        }
    }

    private function subject(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $s)), 0, 250);
    }

    /**
     * Replaces {form_title}, {site_name}, {submission_id}, {all_fields} and {<field key>}.
     *
     * @param bool $html when true the result is HTML-escaped (the template text is escaped as well)
     */
    private function fill(string $template, array $form, array $values, array $files, int $submissionId, bool $html): string
    {
        $esc  = static fn (string $v): string => $html ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : $v;
        $map  = [
            '{form_title}'    => $esc((string) $form['title']),
            '{site_name}'     => $esc((string) Factory::getApplication()->get('sitename', '')),
            '{submission_id}' => $submissionId > 0 ? (string) $submissionId : '',
        ];
        $text = [];
        foreach ($form['fields'] as $f) {
            if (!isset($values[$f['key']])) {
                continue;
            }
            $v                       = $values[$f['key']];
            $v                       = \is_array($v) ? implode(', ', $v) : (string) $v;
            $map['{' . $f['key'] . '}'] = $esc($v);
            $text[]                  = ($f['label'] !== '' ? $f['label'] : $f['key']) . ': ' . $v;
        }
        $map['{all_fields}'] = $esc(implode("\n", $text));
        $template            = $esc($template);

        return strtr($template, $map);
    }

    /** HTML table of the submitted values (everything escaped). */
    private function table(array $form, array $values, array $files): string
    {
        $rows = '';
        foreach ($form['fields'] as $f) {
            if (\in_array($f['type'], Fields::LAYOUT, true)) {
                continue;
            }
            $key = $f['key'];
            if ($f['type'] === 'file') {
                if (empty($files[$key])) {
                    continue;
                }
                $v = implode(', ', array_map(static fn ($e) => (string) $e['name'], $files[$key]));
            } else {
                if (!array_key_exists($key, $values)) {
                    continue;
                }
                $v = \is_array($values[$key]) ? implode(', ', $values[$key]) : (string) $values[$key];
            }
            $rows .= '<tr><th align="left" valign="top" style="padding:6px 12px 6px 0;border-bottom:1px solid #e5e7eb;">'
                . htmlspecialchars($f['label'] !== '' ? $f['label'] : $key, ENT_QUOTES, 'UTF-8') . '</th>'
                . '<td style="padding:6px 0;border-bottom:1px solid #e5e7eb;">' . nl2br(htmlspecialchars($v, ENT_QUOTES, 'UTF-8'), false) . '</td></tr>';
        }

        return '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:14px;">' . $rows . '</table>';
    }
}

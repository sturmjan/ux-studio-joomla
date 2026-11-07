<?php

namespace Ux1\UxStudio\Modules\SmtpEmail\Mail;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\Mail;

\defined('_JEXEC') or die;

/**
 * The Joomla mailer with two hooks: the connection (SMTP / API, with an optional fallback) is chosen by the registered
 * MailTransport and every message is reported to the registered MailObservers (e-mail log).
 *
 * Instances are created by UxMailerFactory, so it covers every code path that asks the DI container for a mailer
 * (Factory::getMailer(), Mail::getInstance(), MailTemplate, plugins).
 */
final class UxMail extends Mail
{
    /** Provider message id of the last accepted API send (Brevo messageId); empty for SMTP. */
    public string $uxMessageId = '';

    /** @var array{ok:bool,error:string,mailer:string,attempts:array<int,array<string,mixed>>}|null outcome of the last Send() */
    public ?array $uxResult = null;

    /** The unchanged Joomla send (mail() / sendmail / SMTP from the global configuration). */
    public function nativeSend(): bool
    {
        return (bool) parent::Send();
    }

    /** Whether this mailer throws exceptions (the Joomla default) or returns false. */
    public function throwsExceptions(): bool
    {
        return (bool) $this->exceptions;
    }

    /** @return array<string,mixed> transport related properties, to restore them after a temporary SMTP override */
    public function transportState(): array
    {
        $state = [];
        foreach (['Mailer', 'Host', 'Port', 'SMTPAuth', 'Username', 'Password', 'SMTPSecure', 'SMTPAutoTLS', 'AuthType', 'Sendmail'] as $prop) {
            $state[$prop] = $this->$prop;
        }
        $state['oauth'] = $this->oauth ?? null;

        return $state;
    }

    /** @param array<string,mixed> $state */
    public function restoreTransport(array $state): void
    {
        foreach ($state as $prop => $value) {
            if ($prop === 'oauth') {
                $this->oauth = $value;
                continue;
            }
            $this->$prop = $value;
        }
    }

    public function Send()
    {
        $this->uxResult = null;
        $attempts       = [];
        $ok             = false;
        $error          = '';
        $used           = '';
        $last           = null;

        try {
            MailHub::prepare($this);
        } catch (\Throwable $e) {
            // A broken From override must not stop the message.
        }
        $handle = MailHub::begin($this);

        try {
            // "Send mail" switched off in the global configuration: leave the decision (and the exception) to Joomla.
            $steps = Factory::getApplication()->get('mailonline', 1) ? MailHub::steps() : [];
            $ready = array_filter($steps, static fn (array $s): bool => $s['ready']);

            // A forced (test) connection that is not set up must fail instead of silently using the Joomla mailer.
            if (!$ready && !($steps && $steps[0]['kind'] === 'test')) {
                $used = 'joomla/' . $this->Mailer;
                try {
                    $ok    = $this->nativeSend();
                    $error = $ok ? '' : (string) $this->ErrorInfo;
                } catch (\Throwable $e) {
                    $last  = $e;
                    $error = $e->getMessage();
                }
                $attempts[] = ['mailer' => $used, 'kind' => 'primary', 'ok' => $ok, 'error' => $error];
            } else {
                foreach ($steps as $step) {
                    if (!$step['ready']) {
                        $error      = Text::_('UXS_SMTP_EMAIL_NOT_SET_UP');
                        $used       = $step['id'];
                        $attempts[] = ['mailer' => $step['id'], 'kind' => $step['kind'], 'ok' => false, 'error' => $error];
                        continue;
                    }
                    try {
                        $this->uxMessageId = '';
                        MailHub::transport()?->attempt($step['id'], $this);
                        $ok         = true;
                        $used       = $step['id'];
                        $error      = '';
                        $attempts[] = ['mailer' => $step['id'], 'kind' => $step['kind'], 'ok' => true, 'error' => ''] + ($this->uxMessageId !== '' ? ['message_id' => $this->uxMessageId] : []);
                        break;
                    } catch (\Throwable $e) {
                        $last       = $e;
                        $error      = $e->getMessage() !== '' ? $e->getMessage() : (string) $this->ErrorInfo;
                        $used       = $step['id'];
                        $attempts[] = ['mailer' => $step['id'], 'kind' => $step['kind'], 'ok' => false, 'error' => $error];
                    }
                }
            }
        } finally {
            $this->uxResult = ['ok' => $ok, 'error' => $error, 'mailer' => $used, 'attempts' => $attempts];
            MailHub::end($handle, $this->uxResult);
        }

        if ($ok) {
            return true;
        }
        if ($this->throwsExceptions()) {
            throw ($last ?? new \RuntimeException($error));
        }

        return false;
    }
}

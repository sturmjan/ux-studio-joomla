<?php

namespace Ux1\UxStudio\Modules\SmtpEmail;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\BrevoClient;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\MailHub;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\MailTransport;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\UxMail;

\defined('_JEXEC') or die;

/**
 * Routes every message Joomla sends through SMTP or the Brevo API (see Mail\MailHub for how all code paths are caught),
 * with an optional fallback connection and a From address / name override. Secrets are password-type settings:
 * encrypted at rest and never returned to the browser.
 */
final class Module extends BaseModule implements MailTransport
{
    /** Connection ids. 'joomla' = the unchanged mailer from the global configuration. */
    public const CONNECTIONS = ['smtp', 'brevo'];

    public function boot(ModuleContext $ctx): void
    {
        MailHub::install();
        MailHub::registerTransport($this);
    }

    public function onDisable(): void
    {
        MailHub::registerTransport(null);
    }

    public function acl(): string
    {
        // SMTP credentials and the ability to redirect all site mail: administrators only.
        return 'core.admin';
    }

    public function settingsSchema(): array
    {
        $smtp   = 'UXS_SMTP_EMAIL_SECTION_SMTP';
        $brevo  = 'UXS_SMTP_EMAIL_SECTION_BREVO';

        return [
            ['key' => 'mailer', 'type' => 'select', 'label' => 'UXS_SMTP_EMAIL_MAILER', 'help' => 'UXS_SMTP_EMAIL_MAILER_HELP', 'default' => 'smtp',
             'options' => ['smtp' => 'UXS_SMTP_EMAIL_OPT_SMTP', 'brevo' => 'UXS_SMTP_EMAIL_OPT_BREVO', 'joomla' => 'UXS_SMTP_EMAIL_OPT_JOOMLA']],
            ['key' => 'fallback_mailer', 'type' => 'select', 'label' => 'UXS_SMTP_EMAIL_FALLBACK', 'help' => 'UXS_SMTP_EMAIL_FALLBACK_HELP', 'default' => 'none',
             'options' => ['none' => 'UXS_SMTP_EMAIL_OPT_NONE', 'smtp' => 'UXS_SMTP_EMAIL_OPT_SMTP', 'brevo' => 'UXS_SMTP_EMAIL_OPT_BREVO', 'joomla' => 'UXS_SMTP_EMAIL_OPT_JOOMLA']],
            ['key' => 'from_email', 'type' => 'text', 'label' => 'UXS_SMTP_EMAIL_FROM_EMAIL', 'help' => 'UXS_SMTP_EMAIL_FROM_EMAIL_HELP', 'default' => '', 'maxlength' => 190],
            ['key' => 'force_from_email', 'type' => 'toggle', 'label' => 'UXS_SMTP_EMAIL_FORCE_FROM_EMAIL', 'help' => 'UXS_SMTP_EMAIL_FORCE_FROM_EMAIL_HELP', 'default' => false],
            ['key' => 'from_name', 'type' => 'text', 'label' => 'UXS_SMTP_EMAIL_FROM_NAME', 'default' => '', 'maxlength' => 120],
            ['key' => 'force_from_name', 'type' => 'toggle', 'label' => 'UXS_SMTP_EMAIL_FORCE_FROM_NAME', 'help' => 'UXS_SMTP_EMAIL_FORCE_FROM_NAME_HELP', 'default' => false],

            ['key' => 'host', 'type' => 'text', 'section' => $smtp, 'label' => 'UXS_SMTP_EMAIL_HOST', 'default' => '', 'maxlength' => 190, 'placeholder' => 'smtp.example.com'],
            ['key' => 'port', 'type' => 'number', 'section' => $smtp, 'label' => 'UXS_SMTP_EMAIL_PORT', 'default' => 587, 'min' => 1, 'max' => 65535],
            ['key' => 'encryption', 'type' => 'select', 'section' => $smtp, 'label' => 'UXS_SMTP_EMAIL_ENCRYPTION', 'default' => 'tls',
             'options' => ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'UXS_SMTP_EMAIL_OPT_NONE']],
            ['key' => 'username', 'type' => 'text', 'section' => $smtp, 'label' => 'UXS_SMTP_EMAIL_USERNAME', 'default' => '', 'maxlength' => 190],
            ['key' => 'password', 'type' => 'password', 'section' => $smtp, 'label' => 'UXS_SMTP_EMAIL_PASSWORD', 'help' => 'UXS_SMTP_EMAIL_PASSWORD_HELP'],

            ['key' => 'brevo_api_key', 'type' => 'password', 'section' => $brevo, 'label' => 'UXS_SMTP_EMAIL_BREVO_KEY', 'help' => 'UXS_SMTP_EMAIL_BREVO_KEY_HELP'],
        ];
    }

    public function routes(): array
    {
        return [
            'GET status'         => [$this, 'status'],
            'POST test'          => [$this, 'test'],
            'POST brevo/verify'  => [$this, 'verifyBrevo'],
        ];
    }

    public function dashboardWidget(): ?array
    {
        $ready = $this->isReady($this->primary());

        return [
            'title'  => Text::_('UXS_SMTP_EMAIL_NAME'),
            'value'  => $this->primary() === 'joomla' ? 'Joomla' : strtoupper($this->primary()),
            'text'   => Text::_($ready ? 'UXS_SMTP_EMAIL_WIDGET_OK' : 'UXS_SMTP_EMAIL_WIDGET_NOT_SET'),
            'status' => $ready ? 'ok' : 'warn',
        ];
    }

    /* ---- MailTransport ---- */

    public function primary(): string
    {
        $m = (string) $this->ctx()->setting('mailer', 'smtp');

        return \in_array($m, ['smtp', 'brevo', 'joomla'], true) ? $m : 'smtp';
    }

    public function fallback(): string
    {
        $m = (string) $this->ctx()->setting('fallback_mailer', 'none');

        return \in_array($m, ['smtp', 'brevo', 'joomla'], true) && $m !== $this->primary() ? $m : '';
    }

    public function plan(): array
    {
        $primary = $this->primary();
        if ($primary === 'joomla') {
            return [];
        }
        $plan     = [$primary];
        $fallback = $this->fallback();
        if ($fallback !== '') {
            $plan[] = $fallback;
        }

        return $plan;
    }

    public function isReady(string $id): bool
    {
        $ctx = $this->ctx();

        return match ($id) {
            'smtp'   => trim((string) $ctx->setting('host', '')) !== '',
            'brevo'  => (string) $ctx->setting('brevo_api_key', '') !== '',
            'joomla' => true,
            default  => false,
        };
    }

    public function prepare(UxMail $mail): void
    {
        $ctx   = $this->ctx();
        $app   = Factory::getApplication();
        $email = trim((string) $ctx->setting('from_email', ''));
        if ($email !== '' && MailHelper::isEmailAddress($email)) {
            $isDefault = $mail->From === '' || strcasecmp((string) $mail->From, (string) $app->get('mailfrom')) === 0;
            if ($isDefault || $ctx->setting('force_from_email', false)) {
                $mail->setFrom($email, (string) $mail->FromName, false);
            }
        }
        $name = trim((string) $ctx->setting('from_name', ''));
        if ($name !== '') {
            $isDefault = trim((string) $mail->FromName) === '' || (string) $mail->FromName === (string) $app->get('fromname');
            if ($isDefault || $ctx->setting('force_from_name', false)) {
                $mail->setFrom((string) $mail->From, MailHelper::cleanLine($name), false);
            }
        }
    }

    public function attempt(string $id, UxMail $mail): void
    {
        $ctx = $this->ctx();
        switch ($id) {
            case 'smtp':
                $state = $mail->transportState();
                try {
                    $enc = (string) $ctx->setting('encryption', 'tls');
                    $mail->isSMTP();
                    $mail->Host        = trim((string) $ctx->setting('host', ''));
                    $mail->Port        = (int) $ctx->setting('port', 587);
                    $mail->Username    = (string) $ctx->setting('username', '');
                    $mail->Password    = (string) $ctx->setting('password', '');
                    $mail->SMTPAuth    = $mail->Username !== '';
                    $mail->SMTPSecure  = $enc === 'none' ? '' : $enc;
                    $mail->SMTPAutoTLS = $enc !== 'none';
                    $mail->AuthType    = '';
                    if (!$mail->nativeSend()) {
                        throw new \RuntimeException((string) $mail->ErrorInfo ?: 'The SMTP server did not accept the message.');
                    }
                } finally {
                    $mail->restoreTransport($state);
                }
                break;

            case 'brevo':
                $mail->uxMessageId = (new BrevoClient((string) $ctx->setting('brevo_api_key', ''), $ctx->http()))->send($mail);
                break;

            case 'joomla':
                if (!$mail->nativeSend()) {
                    throw new \RuntimeException((string) $mail->ErrorInfo ?: 'Joomla could not send the message.');
                }
                break;

            default:
                throw new \RuntimeException('Unknown connection.');
        }
    }

    /* ---- API ---- */

    /** GET smtp-email/status */
    public function status(ApiRequest $r): array
    {
        $ctx      = $this->ctx();
        $registry = Kernel::instance()->registry();

        return [
            'mailer'      => $this->primary(),
            'fallback'    => $this->fallback(),
            'ready'       => ['smtp' => $this->isReady('smtp'), 'brevo' => $this->isReady('brevo')],
            'host'        => trim((string) $ctx->setting('host', '')),
            'from_email'  => trim((string) $ctx->setting('from_email', '')),
            'joomla_mailer' => (string) Factory::getApplication()->get('mailer', 'mail'),
            'logging'     => $registry->isEnabled('email-log'),
            'health'      => $registry->isEnabled('email-health'),
            'mail_online' => (bool) Factory::getApplication()->get('mailonline', 1),
        ];
    }

    /**
     * POST smtp-email/test {to?, mailer?}: one test message. Without "mailer" it goes the normal way (primary, then the
     * fallback); with "mailer" (smtp | brevo | joomla) through exactly that connection, no fallback.
     */
    public function test(ApiRequest $r): array
    {
        $to = trim($r->string('to'));
        if ($to === '') {
            $to = (string) $r->user()->email;
        }
        if (!MailHelper::isEmailAddress($to)) {
            throw new ApiException(Text::_('UXS_SMTP_EMAIL_ERR_RECIPIENT'), 422, 'invalid_recipient');
        }
        $mailer = $r->string('mailer');
        if ($mailer !== '' && !\in_array($mailer, ['smtp', 'brevo', 'joomla'], true)) {
            throw new ApiException(Text::_('UXS_SMTP_EMAIL_ERR_MAILER'), 422, 'invalid_value');
        }

        $site = (string) Factory::getApplication()->get('sitename', '');
        MailHub::forceMailer($mailer !== '' ? $mailer : null);
        MailHub::overrideSource('UX1 Studio: ' . Text::_('UXS_SMTP_EMAIL_NAME'));
        $error = '';
        $ok    = false;
        $mail  = null;
        try {
            $mail = MailHub::createMailer();
            $mail->addRecipient($to);
            $mail->setSubject(Text::sprintf('UXS_SMTP_EMAIL_TEST_SUBJECT', $site));
            $mail->isHtml(false);
            $mail->setBody(Text::_('UXS_SMTP_EMAIL_TEST_BODY'));
            $ok = (bool) $mail->Send();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            MailHub::forceMailer(null);
            MailHub::overrideSource('');
        }
        $result = $mail?->uxResult;

        return [
            'success'  => $ok,
            'to'       => $to,
            'error'    => $ok ? '' : ($error !== '' ? $error : (string) ($result['error'] ?? '')),
            'mailer'   => (string) ($result['mailer'] ?? ''),
            'attempts' => $result['attempts'] ?? [],
        ];
    }

    /** POST smtp-email/brevo/verify: checks the saved Brevo key against the Brevo account endpoint. */
    public function verifyBrevo(ApiRequest $r): array
    {
        $key = (string) $this->ctx()->setting('brevo_api_key', '');
        if ($key === '') {
            throw new ApiException(Text::_('UXS_SMTP_EMAIL_ERR_NO_KEY'), 422, 'no_key');
        }

        return (new BrevoClient($key, $this->ctx()->http()))->verify();
    }
}

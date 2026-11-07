<?php

namespace Ux1\UxStudio\Modules\SmtpEmail\Mail;

\defined('_JEXEC') or die;

/**
 * Sending strategy plugged into UxMail by the smtp-email module. Without a registered transport every message goes
 * through the Joomla mailer exactly as configured in the global configuration.
 */
interface MailTransport
{
    /** Called right before sending: apply the From address / name override. */
    public function prepare(UxMail $mail): void;

    /** @return string[] connection ids in the order they are tried (primary first, then the optional fallback) */
    public function plan(): array;

    /** Whether the connection has everything it needs to try sending. */
    public function isReady(string $id): bool;

    /**
     * Send the message through one connection.
     *
     * @throws \Throwable when the message was not accepted
     */
    public function attempt(string $id, UxMail $mail): void;
}

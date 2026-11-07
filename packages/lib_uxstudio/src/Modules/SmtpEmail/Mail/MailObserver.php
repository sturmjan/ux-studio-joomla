<?php

namespace Ux1\UxStudio\Modules\SmtpEmail\Mail;

\defined('_JEXEC') or die;

/**
 * Watches every message sent through a UxMail (the e-mail log). Implementations must never throw: a broken log must
 * not stop an e-mail from leaving the site.
 */
interface MailObserver
{
    /**
     * Called before the first attempt.
     *
     * @param array<string,mixed> $snapshot see MailHub::snapshot()
     *
     * @return mixed handle handed back to end()
     */
    public function begin(UxMail $mail, array $snapshot): mixed;

    /**
     * Called after the last attempt.
     *
     * @param array{ok:bool,error:string,mailer:string,attempts:array<int,array<string,mixed>>} $result
     */
    public function end(mixed $handle, array $result): void;
}

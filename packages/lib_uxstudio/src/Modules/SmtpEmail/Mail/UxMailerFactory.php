<?php

namespace Ux1\UxStudio\Modules\SmtpEmail\Mail;

use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailerInterface;
use Joomla\Registry\Registry;

\defined('_JEXEC') or die;

/** Decorates Joomla's mailer factory: the mailer it builds is handed back as a UxMail with the same configuration. */
final class UxMailerFactory implements MailerFactoryInterface
{
    public function __construct(private MailerFactoryInterface $inner)
    {
    }

    public function createMailer(?Registry $settings = null): MailerInterface
    {
        $mailer = $this->inner->createMailer($settings);

        return $mailer instanceof UxMail ? $mailer : MailHub::wrap($mailer);
    }
}

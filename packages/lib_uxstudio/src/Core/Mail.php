<?php

namespace Ux1\UxStudio\Core;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\Mail as JoomlaMail;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\MailHub;

\defined('_JEXEC') or die;

/**
 * The one way for modules to send e-mail. Messages go through the shared MailHub, so they use the connection of the
 * smtp-email module (SMTP / Brevo, fallback, From override) and are recorded by email-log when those modules are on.
 * Both modules are optional: with them switched off the hub passes the message to the native Joomla mailer unchanged.
 *
 *   Mail::send(['to' => $address, 'subject' => $subject, 'body' => $html, 'html' => true, 'source' => 'UX1 Studio: Forms']);
 *   $mailer = Mail::create();   // a Joomla mailer (UxMail) for anything the array form cannot express
 */
final class Mail
{
    private static string $lastError = '';

    /**
     * A Joomla mailer routed through the hub. The hub (swap of the container's mailer factory) is installed only by the
     * smtp-email and email-log modules while they are on; with both off this is the native mailer wrapped without touching the
     * container. Falls back to the plain native mailer when the smtp-email code is missing or broken.
     */
    public static function create(): JoomlaMail
    {
        try {
            if (class_exists(MailHub::class)) {
                return MailHub::createMailer();
            }
        } catch (\Throwable $e) {
            Log::add('Mail hub unavailable, using the native mailer: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }

        return Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
    }

    /**
     * Sends one message. Never throws.
     *
     * @param array{to:string|string[],subject:string,body:string,html?:bool,from?:string,from_name?:string,reply_to?:string,cc?:string[],bcc?:string[],attachments?:array<int,string|array{path:string,name?:string}>,alt_body?:string,source?:string} $message
     *        source = label shown in the e-mail log as the origin of the message (default: detected from the caller)
     * @return bool true when the message was accepted for delivery; the reason of a failure is in lastError()
     */
    public static function send(array $message): bool
    {
        self::$lastError = '';
        $hub             = class_exists(MailHub::class);
        $previous        = $hub ? MailHub::sourceOverride() : '';
        try {
            $mailer = self::create();
            if (($message['from'] ?? '') !== '' && filter_var($message['from'], FILTER_VALIDATE_EMAIL)) {
                $mailer->setSender([(string) $message['from'], (string) ($message['from_name'] ?? '')]);
            }
            foreach ((array) $message['to'] as $to) {
                $mailer->addRecipient((string) $to);
            }
            foreach ((array) ($message['cc'] ?? []) as $cc) {
                $mailer->addCc((string) $cc);
            }
            foreach ((array) ($message['bcc'] ?? []) as $bcc) {
                $mailer->addBcc((string) $bcc);
            }
            if (($message['reply_to'] ?? '') !== '' && filter_var($message['reply_to'], FILTER_VALIDATE_EMAIL)) {
                $mailer->addReplyTo((string) $message['reply_to']);
            }
            $mailer->setSubject((string) $message['subject']);
            $mailer->isHtml(!empty($message['html']));
            $mailer->setBody((string) $message['body']);
            if (($message['alt_body'] ?? '') !== '') {
                $mailer->AltBody = (string) $message['alt_body'];
            }
            foreach ((array) ($message['attachments'] ?? []) as $file) {
                $path = \is_array($file) ? (string) ($file['path'] ?? '') : (string) $file;
                $name = \is_array($file) ? (string) ($file['name'] ?? '') : '';
                if ($path !== '' && is_file($path)) {
                    $mailer->addAttachment($path, $name);
                }
            }
            if ($hub && ($message['source'] ?? '') !== '') {
                MailHub::overrideSource((string) $message['source']);
            }

            if ($mailer->send() === true) {
                return true;
            }
            self::$lastError = (string) $mailer->ErrorInfo ?: 'The mailer refused the message.';
        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
        } finally {
            if ($hub) {
                MailHub::overrideSource($previous);
            }
        }
        Log::add('Mail failed: ' . self::$lastError, Log::WARNING, 'uxstudio');

        return false;
    }

    /** Why the last send() failed ('' after a success). */
    public static function lastError(): string
    {
        return self::$lastError;
    }
}

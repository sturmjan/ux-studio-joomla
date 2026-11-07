<?php

namespace Ux1\UxStudio\Modules\SmtpEmail\Mail;

use Joomla\CMS\Extension\ExtensionHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Mail\Mail;
use Joomla\CMS\Mail\MailerFactoryAwareInterface;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\DI\Container;
use Joomla\DI\ContainerResource;

\defined('_JEXEC') or die;

/**
 * Process-wide switchboard shared by the modules smtp-email (picks the connection), email-log (records messages) and
 * email-health (sends test messages). Joomla has no "mail sent" event, so the hub replaces the mailer factory service in
 * the DI container with UxMailerFactory; both modules may be switched on independently, whichever boots first installs it.
 */
final class MailHub
{
    private static bool $installed = false;

    private static ?MailTransport $transport = null;

    /** @var array<string,MailObserver> */
    private static array $observers = [];

    /** Connection id forced for the next messages (test sends); 'joomla' = the unchanged Joomla mailer. */
    private static ?string $forced = null;

    /** Replaces the detected origin of messages sent right now (resend, test). */
    private static string $sourceOverride = '';

    /**
     * Replaces the protected mailer factory service with the decorating one. Idempotent. The key is protected in the
     * container (Joomla registers it that way), so the resource is swapped directly.
     */
    public static function install(): void
    {
        if (self::$installed) {
            return;
        }
        $container = Factory::getContainer();
        $current   = $container->get(MailerFactoryInterface::class);
        if (!$current instanceof UxMailerFactory) {
            $factory = new UxMailerFactory($current);
            $swap    = \Closure::bind(
                static function (Container $c) use ($factory): void {
                    $c->resources[MailerFactoryInterface::class] = new ContainerResource($c, $factory, ContainerResource::SHARE | ContainerResource::PROTECT);
                },
                null,
                Container::class
            );
            $swap($container);
            self::refreshBootedExtensions($container->get(MailerFactoryInterface::class));
        }
        self::$installed = true;
    }

    /**
     * System plugins (and other extensions) booted before the modules (CMSApplication imports the system group before
     * onAfterInitialise) keep the factory they were given at construction. Hand them the decorating one, otherwise their
     * mail (for example the scheduler notifications of plg_system_tasknotification) bypasses the connection and the log.
     */
    private static function refreshBootedExtensions(MailerFactoryInterface $factory): void
    {
        foreach ((array) (ExtensionHelper::$extensions[PluginInterface::class] ?? []) as $plugin) {
            if ($plugin instanceof MailerFactoryAwareInterface) {
                try {
                    $plugin->setMailerFactory($factory);
                } catch (\Throwable $e) {
                    // A plugin that refuses the factory keeps the old one; nothing else to do.
                }
            }
        }
    }

    public static function registerTransport(?MailTransport $transport): void
    {
        self::$transport = $transport;
    }

    public static function transport(): ?MailTransport
    {
        return self::$transport;
    }

    public static function addObserver(MailObserver $observer): void
    {
        self::$observers[$observer::class] = $observer;
    }

    public static function removeObserver(string $class): void
    {
        unset(self::$observers[$class]);
    }

    public static function forceMailer(?string $id): void
    {
        self::$forced = $id;
    }

    public static function overrideSource(string $source): void
    {
        self::$sourceOverride = $source;
    }

    public static function sourceOverride(): string
    {
        return self::$sourceOverride;
    }

    /** A mailer from the container (the decorated one when installed), always a UxMail. */
    public static function createMailer(): UxMail
    {
        $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();

        return $mailer instanceof UxMail ? $mailer : self::wrap($mailer);
    }

    /**
     * Turns a Joomla mailer into a UxMail by copying its state (the factory builds a plain Mail, so the subclass
     * cannot be requested directly).
     */
    public static function wrap(object $inner): UxMail
    {
        $copy = new UxMail(true);
        for ($class = new \ReflectionClass($inner); $class !== false; $class = $class->getParentClass()) {
            foreach ($class->getProperties() as $prop) {
                if ($prop->isStatic() || $prop->getDeclaringClass()->getName() !== $class->getName() || !$prop->isInitialized($inner)) {
                    continue;
                }
                try {
                    $prop->setValue($copy, $prop->getValue($inner));
                } catch (\Throwable $e) {
                    // Readonly or typed mismatch: keep the default of the new object.
                }
            }
        }

        return $copy;
    }

    /** From override etc. before the message is recorded and sent. */
    public static function prepare(UxMail $mail): void
    {
        self::$transport?->prepare($mail);
    }

    /** @return array<int,array{id:string,kind:string,ready:bool}> */
    public static function steps(): array
    {
        $t = self::$transport;
        if ($t === null) {
            return [];
        }
        if (self::$forced !== null) {
            return self::$forced === 'joomla' ? [] : [['id' => self::$forced, 'kind' => 'test', 'ready' => $t->isReady(self::$forced)]];
        }
        $steps = [];
        foreach (array_values($t->plan()) as $i => $id) {
            $steps[] = ['id' => $id, 'kind' => $i === 0 ? 'primary' : 'fallback', 'ready' => $t->isReady($id)];
        }

        return $steps;
    }

    /** @return array<string,mixed> handles keyed by observer class */
    public static function begin(UxMail $mail): array
    {
        $handles = [];
        if (!self::$observers) {
            return $handles;
        }
        try {
            $snapshot = self::snapshot($mail);
        } catch (\Throwable $e) {
            return $handles;
        }
        foreach (self::$observers as $class => $observer) {
            try {
                $handles[$class] = $observer->begin($mail, $snapshot);
            } catch (\Throwable $e) {
                // Never stop the e-mail because of the log.
            }
        }

        return $handles;
    }

    /**
     * @param array<string,mixed> $handles
     * @param array{ok:bool,error:string,mailer:string,attempts:array<int,array<string,mixed>>} $result
     */
    public static function end(array $handles, array $result): void
    {
        foreach ($handles as $class => $handle) {
            try {
                (self::$observers[$class] ?? null)?->end($handle, $result);
            } catch (\Throwable $e) {
                // see begin()
            }
        }
    }

    /**
     * Everything worth recording about the message in its final state.
     *
     * @return array<string,mixed>
     */
    public static function snapshot(Mail $mail): array
    {
        $list = static function (array $addresses): array {
            $out = [];
            foreach (array_values($addresses) as $a) {
                $out[] = ['email' => (string) ($a[0] ?? ''), 'name' => (string) ($a[1] ?? '')];
            }

            return $out;
        };
        $attachments = [];
        foreach ($mail->getAttachments() as $a) {
            $attachments[] = basename((string) (($a[2] ?? '') !== '' ? $a[2] : ($a[1] ?? '')));
        }

        return [
            'from'           => ['email' => (string) $mail->From, 'name' => (string) $mail->FromName],
            'to'             => $list($mail->getToAddresses()),
            'cc'             => $list($mail->getCcAddresses()),
            'bcc'            => $list($mail->getBccAddresses()),
            'reply_to'       => $list($mail->getReplyToAddresses()),
            'subject'        => (string) $mail->Subject,
            'body'           => (string) $mail->Body,
            'alt_body'       => (string) $mail->AltBody,
            'html'           => $mail->ContentType === 'text/html',
            'attachments'    => $attachments,
            'custom_headers' => $mail->getCustomHeaders(),
        ];
    }
}

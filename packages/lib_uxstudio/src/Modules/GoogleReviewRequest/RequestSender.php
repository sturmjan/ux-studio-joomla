<?php

namespace Ux1\UxStudio\Modules\GoogleReviewRequest;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Builds and sends the review request e-mail, applies the safety rules (block list, cool-down per address, daily limit)
 * and writes every attempt to #__uxstudio_grr_log.
 */
final class RequestSender
{
    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /** Hard safety cap of sent e-mails per hour. Not a setting and not bypassed by anything (protects the reputation of the domain). */
    public const HOURLY_CAP = 200;

    public function __construct(private ModuleContext $ctx)
    {
    }

    /** "Write a review" link: a Place ID becomes the Google write-review URL, a http(s) URL is used as is. */
    public static function resolveLink(string $input): string
    {
        $input = trim($input);
        if (preg_match('/^ChIJ[A-Za-z0-9_-]{20,}$/', $input)) {
            return 'https://search.google.com/local/writereview?placeid=' . $input;
        }
        if (preg_match('#^https?://[^\s<>"\']+$#i', $input) && filter_var($input, FILTER_VALIDATE_URL)) {
            return $input;
        }

        return '';
    }

    /** The link to use: own setting first, then the Place ID of the Review Aggregator (if configured there). */
    public function link(): string
    {
        $link = self::resolveLink((string) $this->ctx->setting('google_review_url', ''));
        if ($link === '') {
            $place = (string) $this->ctx->kernel()->settings()->get('review-aggregator', 'google_url', '');
            $link  = preg_match('/^ChIJ/', trim($place)) ? self::resolveLink($place) : '';
        }

        return $link;
    }

    /** Signed token of one address for the opt-out link (HMAC with the site secret; nothing is stored). */
    public static function unsubscribeToken(string $email): string
    {
        $secret = (string) Factory::getApplication()->get('secret', '');

        return substr(hash_hmac('sha256', 'grr-unsub|' . mb_strtolower(trim($email)), $secret), 0, 40);
    }

    /** Opt-out page address of one recipient; '' for a preview without a recipient. */
    public static function unsubscribeUrl(string $email): string
    {
        if ($email === '') {
            return '';
        }

        return Uri::root() . 'index.php?option=com_uxstudio&task=public.dispatch&format=json&route=google-review-request/unsubscribe&e='
            . rawurlencode(mb_strtolower(trim($email))) . '&t=' . self::unsubscribeToken($email);
    }

    public function isOptedOut(string $email): bool
    {
        $db = $this->ctx->db();

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_grr_optout'))->where($db->quoteName('email') . ' = :e')->bind(':e', $email)
        )->loadResult() > 0;
    }

    public function optOut(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || mb_strlen($email) > 190 || $this->isOptedOut($email)) {
            return;
        }
        $db  = $this->ctx->db();
        $now = Factory::getDate()->toSql();
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_grr_optout'))->columns($db->quoteName(['email', 'created']))->values(':e, :c')->bind(':e', $email)->bind(':c', $now)
        )->execute();
    }

    /**
     * Subject and HTML body with the placeholders {name}, {site}, {review_url}, {unsubscribe_url} filled in. A message without
     * {unsubscribe_url} gets an opt-out line appended (a commercial message must offer a way to refuse further ones).
     *
     * @return array{subject:string,body:string}
     */
    public function compose(string $name, string $email = ''): array
    {
        $site    = (string) $this->ctx->app()->get('sitename', '');
        $link    = $this->link();
        $name    = $this->cleanName($name);
        $fallback = Text::_('UXS_GOOGLE_REVIEW_REQUEST_DEFAULT_NAME');
        $esc     = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $subject = (string) $this->ctx->setting('email_subject', '');
        $subject = str_replace(['{name}', '{site}'], [$name !== '' ? $name : $fallback, $site], $subject);
        $subject = MailHelper::cleanSubject(trim(preg_replace('/\s+/', ' ', strip_tags($subject)) ?? ''));

        $body = (string) $this->ctx->setting('email_body', '');
        // The link may have been percent-encoded inside href by the HTML filter: accept both spellings of the placeholder.
        $body = str_replace(['%7Breview_url%7D', '%7breview_url%7d'], '{review_url}', $body);
        $body  = str_replace(['%7Bunsubscribe_url%7D', '%7bunsubscribe_url%7d'], '{unsubscribe_url}', $body);
        $unsub = self::unsubscribeUrl($email) ?: '#';
        if (!str_contains($body, '{unsubscribe_url}')) {
            $body .= '<p style="font-size:12px;color:#666666">' . Text::sprintf('UXS_GOOGLE_REVIEW_REQUEST_UNSUB_FOOTER', $esc($unsub)) . '</p>';
        }
        $body = str_replace(['{name}', '{site}', '{review_url}', '{unsubscribe_url}'], [$esc($name !== '' ? $name : $fallback), $esc($site), $esc($link), $esc($unsub)], $body);

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Sends one request.
     *
     * @param string $channel manual | bulk | api | test
     * @return array{email:string,status:string,reason:string}
     */
    public function send(string $email, string $name, string $ref, string $channel, ?int $userId, bool $force = false, int $alreadySentInBatch = 0): array
    {
        $email = trim($email);
        $name  = $this->cleanName($name);
        $ref   = mb_substr(trim(strip_tags($ref)), 0, 100);

        if ($email === '' || mb_strlen($email) > 190 || !MailHelper::isEmailAddress($email)) {
            return $this->record($email, $name, self::STATUS_SKIPPED, 'invalid_email', $channel, $ref, $userId, false);
        }
        $key = mb_strtolower($email);

        if ($this->link() === '') {
            return $this->record($key, $name, self::STATUS_SKIPPED, 'no_link', $channel, $ref, $userId);
        }
        // $force (admin checkbox, test) skips ONLY the cool-down. Opt-outs, the block list, the daily limit and the hard hourly
        // cap always apply; the test to the administrator's own address is exempt from the opt-out, block list and daily limit.
        $test = $channel === 'test';
        if (!$test && $this->isOptedOut($key)) {
            return $this->record($key, $name, self::STATUS_SKIPPED, 'unsubscribed', $channel, $ref, $userId);
        }
        if (!$test && $this->blocked($key)) {
            return $this->record($key, $name, self::STATUS_SKIPPED, 'blocked', $channel, $ref, $userId);
        }
        if (!$force) {
            $cooldown = (int) $this->ctx->setting('cooldown_days', 90);
            if ($cooldown > 0 && $this->sentWithin($key, $cooldown * 86400)) {
                return $this->record($key, $name, self::STATUS_SKIPPED, 'cooldown', $channel, $ref, $userId);
            }
        }
        $limit = (int) $this->ctx->setting('daily_limit', 100);
        if (!$test && $limit > 0 && $this->countSent(86400) + $alreadySentInBatch >= $limit) {
            return $this->record($key, $name, self::STATUS_SKIPPED, 'daily_limit', $channel, $ref, $userId);
        }
        if ($this->countSent(3600) + $alreadySentInBatch >= self::HOURLY_CAP) {
            return $this->record($key, $name, self::STATUS_SKIPPED, 'hourly_cap', $channel, $ref, $userId);
        }

        $mail = $this->compose($name, $key);
        if ($mail['subject'] === '' || trim(strip_tags($mail['body'])) === '') {
            return $this->record($key, $name, self::STATUS_SKIPPED, 'empty_message', $channel, $ref, $userId);
        }

        try {
            $mailer = \Ux1\UxStudio\Core\Mail::create();
            $mailer->isHtml(true);
            $mailer->CharSet = 'UTF-8';
            $mailer->addRecipient($key, $name);
            $mailer->setSubject($mail['subject']);
            $mailer->setBody($mail['body']);
            $unsubUrl = self::unsubscribeUrl($key);
            if ($unsubUrl !== '') {
                $mailer->addCustomHeader('List-Unsubscribe', '<' . $unsubUrl . '>');
            }
            $mailer->AltBody = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $mail['body']) ?? ''), ENT_QUOTES, 'UTF-8'));
            $sender = trim((string) $this->ctx->setting('sender_name', ''));
            if ($sender !== '') {
                $mailer->setSender([(string) $this->ctx->app()->get('mailfrom'), MailHelper::cleanLine($sender)]);
            }
            $replyTo = trim((string) $this->ctx->setting('reply_to', ''));
            if ($replyTo !== '' && MailHelper::isEmailAddress($replyTo)) {
                $mailer->addReplyTo($replyTo);
            }
            $ok     = $mailer->Send();
            $reason = $ok === true ? '' : mb_substr((string) ($mailer->ErrorInfo ?: 'send_failed'), 0, 190);
        } catch (\Throwable $e) {
            $ok     = false;
            $reason = mb_substr($e->getMessage(), 0, 190);
        }

        return $this->record($key, $name, $ok === true ? self::STATUS_SENT : self::STATUS_FAILED, $reason, $channel, $ref, $userId);
    }

    private function cleanName(string $name): string
    {
        return mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($name)) ?? ''), 0, 120);
    }

    private function blocked(string $email): bool
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);
        foreach (preg_split('/[\r\n,;\s]+/', mb_strtolower((string) $this->ctx->setting('blocklist', ''))) ?: [] as $entry) {
            $entry = ltrim(trim($entry), '@');
            if ($entry !== '' && ($entry === $email || $entry === $domain)) {
                return true;
            }
        }

        return false;
    }

    private function sentWithin(string $email, int $seconds): bool
    {
        $db    = $this->ctx->db();
        $since = Factory::getDate('-' . $seconds . ' seconds')->toSql();
        $sent  = self::STATUS_SENT;

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_grr_log'))
                ->where($db->quoteName('email') . ' = :e')->where($db->quoteName('status') . ' = :s')->where($db->quoteName('created') . ' >= :c')
                ->bind(':e', $email)->bind(':s', $sent)->bind(':c', $since)
        )->loadResult() > 0;
    }

    private function countSent(int $seconds): int
    {
        $db    = $this->ctx->db();
        $since = Factory::getDate('-' . $seconds . ' seconds')->toSql();
        $sent  = self::STATUS_SENT;

        return (int) $db->setQuery(
            $db->createQuery()->select('COUNT(*)')->from($db->quoteName('#__uxstudio_grr_log'))
                ->where($db->quoteName('status') . ' = :s')->where($db->quoteName('created') . ' >= :c')->bind(':s', $sent)->bind(':c', $since)
        )->loadResult();
    }

    /** @return array{email:string,status:string,reason:string} */
    private function record(string $email, string $name, string $status, string $reason, string $channel, string $ref, ?int $userId, bool $log = true): array
    {
        if ($log) {
            $db  = $this->ctx->db();
            $now = Factory::getDate()->toSql();
            $uid = $userId ?: null;
            $q   = $db->createQuery()->insert($db->quoteName('#__uxstudio_grr_log'))
                ->columns($db->quoteName(['created', 'email', 'name', 'status', 'reason', 'channel', 'ref', 'user_id']))
                ->values(':c, :e, :n, :s, :r, :ch, :ref, :u')
                ->bind(':c', $now)->bind(':e', $email)->bind(':n', $name)->bind(':s', $status)->bind(':r', $reason)->bind(':ch', $channel)->bind(':ref', $ref)
                ->bind(':u', $uid, $uid === null ? ParameterType::NULL : ParameterType::INTEGER);
            $db->setQuery($q)->execute();
        }

        return ['email' => $email, 'status' => $status, 'reason' => $reason];
    }
}

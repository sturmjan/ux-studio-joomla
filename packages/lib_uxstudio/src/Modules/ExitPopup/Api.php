<?php

namespace Ux1\UxStudio\Modules\ExitPopup;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;
use Ux1\UxStudio\Modules\PopupManager\Support;

\defined('_JEXEC') or die;

/** Route handlers of the exit-popup module. */
final class Api
{
    public function __construct(private ModuleContext $ctx)
    {
    }

    private function subscribers(): Subscribers
    {
        return new Subscribers($this->ctx->db());
    }

    /** GET subscribers?page=1&q=text */
    public function list(ApiRequest $r): array
    {
        return $this->subscribers()->page($r->int('page', 1), Support::text($r->query('q', ''), 100));
    }

    /** GET subscribers/export - every row (max 50 000) for the CSV the browser builds. */
    public function export(ApiRequest $r): array
    {
        return ['rows' => $this->subscribers()->everything()];
    }

    /** POST subscribers/{id}/delete */
    public function delete(ApiRequest $r): array
    {
        if (!$this->subscribers()->delete((int) $r->param('id'))) {
            throw new ApiException(Text::_('UXS_EXIT_POPUP_ERR_NOT_FOUND'), 404, 'not_found');
        }

        return ['deleted' => true];
    }

    /** POST subscribers/purge (core.admin) - removes every captured address. */
    public function purge(ApiRequest $r): array
    {
        return ['deleted' => $this->subscribers()->purge()];
    }

    /** GET preview-url */
    public function previewUrl(ApiRequest $r): array
    {
        return ['url' => Support::previewUrl('exit', 1)];
    }

    /** PUBLIC POST subscribe {email, consent?, page_url?, website (honeypot)} */
    public function subscribe(ApiRequest $r): array
    {
        $s = $this->ctx->settings();
        if (empty($s['active']) || empty($s['enable_email'])) {
            throw new ApiException(Text::_('UXS_EXIT_POPUP_ERR_NOT_FOUND'), 404, 'not_found');
        }
        $now   = time();
        $from  = Support::localToTimestamp($s['starts_at'] ?? '');
        $until = Support::localToTimestamp($s['ends_at'] ?? '');
        if (($from && $now < $from) || ($until && $now > $until)) {
            throw new ApiException(Text::_('UXS_EXIT_POPUP_ERR_NOT_FOUND'), 404, 'not_found');
        }
        // Honeypot filled in: pretend everything worked, store nothing.
        if ($r->string('website') !== '') {
            return ['success' => true];
        }
        $email = strtolower(trim($r->string('email')));
        // An IDN domain (příklad.cz) is stored in its punycode form; a diacritic in the local part is allowed.
        if (str_contains($email, '@') && \function_exists('idn_to_ascii')) {
            [$local, $domain] = explode('@', $email, 2) + [1 => ''];
            $ascii = $domain !== '' ? idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;
            if (\is_string($ascii) && $ascii !== '') {
                $email = $local . '@' . $ascii;
            }
        }
        if ($email === '' || \strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE)) {
            throw new ApiException(Text::_('UXS_EXIT_POPUP_MSG_INVALID'), 422, 'invalid_email');
        }
        if (Support::text($s['consent_text'] ?? '', 300) !== '' && !$r->bool('consent')) {
            throw new ApiException(Text::_('UXS_EXIT_POPUP_MSG_CONSENT'), 422, 'consent_required');
        }
        $page = trim($r->string('page_url'));
        $page = preg_match('#^https?://#i', $page) ? mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $page) ?? '', 0, 500) : '';

        $ip     = \Ux1\UxStudio\Core\ClientIp::current();
        $ipHash = hash_hmac('sha256', $ip, (string) $this->ctx->app()->get('secret', ''));

        // Idempotent: an existing address answers like a new one, so the form cannot be used to probe subscriptions.
        if ($this->subscribers()->add($email, $page, $ipHash, Support::text($s['consent_text'] ?? '', 300) !== '')) {
            $this->autoresponder($s, $email);
        }

        return ['success' => true];
    }

    /** @param array<string,mixed> $s */
    private function autoresponder(array $s, string $email): void
    {
        if (empty($s['enable_autoresponder'])) {
            return;
        }
        $subject = Support::text($s['autoresponder_subject'] ?? '', 200);
        $body    = Support::html($s['autoresponder_body'] ?? '', 20000);
        if ($subject === '' || trim(strip_tags($body)) === '') {
            return;
        }
        // Sitewide cap so the form cannot be turned into a mail cannon.
        if ((new RateLimiter($this->ctx->db()))->hit('exit-popup:autoresponder', 30, 3600) > 0) {
            return;
        }
        try {
            $mailer = \Ux1\UxStudio\Core\Mail::create();
            $mailer->addRecipient($email);
            $mailer->setSubject($subject);
            if (method_exists($mailer, 'isHtml')) {
                $mailer->isHtml(true);
            }
            $mailer->setBody($body);
            $mailer->send();
        } catch (\Throwable $e) {
            Log::add('Exit popup autoresponder failed: ' . $e->getMessage(), Log::WARNING, 'uxstudio');
        }
    }
}

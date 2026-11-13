<?php

namespace Ux1\UxStudio\Modules\EmailHealth;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Mail\MailHelper;
use Joomla\Database\ParameterType;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\BaseModule;
use Ux1\UxStudio\Core\Kernel;
use Ux1\UxStudio\Core\ModuleContext;
use Ux1\UxStudio\Core\RateLimiter;
use Ux1\UxStudio\Modules\SmtpEmail\Mail\MailHub;

\defined('_JEXEC') or die;

/**
 * Two questions about outgoing mail: "does a message leave the site?" (test e-mail through the same mailer as all other
 * mail, so the smtp-email connection and the e-mail log apply) and "is the sender domain set up so receivers trust it?"
 * (MX / SPF / DKIM / DMARC in DNS). Results are stored in #__uxstudio_email_health and served to the Central App.
 */
final class Module extends BaseModule
{
    public function boot(ModuleContext $ctx): void
    {
        // Nothing to hook: tests are started from the API or the scheduler.
    }

    public function acl(): string
    {
        // Sends mail from the real sender identity and runs DNS lookups of arbitrary domains: administrators only.
        return 'core.admin';
    }

    public function settingsSchema(): array
    {
        return [
            ['key' => 'test_recipient', 'type' => 'text', 'label' => 'UXS_EMAIL_HEALTH_RECIPIENT', 'help' => 'UXS_EMAIL_HEALTH_RECIPIENT_HELP', 'default' => '', 'maxlength' => 190],
            ['key' => 'auto_test', 'type' => 'toggle', 'label' => 'UXS_EMAIL_HEALTH_AUTO_TEST', 'help' => 'UXS_EMAIL_HEALTH_AUTO_TEST_HELP', 'default' => false],
            ['key' => 'test_interval', 'type' => 'select', 'label' => 'UXS_EMAIL_HEALTH_INTERVAL', 'default' => '24', 'show_if' => ['key' => 'auto_test', 'truthy' => true],
             'options' => ['1' => 'UXS_EMAIL_HEALTH_EVERY_HOUR', '3' => 'UXS_EMAIL_HEALTH_EVERY_3H', '6' => 'UXS_EMAIL_HEALTH_EVERY_6H', '12' => 'UXS_EMAIL_HEALTH_EVERY_12H', '24' => 'UXS_EMAIL_HEALTH_EVERY_DAY']],
            ['key' => 'dns_domain', 'type' => 'text', 'section' => 'UXS_EMAIL_HEALTH_SECTION_DNS', 'label' => 'UXS_EMAIL_HEALTH_DNS_DOMAIN', 'help' => 'UXS_EMAIL_HEALTH_DNS_DOMAIN_HELP', 'default' => '', 'maxlength' => 190],
            ['key' => 'dkim_selectors', 'type' => 'text', 'section' => 'UXS_EMAIL_HEALTH_SECTION_DNS', 'label' => 'UXS_EMAIL_HEALTH_DKIM_SELECTORS', 'help' => 'UXS_EMAIL_HEALTH_DKIM_SELECTORS_HELP', 'default' => '', 'maxlength' => 400],
            ['key' => 'auto_dns', 'type' => 'toggle', 'section' => 'UXS_EMAIL_HEALTH_SECTION_DNS', 'label' => 'UXS_EMAIL_HEALTH_AUTO_DNS', 'help' => 'UXS_EMAIL_HEALTH_AUTO_DNS_HELP', 'default' => true],
        ];
    }

    public function migrations(): array
    {
        return [
            1 => [
                'CREATE TABLE IF NOT EXISTS `#__uxstudio_email_health` ('
                . '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT, `created` DATETIME NOT NULL, `kind` VARCHAR(10) NOT NULL DEFAULT \'\', '
                . '`success` TINYINT(1) NOT NULL DEFAULT 0, `summary` VARCHAR(255) NOT NULL DEFAULT \'\', `data` MEDIUMTEXT NULL, '
                . 'PRIMARY KEY (`id`), KEY `idx_kind_created` (`kind`, `created`)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci',
            ],
        ];
    }

    public function retention(): array
    {
        return [['table' => '#__uxstudio_email_health', 'column' => 'created', 'days' => 180]];
    }

    public function tasks(): array
    {
        return [
            // Hourly tick; the configured interval (1-24 h) is enforced in the callable.
            'test' => ['label' => 'UXS_EMAIL_HEALTH_JOB_TEST', 'callable' => [$this, 'scheduledTest'], 'interval' => 'PT1H'],
            'dns'  => ['label' => 'UXS_EMAIL_HEALTH_JOB_DNS', 'callable' => [$this, 'scheduledDns'], 'interval' => 'P1D'],
        ];
    }

    public function routes(): array
    {
        return [
            'GET status' => [$this, 'status'],
            'POST test'  => [$this, 'test'],
            'POST dns'   => [$this, 'dns'],
        ];
    }

    public function dashboardWidget(): ?array
    {
        try {
            $test = $this->last('test');
            $dns  = $this->last('dns');
        } catch (\Throwable $e) {
            return null;
        }
        if (!$test) {
            return ['title' => Text::_('UXS_EMAIL_HEALTH_NAME'), 'value' => '-', 'text' => Text::_('UXS_EMAIL_HEALTH_WIDGET_NONE'), 'status' => 'warn'];
        }
        $dnsOverall = (string) ($dns['data']['overall'] ?? '');
        $status     = !$test['success'] ? 'bad' : (\in_array($dnsOverall, ['warn', 'bad'], true) ? 'warn' : 'ok');

        return [
            'title'  => Text::_('UXS_EMAIL_HEALTH_NAME'),
            'value'  => Text::_($test['success'] ? 'UXS_EMAIL_HEALTH_WIDGET_OK' : 'UXS_EMAIL_HEALTH_WIDGET_FAIL'),
            'text'   => $dnsOverall !== '' ? Text::sprintf('UXS_EMAIL_HEALTH_WIDGET_DNS', Text::_('UXS_EMAIL_HEALTH_STATUS_' . strtoupper($dnsOverall))) : Text::_('UXS_EMAIL_HEALTH_WIDGET_NO_DNS'),
            'status' => $status,
        ];
    }

    /**
     * Central App feed for route "email-health" (shape of the WordPress endpoint plus a "dns" summary).
     *
     * @return array<string,mixed>
     */
    public function caFeed(string $route, array $query, array $body): array
    {
        if ($route !== 'email-health') {
            return [];
        }
        $test   = $this->last('test');
        $dns    = $this->last('dns');
        $dnsOut = null;
        if ($dns) {
            $dnsOut = ['domain' => (string) ($dns['data']['domain'] ?? ''), 'checked_at' => $this->siteTime((string) $dns['created']), 'overall' => (string) ($dns['data']['overall'] ?? '')];
            foreach ((array) ($dns['data']['checks'] ?? []) as $c) {
                $dnsOut[(string) $c['id']] = (string) $c['status'];
            }
        }
        if (!$test) {
            return ['available' => false, 'success' => null, 'error' => null, 'method' => null, 'tested_at' => null, 'age_hours' => null, 'mail_tester' => null, 'dns' => $dnsOut];
        }

        return [
            'available'   => true,
            'success'     => (bool) $test['success'],
            'error'       => ($test['data']['error'] ?? '') !== '' ? (string) $test['data']['error'] : null,
            'method'      => ($test['data']['method'] ?? '') !== '' ? (string) $test['data']['method'] : null,
            'tested_at'   => $this->siteTime((string) $test['created']),
            'tested_at_iso' => (new \DateTimeImmutable((string) $test['created'], new \DateTimeZone('UTC')))->format(\DATE_ATOM),
            'age_hours'   => round((time() - strtotime($test['created'] . ' UTC')) / 3600, 1),
            'mail_tester' => null,
            'dns'         => $dnsOut,
        ];
    }

    /* ---- API ---- */

    /** GET email-health/status */
    public function status(ApiRequest $r): array
    {
        $sender = $this->senderAddress();

        return [
            'test'    => $this->last('test'),
            'dns'     => $this->localizedDns($this->last('dns')),
            'history' => $this->history('test', 15),
            'sender'  => ['email' => $sender, 'domain' => $this->domain($sender)],
            'logging' => Kernel::instance()->registry()->isEnabled('email-log'),
        ];
    }

    /** POST email-health/test {recipient?} */
    public function test(ApiRequest $r): array
    {
        $this->throttle($r, 'test', 5);
        $to = trim($r->string('recipient'));

        return $this->runTest($to !== '' ? $to : $this->defaultRecipient((string) $r->user()->email), true);
    }

    /** POST email-health/dns {domain?} */
    public function dns(ApiRequest $r): array
    {
        $this->throttle($r, 'dns', 10);
        $domain = strtolower(trim($r->string('domain')));
        if ($domain === '') {
            $domain = $this->domain($this->senderAddress());
        }

        return $this->localizedDns($this->runDns($domain)) ?? [];
    }

    /** @param array<string,mixed>|null $row */
    private function localizedDns(?array $row): ?array
    {
        if ($row) {
            $row['data'] = DnsCheck::localize($row['data']);
        }

        return $row;
    }

    /** At most $limit runs per 10 minutes and user. */
    private function throttle(ApiRequest $r, string $what, int $limit): void
    {
        $wait = (new RateLimiter($this->ctx()->db()))->hit('email-health:' . $what . ':' . (int) $r->user()->id, $limit, 600);
        if ($wait > 0) {
            throw new ApiException(Text::sprintf('UXS_EMAIL_HEALTH_ERR_RATE', $wait), 429, 'rate_limited');
        }
    }

    /** Stored UTC time as the site-local time (the WordPress feed sends current_time('mysql')). */
    private function siteTime(string $utc): string
    {
        try {
            $tz = new \DateTimeZone((string) Factory::getApplication()->get('offset', 'UTC'));

            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($tz)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return $utc;
        }
    }

    /* ---- Scheduler jobs ---- */

    public function scheduledTest(): string
    {
        $ctx = $this->ctx();
        if (!$ctx->setting('auto_test', false)) {
            return 'skipped: scheduled test is off';
        }
        $hours = max(1, (int) $ctx->setting('test_interval', 24));
        $last  = $this->lastOfKind('scheduled');
        if ($last && time() - strtotime($last['created'] . ' UTC') < $hours * 3600 - 120) {
            return 'skipped: last scheduled test is newer than ' . $hours . ' h';
        }
        $res = $this->runTest($this->defaultRecipient(''), false, 'scheduled');

        return $res['success'] ? 'ok: test e-mail sent' : 'failed: ' . $res['error'];
    }

    public function scheduledDns(): string
    {
        if (!$this->ctx()->setting('auto_dns', true)) {
            return 'skipped: scheduled DNS check is off';
        }
        $res = $this->runDns($this->domain($this->senderAddress()));

        return 'DNS ' . $res['data']['domain'] . ': ' . $res['data']['overall'];
    }

    /* ---- Work ---- */

    /**
     * Sends the test e-mail through the normal mailer and stores the outcome.
     *
     * @return array{success:bool,error:string,method:string,recipient:string,tested_at:string,mailer:string,attempts:array<int,array<string,mixed>>}
     */
    public function runTest(string $recipient, bool $strict, string $kind = 'test'): array
    {
        if (!MailHelper::isEmailAddress($recipient)) {
            if ($strict) {
                throw new ApiException(Text::_('UXS_EMAIL_HEALTH_ERR_RECIPIENT'), 422, 'invalid_recipient');
            }

            return $this->saveTest($kind, false, Text::_('UXS_EMAIL_HEALTH_ERR_RECIPIENT'), '', $recipient, '', []);
        }

        $app  = Factory::getApplication();
        $site = (string) $app->get('sitename', '');
        MailHub::overrideSource('UX1 Studio: ' . Text::_('UXS_EMAIL_HEALTH_NAME'));
        $error  = '';
        $ok     = false;
        $result = null;
        $mail   = null;
        try {
            $mail = MailHub::createMailer();
            $mail->addRecipient($recipient);
            $mail->setSubject(Text::sprintf('UXS_EMAIL_HEALTH_TEST_SUBJECT', $site, Factory::getDate()->format('Y-m-d H:i')));
            $mail->isHtml(false);
            $mail->setBody(Text::sprintf('UXS_EMAIL_HEALTH_TEST_BODY', $site, Factory::getDate()->format('Y-m-d H:i:s')));
            $ok = (bool) $mail->Send();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            MailHub::overrideSource('');
        }
        $result = $mail?->uxResult;
        if (!$ok && $error === '') {
            $error = (string) ($result['error'] ?? '');
        }

        $mailer = (string) ($result['mailer'] ?? '');
        $method = $mailer;
        if ($mailer === 'smtp') {
            $method = 'smtp (' . trim((string) Kernel::instance()->settings()->get('smtp-email', 'host', '')) . ')';
        } elseif ($mailer === 'brevo') {
            $method = 'brevo (API)';
        } elseif (str_starts_with($mailer, 'joomla/')) {
            $method = 'joomla ' . substr($mailer, 7);
        }

        return $this->saveTest($kind, $ok, $ok ? '' : $error, $method, $recipient, $mailer, $result['attempts'] ?? []);
    }

    /**
     * @return array{kind:string,created:string,success:bool,summary:string,data:array<string,mixed>}
     */
    public function runDns(string $domain): array
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        // Internationalised names (háček.cz) are looked up in their punycode form.
        if ($domain !== '' && !mb_check_encoding($domain, 'ASCII') && \function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (\is_string($ascii) && $ascii !== '') {
                $domain = $ascii;
            }
        }
        if (!DnsCheck::validDomain($domain)) {
            throw new ApiException(Text::_('UXS_EMAIL_HEALTH_ERR_DOMAIN'), 422, 'invalid_domain');
        }
        $selectors = array_values(array_filter(array_map(
            static fn (string $s): string => strtolower(trim($s)),
            explode(',', (string) $this->ctx()->setting('dkim_selectors', ''))
        ), static fn (string $s): bool => (bool) preg_match('/^[a-z0-9]([a-z0-9._-]{0,60}[a-z0-9])?$/', $s)));

        // A provider that sends the mail must be authorised in SPF.
        $expect = [];
        $kernel = Kernel::instance();
        $smtp   = $kernel->registry()->isEnabled('smtp-email') ? $kernel->settings()->all('smtp-email') : [];
        if ($smtp && (($smtp['mailer'] ?? '') === 'brevo' || ($smtp['fallback_mailer'] ?? '') === 'brevo') && (string) ($smtp['brevo_api_key'] ?? '') !== '') {
            $expect[] = 'brevo.com';
        }

        $data    = (new DnsCheck($selectors, $expect))->run($domain);
        $summary = $domain . ': ' . $data['overall'];
        $this->store('dns', $data['overall'] !== 'bad', $summary, $data);

        return ['kind' => 'dns', 'created' => $data['checked_at'], 'success' => $data['overall'] !== 'bad', 'summary' => $summary, 'data' => $data];
    }

    /** @return array{success:bool,error:string,method:string,recipient:string,tested_at:string,mailer:string,attempts:array<int,array<string,mixed>>} */
    private function saveTest(string $kind, bool $ok, string $error, string $method, string $recipient, string $mailer, array $attempts): array
    {
        $data = ['error' => $error, 'method' => $method, 'recipient' => $recipient, 'mailer' => $mailer, 'attempts' => $attempts];
        $this->store($kind, $ok, $ok ? $method : mb_substr($error, 0, 200), $data);

        return ['success' => $ok, 'error' => $error, 'method' => $method, 'recipient' => $recipient, 'tested_at' => Factory::getDate()->toSql(), 'mailer' => $mailer, 'attempts' => $attempts];
    }

    private function store(string $kind, bool $ok, string $summary, array $data): void
    {
        $db      = $this->ctx()->db();
        $now     = Factory::getDate()->toSql();
        $flag    = $ok ? 1 : 0;
        $json    = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
        $summary = mb_substr($summary, 0, 255);
        $db->setQuery(
            $db->createQuery()->insert($db->quoteName('#__uxstudio_email_health'))
                ->columns($db->quoteName(['created', 'kind', 'success', 'summary', 'data']))->values(':c, :k, :s, :m, :d')
                ->bind(':c', $now)->bind(':k', $kind)->bind(':s', $flag, ParameterType::INTEGER)->bind(':m', $summary)->bind(':d', $json)
        )->execute();
    }

    /**
     * Latest stored result of a group: "test" = newest manual OR scheduled test, "dns" = newest DNS check.
     *
     * @return array{kind:string,created:string,success:bool,summary:string,data:array<string,mixed>}|null
     */
    private function last(string $group): ?array
    {
        return $this->history($group, 1)[0] ?? null;
    }

    /** @return array{kind:string,created:string,success:bool,summary:string,data:array<string,mixed>}|null */
    private function lastOfKind(string $kind): ?array
    {
        return $this->history($kind, 1)[0] ?? null;
    }

    /** @return array<int,array{kind:string,created:string,success:bool,summary:string,data:array<string,mixed>}> newest first */
    private function history(string $group, int $limit): array
    {
        $db    = $this->ctx()->db();
        $kinds = $group === 'test' ? ['test', 'scheduled'] : [$group];
        $query = $db->createQuery()->select($db->quoteName(['kind', 'created', 'success', 'summary', 'data']))
            ->from($db->quoteName('#__uxstudio_email_health'))
            ->whereIn($db->quoteName('kind'), $kinds, ParameterType::STRING)
            ->order($db->quoteName('id') . ' DESC');
        $rows = $db->setQuery($query, 0, $limit)->loadAssocList() ?: [];

        return array_map(static function (array $row): array {
            $data = json_decode((string) $row['data'], true);

            return ['kind' => (string) $row['kind'], 'created' => (string) $row['created'], 'success' => (bool) $row['success'], 'summary' => (string) $row['summary'], 'data' => \is_array($data) ? $data : []];
        }, $rows);
    }

    private function defaultRecipient(string $fallback): string
    {
        $to = trim((string) $this->ctx()->setting('test_recipient', ''));
        if ($to === '') {
            $to = $fallback !== '' ? $fallback : (string) Factory::getApplication()->get('mailfrom', '');
        }

        return $to;
    }

    /** Effective From address of outgoing mail (after the smtp-email override). */
    private function senderAddress(): string
    {
        $from = '';
        try {
            $mail = MailHub::createMailer();
            MailHub::prepare($mail);
            $from = (string) $mail->From;
        } catch (\Throwable $e) {
            // fall back to the global configuration below
        }

        return $from !== '' ? $from : (string) Factory::getApplication()->get('mailfrom', '');
    }

    private function domain(string $email): string
    {
        $override = strtolower(trim((string) $this->ctx()->setting('dns_domain', '')));
        if ($override !== '') {
            return $override;
        }
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }
}

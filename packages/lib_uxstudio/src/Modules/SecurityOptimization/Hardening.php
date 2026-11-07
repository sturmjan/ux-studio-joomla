<?php

namespace Ux1\UxStudio\Modules\SecurityOptimization;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Ux1\UxStudio\Core\Http;

\defined('_JEXEC') or die;

/**
 * Read-only inspection of the Joomla installation: hardening posture and information leaks.
 * Everything here only REPORTS. Nothing is written, moved or deleted; the .htaccess rules are offered as text.
 */
final class Hardening
{
    /** Files of a stock Joomla package that tell the world the CMS and its version. */
    private const LEAKY_FILES = [
        'administrator/manifests/files/joomla.xml' => 'version',
        'language/en-GB/langmetadata.xml'          => 'version',
        'README.txt'                               => 'cms',
        'LICENSE.txt'                              => 'cms',
        'htaccess.txt'                             => 'cms',
        'web.config.txt'                           => 'cms',
        'robots.txt.dist'                          => 'cms',
    ];

    public function __construct(private DatabaseInterface $db, private object $app)
    {
    }

    /**
     * Posture checks. Each: id, status (ok|warn|bad), label (language key), detail.
     *
     * @param array<string,mixed> $settings module settings (the module's own protections count as checks too)
     * @return array<int,array{id:string,status:string,label:string,detail:string}>
     */
    public function checks(array $settings): array
    {
        $out = [];
        $add = static function (string $id, string $status, string $detail = '') use (&$out): void {
            $out[] = ['id' => $id, 'status' => $status, 'label' => Text::_('UXS_SECURITY_OPTIMIZATION_CHECK_' . strtoupper($id)), 'detail' => $detail];
        };
        $get = fn (string $key, mixed $default = null): mixed => $this->app->get($key, $default);

        $debug = (bool) $get('debug');
        $add('debug', $debug ? 'bad' : 'ok', $debug ? 'debug=1' : '');

        $reporting = (string) $get('error_reporting', 'default');
        $add('error_reporting', \in_array($reporting, ['maximum', 'development'], true) ? 'warn' : 'ok', $reporting);

        $conf = JPATH_CONFIGURATION . '/configuration.php';
        $add('config_writable', is_file($conf) && is_writable($conf) ? 'warn' : 'ok', is_file($conf) && is_writable($conf) ? 'configuration.php' : '');

        $install = is_dir(JPATH_ROOT . '/installation');
        $add('installation_dir', $install ? 'bad' : 'ok', $install ? 'installation/' : '');

        $leaky = $this->presentLeakyFiles();
        $add('leaky_files', $leaky ? 'warn' : 'ok', implode(', ', $leaky));

        $ssl = (int) $get('force_ssl', 0);
        $add('force_ssl', $ssl === 2 ? 'ok' : 'warn', 'force_ssl=' . $ssl);

        $add('php_version', version_compare(PHP_VERSION, '8.2', '<') ? 'bad' : (version_compare(PHP_VERSION, '8.3', '<') ? 'warn' : 'ok'), PHP_VERSION);

        $admins = $this->superUsers();
        $noMfa  = array_filter($admins, static fn (array $u): bool => !$u['mfa']);
        $add('super_users', \count($admins) > 3 ? 'warn' : 'ok', (string) \count($admins));
        $add('super_users_mfa', $noMfa ? 'warn' : 'ok', implode(', ', array_column($noMfa, 'username')));

        $weak = array_filter($admins, static fn (array $u): bool => \in_array(strtolower($u['username']), ['admin', 'administrator', 'root', 'superuser'], true));
        $add('admin_username', $weak ? 'warn' : 'ok', implode(', ', array_column($weak, 'username')));

        $users   = ComponentHelper::getParams('com_users');
        $regOpen = (int) $users->get('allowUserRegistration', 0) === 1;
        $add('registration', $regOpen ? 'warn' : 'ok', $regOpen ? 'allowUserRegistration=1' : '');

        $add('headers', !empty($settings['security_headers']) ? 'ok' : 'warn');
        $add('generator', !empty($settings['hide_generator']) ? 'ok' : 'warn');
        $csp = (string) ($settings['csp_mode'] ?? 'off');
        $add('csp', $csp === 'enforce' ? 'ok' : ($csp === 'report-only' ? 'warn' : 'warn'), $csp);
        $add('login_limit', !empty($settings['limit_login_attempts']) ? 'ok' : 'warn');
        $add('firewall', !empty($settings['ip_firewall_enabled']) ? 'ok' : 'warn');

        return $out;
    }

    /** Files of the stock package that are still in the web root. @return string[] */
    public function presentLeakyFiles(): array
    {
        $found = [];
        foreach (array_keys(self::LEAKY_FILES) as $file) {
            if (is_file(JPATH_ROOT . '/' . $file)) {
                $found[] = $file;
            }
        }

        return $found;
    }

    /**
     * Asks the web server (HEAD on the site's own address) whether the leaky files are really served.
     * Local, fixed URLs only. The result is what a visitor would get.
     *
     * @return array<int,array{file:string,kind:string,status:int,exposed:bool}>
     */
    public function probe(): array
    {
        $root = $this->trustedRoot();
        $http = new Http();
        $out  = [];
        foreach ($this->presentLeakyFiles() as $file) {
            $status = 0;
            try {
                $status = (int) $http->request('HEAD', $root . $file, ['timeout' => 4, 'allow_private' => true])['status'];
            } catch (\Throwable $e) {
                // Unreachable from the server itself (firewall / loopback): reported as unknown (0).
            }
            $out[] = ['file' => $file, 'kind' => self::LEAKY_FILES[$file], 'status' => $status, 'exposed' => $status === 200];
        }

        return $out;
    }

    /**
     * Site root URL that does not depend on the request's Host header: live_site when configured, otherwise Uri::root() with
     * the host replaced by the server's own name (a forged Host cannot aim the probe at a foreign server).
     */
    private function trustedRoot(): string
    {
        $live = trim((string) \Joomla\CMS\Factory::getApplication()->get('live_site', ''));
        if ($live !== '') {
            return rtrim($live, '/') . '/';
        }
        $root  = Uri::root();
        $parts = parse_url($root);
        $name  = (string) ($_SERVER['SERVER_NAME'] ?? '');
        if (!\is_array($parts) || !isset($parts['host']) || $name === '' || strcasecmp($parts['host'], $name) === 0) {
            return $root;
        }
        if (!preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $name)) {
            return $root;
        }

        return ($parts['scheme'] ?? 'http') . '://' . $name . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/');
    }

    /** Recommended Apache rules. Offered as text only: nothing is written to the server. */
    public function htaccessSnippet(): string
    {
        return implode("\n", [
            '# UX Studio - recommended Joomla hardening (review before pasting into .htaccess; UX Studio never edits this file)',
            '<IfModule mod_autoindex.c>',
            '    Options -Indexes',
            '</IfModule>',
            '<FilesMatch "^(README\.txt|LICENSE\.txt|htaccess\.txt|web\.config\.txt|robots\.txt\.dist|configuration\.php-dist|joomla\.xml|langmetadata\.xml)$">',
            '    <IfModule mod_authz_core.c>',
            '        Require all denied',
            '    </IfModule>',
            '    <IfModule !mod_authz_core.c>',
            '        Order allow,deny',
            '        Deny from all',
            '    </IfModule>',
            '</FilesMatch>',
            '# The Joomla manifests are only read by the administrator app on the server side',
            'RewriteEngine On',
            'RewriteRule ^administrator/manifests/ - [F,L]',
            '# No PHP execution in upload / temp folders',
            'RewriteRule ^(images|tmp|media/cache)/.*\.(php|phtml|phar|php[0-9])$ - [F,L]',
            '',
        ]);
    }

    /** @return array<int,array{id:int,username:string,mfa:bool,lastvisit:?string}> */
    public function superUsers(): array
    {
        $db = $this->db;
        try {
            $rows = $db->setQuery(
                $db->createQuery()->select('DISTINCT u.id, u.username, u.lastvisitDate')->from($db->quoteName('#__users', 'u'))
                    ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm') . ' ON m.user_id = u.id')
                    ->where('m.group_id = 8')->where('u.block = 0')
            )->loadAssocList() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
        $mfa = [];
        try {
            $ids = array_map('intval', array_column($rows, 'id'));
            if ($ids) {
                $mfa = $db->setQuery(
                    $db->createQuery()->select('DISTINCT user_id')->from($db->quoteName('#__user_mfa'))->whereIn($db->quoteName('user_id'), $ids)
                        ->where($db->quoteName('method') . ' <> ' . $db->quote('backupcodes'))
                )->loadColumn() ?: [];
            }
        } catch (\Throwable $e) {
            // No MFA table (should not happen on Joomla 5/6).
        }
        $mfa = array_map('intval', $mfa);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'username' => (string) $r['username'], 'mfa' => \in_array((int) $r['id'], $mfa, true), 'lastvisit' => $r['lastvisitDate'] ?: null,
        ], $rows);
    }

    /** @return array<string,bool> */
    public function hardeningFlags(): array
    {
        $reporting = (string) $this->app->get('error_reporting', 'default');
        $conf      = JPATH_CONFIGURATION . '/configuration.php';

        return [
            // Keys shared with the WordPress report (the Central App renders both CMS from one view).
            'disallow_file_edit'        => true,
            'disallow_file_mods'        => false,
            'xmlrpc_enabled'            => false,
            'debug_display'             => (bool) $this->app->get('debug') || \in_array($reporting, ['maximum', 'development'], true),
            'wpconfig_writable'         => is_file($conf) && is_writable($conf),
            'core_writable'             => false,
            'uploads_executable'        => false,
            // Joomla specific.
            'installation_dir'          => is_dir(JPATH_ROOT . '/installation'),
            'leaky_files'               => $this->presentLeakyFiles() !== [],
            'force_ssl'                 => (int) $this->app->get('force_ssl', 0) === 2,
        ];
    }
}

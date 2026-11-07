<?php

namespace Ux1\UxStudio\Modules\ThirdPartyLogin;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Ux1\UxStudio\Core\ApiException;
use Ux1\UxStudio\Core\ApiRequest;
use Ux1\UxStudio\Core\ClientIp;
use Ux1\UxStudio\Core\ModuleContext;

\defined('_JEXEC') or die;

/**
 * Administration API of the module (CSRF, login and core.admin are enforced by the core before a handler runs).
 */
final class Api
{
    private const LABELS = ['google' => 'Google', 'microsoft' => 'Microsoft', 'facebook' => 'Facebook', 'seznam' => 'Seznam', 'oidc' => 'OpenID Connect'];

    public function __construct(private ModuleContext $ctx, private Store $store)
    {
    }

    /** Providers, the redirect URIs to register, and the connections of the signed-in administrator. */
    public function status(ApiRequest $r): array
    {
        $settings = $this->ctx->settings();
        $providers = [];
        foreach (Providers::IDS as $id) {
            $def = Providers::definition($id, $settings);
            $providers[] = [
                'id'         => $id,
                'label'      => $id === 'oidc' && trim((string) $settings['oidc_label']) !== '' ? trim((string) $settings['oidc_label']) : self::LABELS[$id],
                'enabled'    => !empty($settings[$id . '_enabled']),
                'configured' => $def !== null,
            ];
        }
        $user = $r->user();

        return [
            'providers'     => $providers,
            'redirect_uris' => ['administrator' => Flow::redirectUri('administrator'), 'site' => Flow::redirectUri('site')],
            'areas'         => ['administrator' => (bool) $settings['admin_login'], 'site' => (bool) $settings['site_login']],
            'me'            => ['id' => (int) $user->id, 'username' => (string) $user->username, 'privileged' => Flow::isAdminLevel($user), 'identities' => $this->store->identitiesOfUser((int) $user->id)],
            'allow_privileged' => (bool) $settings['allow_privileged'],
        ];
    }

    /** @return array{items:array<int,array<string,mixed>>} */
    public function identities(ApiRequest $r): array
    {
        $items = [];
        foreach ($this->store->allIdentities() as $row) {
            $items[] = [
                'id' => (int) $row['id'], 'user_id' => (int) $row['user_id'], 'provider' => (string) $row['provider'], 'email' => (string) $row['email'],
                'created' => (string) $row['created'], 'last_login' => $row['last_login'] ?: null,
                'name' => (string) ($row['name'] ?? ''), 'username' => (string) ($row['username'] ?? ''), 'blocked' => (int) ($row['block'] ?? 0) === 1, 'orphan' => $row['username'] === null,
            ];
        }

        return ['items' => $items];
    }

    /** @return array{items:array<int,array<string,mixed>>} */
    public function log(ApiRequest $r): array
    {
        return ['items' => $this->store->logs(200)];
    }

    /** URL that starts linking the signed-in administrator to a provider (opened by the browser; needs the form token). */
    public function link(ApiRequest $r): array
    {
        $provider = $r->param('provider');
        if (!\in_array($provider, Providers::IDS, true) || Providers::definition($provider, $this->ctx->settings()) === null) {
            throw new ApiException(Text::_('UXS_THIRD_PARTY_LOGIN_ERR_PROVIDER'), 422, 'provider_not_configured');
        }

        return ['url' => Uri::root() . 'administrator/index.php?' . http_build_query(['uxs_tpl' => 'start', 'mode' => 'link', 'provider' => $provider, Session::getFormToken() => 1])];
    }

    public function unlink(ApiRequest $r): array
    {
        $id = (int) $r->param('id');
        if ($id <= 0 || !$this->store->deleteIdentity($id)) {
            throw new ApiException(Text::_('UXS_THIRD_PARTY_LOGIN_ERR_IDENTITY_NOT_FOUND'), 404, 'not_found');
        }
        $this->store->log('unlinked', '', 'administrator', (int) $r->user()->id, '#' . $id, ClientIp::current());

        return ['deleted' => true];
    }
}

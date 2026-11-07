<?php

namespace Ux1\UxStudio\Modules\ThirdPartyLogin;

use Ux1\UxStudio\Core\Http;

\defined('_JEXEC') or die;

/**
 * Provider catalogue (endpoints, scopes) and the extraction of a normalised identity from a provider response.
 *
 * Identity = ['sub' => stable subject id, 'email' => string, 'verified' => bool (the provider vouches for the address), 'name' => string].
 * The client secret and the PKCE verifier are sent to the token endpoint over TLS; OpenID Connect id_tokens are read
 * from that direct response (OIDC Core 3.1.3.7 allows skipping the signature check in this case) and still validated
 * for audience, issuer, expiry and nonce.
 */
final class Providers
{
    public const IDS = ['google', 'microsoft', 'facebook', 'seznam', 'oidc'];

    /** Tenant id of personal Microsoft accounts (their e-mail address is verified by Microsoft). */
    private const MSA_TENANT = '9188040d-6c67-4c5b-b112-36a304b66dad';

    /** Domains whose mailboxes are provided by Seznam itself (an address there proves the account). */
    private const SEZNAM_DOMAINS = ['seznam.cz', 'email.cz', 'post.cz', 'spoluzaci.cz', 'stream.cz', 'firmy.cz'];

    /**
     * @param array<string,mixed> $s module settings
     * @return array<string,mixed>|null definition, null for an unknown or not configured provider
     */
    public static function definition(string $id, array $s): ?array
    {
        $clientId = trim((string) ($s[$id . '_client_id'] ?? ''));
        $secret   = (string) ($s[$id . '_client_secret'] ?? '');
        $base     = ['id' => $id, 'client_id' => $clientId, 'client_secret' => $secret, 'private' => false, 'userinfo' => '', 'issuers' => [], 'extra' => []];

        $def = match ($id) {
            'google' => array_merge($base, [
                'label' => 'Google', 'authorize' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
                'scope' => 'openid email profile', 'oidc' => true,
            ]),
            'microsoft' => self::microsoftDefinition($base, $s),
            'facebook' => array_merge($base, [
                'label' => 'Facebook', 'authorize' => 'https://www.facebook.com/v21.0/dialog/oauth', 'token' => 'https://graph.facebook.com/v21.0/oauth/access_token',
                'userinfo' => 'https://graph.facebook.com/v21.0/me', 'scope' => 'email public_profile', 'oidc' => false, 'trust_email' => !empty($s['facebook_trust_email']),
            ]),
            'seznam' => array_merge($base, [
                'label' => 'Seznam', 'authorize' => 'https://login.szn.cz/api/v1/oauth/auth', 'token' => 'https://login.szn.cz/api/v1/oauth/token',
                'userinfo' => 'https://login.szn.cz/api/v1/user', 'scope' => 'identity', 'oidc' => false,
            ]),
            'oidc' => array_merge($base, [
                'label' => trim((string) ($s['oidc_label'] ?? '')) ?: 'OpenID Connect', 'authorize' => trim((string) ($s['oidc_authorize_url'] ?? '')),
                'token' => trim((string) ($s['oidc_token_url'] ?? '')), 'userinfo' => trim((string) ($s['oidc_userinfo_url'] ?? '')),
                'scope' => 'openid email profile', 'oidc' => true, 'private' => true,
                'issuers' => trim((string) ($s['oidc_issuer'] ?? '')) !== '' ? [rtrim(trim((string) $s['oidc_issuer']), '/')] : [],
                'trust_email' => !empty($s['oidc_trust_email']),
            ]),
            default => null,
        };

        if ($def === null || $def['client_id'] === '' || $def['client_secret'] === '' || $def['authorize'] === '' || $def['token'] === '') {
            return null;
        }
        // The custom provider may live on a private network (only an administrator can set it); the others must be https.
        foreach (array_filter([$def['authorize'], $def['token'], $def['userinfo']]) as $url) {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if ($scheme !== 'https' && !($def['private'] && $scheme === 'http')) {
                return null;
            }
        }

        return $def;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $s
     * @return array<string,mixed>
     */
    private static function microsoftDefinition(array $base, array $s): array
    {
        $tenant = self::tenant((string) ($s['microsoft_tenant'] ?? 'common'));

        return array_merge($base, [
            'label' => 'Microsoft', 'authorize' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/authorize",
            'token' => "https://login.microsoftonline.com/$tenant/oauth2/v2.0/token", 'scope' => 'openid email profile', 'oidc' => true,
            'tenant' => $tenant, 'extra' => ['response_mode' => 'query'],
        ]);
    }

    public static function tenant(string $raw): string
    {
        $raw = trim($raw);

        return $raw !== '' && preg_match('/^(common|organizations|consumers|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+)$/i', $raw) ? $raw : 'common';
    }

    /**
     * Exchanges the authorization code and returns the normalised identity.
     *
     * @param array<string,mixed> $def
     * @return array{sub:string,email:string,verified:bool,name:string}
     * @throws \RuntimeException message = machine readable reason (token_exchange, id_token, nonce, identity)
     */
    public static function fetchIdentity(array $def, Http $http, string $code, string $redirectUri, string $verifier, string $nonce): array
    {
        $opts = ['headers' => ['Accept' => 'application/json'], 'allow_private' => !empty($def['private']), 'timeout' => 10];
        $res  = $http->post($def['token'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $def['client_id'],
            'client_secret' => $def['client_secret'],
            'code_verifier' => $verifier,
        ], $opts);
        $token = json_decode($res['body'], true);
        if ($res['status'] < 200 || $res['status'] >= 300 || !\is_array($token) || (empty($token['access_token']) && empty($token['id_token']))) {
            throw new \RuntimeException('token_exchange');
        }
        $access = (string) ($token['access_token'] ?? '');

        $claims = [];
        if (!empty($def['oidc']) && !empty($token['id_token']) && \is_string($token['id_token'])) {
            $claims = self::validateIdToken($def, $token['id_token'], $nonce);
        }

        $identity = match ($def['id']) {
            'facebook'  => self::facebook($def, $http, $access),
            'seznam'    => self::seznam($def, $http, $access, $token),
            'microsoft' => self::microsoft($def, $claims),
            'google'    => self::google($claims),
            default     => self::generic($def, $http, $access, $claims, $opts),
        };

        if ($identity['sub'] === '' || \strlen($identity['sub']) > 190) {
            throw new \RuntimeException('identity');
        }
        $identity['email'] = mb_strtolower(trim($identity['email']));
        if ($identity['email'] !== '' && !filter_var($identity['email'], FILTER_VALIDATE_EMAIL)) {
            $identity['email']    = '';
            $identity['verified'] = false;
        }
        $identity['name'] = mb_substr(trim(strip_tags($identity['name'])), 0, 100);

        return $identity;
    }

    /**
     * @param array<string,mixed> $def
     * @return array<string,mixed> claims
     */
    private static function validateIdToken(array $def, string $jwt, string $nonce): array
    {
        $parts = explode('.', $jwt);
        if (\count($parts) !== 3) {
            throw new \RuntimeException('id_token');
        }
        $claims = json_decode((string) self::b64d($parts[1]), true);
        if (!\is_array($claims)) {
            throw new \RuntimeException('id_token');
        }
        $aud = (array) ($claims['aud'] ?? []);
        if (!\in_array($def['client_id'], array_map('strval', $aud), true)) {
            throw new \RuntimeException('id_token');
        }
        if ((int) ($claims['exp'] ?? 0) < time() - 60) {
            throw new \RuntimeException('id_token');
        }
        if (!isset($claims['nonce']) || !\is_string($claims['nonce']) || !hash_equals($nonce, $claims['nonce'])) {
            throw new \RuntimeException('nonce');
        }
        $iss = rtrim((string) ($claims['iss'] ?? ''), '/');
        if ($def['id'] === 'google' && !\in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            throw new \RuntimeException('id_token');
        }
        if ($def['id'] === 'microsoft') {
            $tid = strtolower((string) ($claims['tid'] ?? ''));
            if (!preg_match('/^[0-9a-f-]{36}$/', $tid) || $iss !== 'https://login.microsoftonline.com/' . $tid . '/v2.0') {
                throw new \RuntimeException('id_token');
            }
            if (preg_match('/^[0-9a-f-]{36}$/i', (string) $def['tenant']) && strtolower((string) $def['tenant']) !== $tid) {
                throw new \RuntimeException('id_token');
            }
        }
        if (!empty($def['issuers']) && !\in_array($iss, $def['issuers'], true)) {
            throw new \RuntimeException('id_token');
        }

        return $claims;
    }

    /**
     * @param array<string,mixed> $c
     * @return array{sub:string,email:string,verified:bool,name:string}
     */
    private static function google(array $c): array
    {
        if (!$c) {
            throw new \RuntimeException('id_token');
        }
        $verified = $c['email_verified'] ?? false;

        return ['sub' => (string) ($c['sub'] ?? ''), 'email' => (string) ($c['email'] ?? ''), 'verified' => $verified === true || $verified === 'true', 'name' => (string) ($c['name'] ?? '')];
    }

    /**
     * Microsoft does not vouch for the e-mail claim of work accounts in other tenants (the "nOAuth" class of attacks), so the
     * address counts as verified only for personal accounts or when the sign-in is limited to one tenant.
     *
     * @param array<string,mixed> $def
     * @param array<string,mixed> $c
     * @return array{sub:string,email:string,verified:bool,name:string}
     */
    private static function microsoft(array $def, array $c): array
    {
        if (!$c) {
            throw new \RuntimeException('id_token');
        }
        $tid   = strtolower((string) ($c['tid'] ?? ''));
        $email = (string) ($c['email'] ?? '');
        if ($email === '' && str_contains((string) ($c['preferred_username'] ?? ''), '@')) {
            $email = (string) $c['preferred_username'];
        }
        $single   = !\in_array(strtolower((string) $def['tenant']), ['common', 'organizations', 'consumers'], true);
        $edov     = $c['xms_edov'] ?? true;
        $verified = ($tid === self::MSA_TENANT || $single) && $edov !== false && $edov !== '0' && $edov !== 0;

        return ['sub' => (string) ($c['sub'] ?? ''), 'email' => $email, 'verified' => $verified, 'name' => (string) ($c['name'] ?? '')];
    }

    /**
     * @param array<string,mixed> $def
     * @return array{sub:string,email:string,verified:bool,name:string}
     */
    private static function facebook(array $def, Http $http, string $access): array
    {
        $res = $http->get($def['userinfo'] . '?' . http_build_query(['fields' => 'id,name,email', 'access_token' => $access, 'appsecret_proof' => hash_hmac('sha256', $access, (string) $def['client_secret'])]), ['timeout' => 10]);
        $me  = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !\is_array($me)) {
            throw new \RuntimeException('identity');
        }

        // Facebook does not guarantee that the address was verified, so it pairs accounts only when the administrator opted in.
        return ['sub' => (string) ($me['id'] ?? ''), 'email' => (string) ($me['email'] ?? ''), 'verified' => !empty($me['email']) && !empty($def['trust_email']), 'name' => (string) ($me['name'] ?? '')];
    }

    /**
     * Seznam: the token response carries oauth_user_id and account_name (the full mailbox address); /api/v1/user adds
     * username (local part), domain and the contact e-mail. The identity is the account address; it counts as verified only
     * on a domain operated by Seznam. A free-form contact address is never trusted.
     *
     * @param array<string,mixed> $def
     * @param array<string,mixed> $token token endpoint response
     * @return array{sub:string,email:string,verified:bool,name:string}
     */
    private static function seznam(array $def, Http $http, string $access, array $token): array
    {
        $res  = $http->get($def['userinfo'], ['headers' => ['Authorization' => 'Bearer ' . $access, 'Accept' => 'application/json'], 'timeout' => 10]);
        $user = json_decode($res['body'], true);
        if ($res['status'] !== 200 || !\is_array($user)) {
            $user = [];
            if (empty($token['oauth_user_id'])) {
                throw new \RuntimeException('identity');
            }
        }
        $sub     = (string) ($token['oauth_user_id'] ?? $user['oauth_user_id'] ?? $user['id'] ?? '');
        $account = strtolower(trim((string) ($token['account_name'] ?? $user['account_name'] ?? '')));
        if (!str_contains($account, '@')) {
            $local  = strtolower(trim((string) ($user['username'] ?? $account)));
            $domain = strtolower(trim((string) ($user['domain'] ?? '')));
            $account = $local !== '' && $domain !== '' && !str_contains($local, '@') ? $local . '@' . $domain : (str_contains($local, '@') ? $local : '');
        }
        $contact = strtolower(trim((string) ($user['contact-email'] ?? $user['email'] ?? '')));

        $email    = $account;
        $verified = false;
        if ($account !== '') {
            $verified = \in_array(substr((string) strrchr($account, '@'), 1), self::SEZNAM_DOMAINS, true);
        } else {
            $email = $contact;
        }
        $name = trim(((string) ($user['firstname'] ?? '')) . ' ' . ((string) ($user['lastname'] ?? '')));

        return ['sub' => $sub, 'email' => $email, 'verified' => $verified, 'name' => $name];
    }

    /**
     * @param array<string,mixed> $def
     * @param array<string,mixed> $claims
     * @param array<string,mixed> $opts
     * @return array{sub:string,email:string,verified:bool,name:string}
     */
    private static function generic(array $def, Http $http, string $access, array $claims, array $opts): array
    {
        $info = $claims;
        if ($def['userinfo'] !== '' && $access !== '' && (empty($claims['email']) || empty($claims['sub']))) {
            $res = $http->get($def['userinfo'], ['headers' => ['Authorization' => 'Bearer ' . $access] + $opts['headers'], 'allow_private' => $opts['allow_private'], 'timeout' => 10]);
            $ui  = json_decode($res['body'], true);
            if ($res['status'] === 200 && \is_array($ui)) {
                // The subject of the userinfo response must belong to the same user as the id_token.
                if (!empty($claims['sub']) && isset($ui['sub']) && (string) $ui['sub'] !== (string) $claims['sub']) {
                    throw new \RuntimeException('identity');
                }
                $info = $claims + $ui;
            }
        }
        $verified = $info['email_verified'] ?? false;

        return [
            'sub'      => (string) ($info['sub'] ?? ''),
            'email'    => (string) ($info['email'] ?? ''),
            'verified' => $verified === true || $verified === 'true' || !empty($def['trust_email']),
            'name'     => (string) ($info['name'] ?? ''),
        ];
    }

    private static function b64d(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - \strlen($s) % 4) % 4), true);
    }
}

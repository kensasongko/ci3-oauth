<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

/**
 * Wraps jumbojett/openid-connect-php for the OpenID Connect Authorization Code
 * flow against Microsoft Entra ID (v2.0 endpoint).
 *
 * Loaded with: $this->load->library('azure_auth');
 *
 * Public API:
 *   start_login()        — redirects browser to Entra; never returns
 *   handle_callback()    — validates the redirect-back, returns normalized claims
 *   build_logout_url()   — returns RP-initiated logout URL
 */
class Azure_auth {

    /** @var array */
    protected $cfg;

    /** @var OpenIDConnectClient */
    protected $oidc;

    /** @var CI_Controller */
    protected $CI;

    public function __construct() {
        $this->CI =& get_instance();
        $this->CI->config->load('Azure', FALSE, TRUE);
        $loaded = $this->CI->config->item('azure');
        $this->cfg = is_array($loaded) ? $loaded : [];

        if (empty($this->cfg['clientId']) || empty($this->cfg['clientSecret']) || empty($this->cfg['tenantId'])) {
            throw new RuntimeException('Azure SSO is not configured: clientId, clientSecret, and tenantId are required.');
        }
        if (empty($this->cfg['redirectUri'])) {
            throw new RuntimeException('Azure SSO is not configured: redirectUri is required and must match the Entra app registration.');
        }

        // jumbojett uses native $_SESSION for state/nonce. CI3's session library
        // does not touch $_SESSION, so we have to start one ourselves.
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Microsoft v2.0 issuer — jumbojett auto-discovers endpoints from
        // <issuer>/.well-known/openid-configuration.
        $providerUrl = 'https://login.microsoftonline.com/' . $this->cfg['tenantId'] . '/v2.0';

        $this->oidc = new OpenIDConnectClient(
            $providerUrl,
            $this->cfg['clientId'],
            $this->cfg['clientSecret']
        );
        $this->oidc->setRedirectURL($this->cfg['redirectUri']);

        $scopes = isset($this->cfg['scopes']) && is_array($this->cfg['scopes'])
            ? $this->cfg['scopes']
            : ['openid', 'profile', 'email', 'offline_access'];
        $this->oidc->addScope($scopes);
    }

    /**
     * Build the auth URL, generate state + nonce, redirect — and exit.
     * jumbojett's authenticate() handles the entire kickoff including the
     * header() + exit(), so this method does not return on the happy path.
     */
    public function start_login() {
        try {
            $this->oidc->authenticate();
        } catch (OpenIDConnectClientException $e) {
            throw new RuntimeException('OIDC authorize failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Process the callback. authenticate() reads ?code & ?state from $_GET,
     * exchanges the code, validates state/nonce/signature/iss/aud/exp.
     * We additionally enforce Microsoft's `tid` claim against tenantId or the
     * allowedTenants whitelist.
     *
     * @return array{oid:string,tid:string,email:?string,name:?string,preferred_username:?string,upn:?string}
     */
    public function handle_callback() {
        try {
            $this->oidc->authenticate();
        } catch (OpenIDConnectClientException $e) {
            throw new RuntimeException('OIDC callback failed: ' . $e->getMessage(), 0, $e);
        }

        $verified = $this->oidc->getVerifiedClaims();
        $claims   = is_object($verified) ? (array) $verified : (array) $verified;

        if (empty($claims['tid'])) {
            throw new RuntimeException('ID token missing tid (tenant id) claim.');
        }
        $allowed = isset($this->cfg['allowedTenants']) && is_array($this->cfg['allowedTenants'])
            ? $this->cfg['allowedTenants']
            : [];
        if (!empty($allowed)) {
            if (!in_array($claims['tid'], $allowed, TRUE)) {
                throw new RuntimeException('ID token tenant is not in the allowed list.');
            }
        } else {
            if (!hash_equals((string) $this->cfg['tenantId'], (string) $claims['tid'])) {
                throw new RuntimeException('ID token tenant does not match configured tenantId.');
            }
        }
        if (empty($claims['oid'])) {
            throw new RuntimeException('ID token missing oid (object id) claim.');
        }

        // Stash the id_token so we can pass it as id_token_hint at logout.
        $idToken = $this->oidc->getIdToken();
        if ($idToken) {
            $this->CI->session->set_userdata('azure_id_token', $idToken);
        }

        return [
            'oid'                => $claims['oid'],
            'tid'                => $claims['tid'],
            'email'              => $claims['email']              ?? NULL,
            'name'               => $claims['name']               ?? NULL,
            'preferred_username' => $claims['preferred_username'] ?? NULL,
            'upn'                => $claims['upn']                ?? NULL,
        ];
    }

    /**
     * Build the federated logout URL. Microsoft's v2.0 logout endpoint accepts
     * post_logout_redirect_uri and (optionally) id_token_hint.
     */
    public function build_logout_url() {
        $postLogout = !empty($this->cfg['postLogoutRedirectUri'])
            ? $this->cfg['postLogoutRedirectUri']
            : '';
        $idToken = $this->CI->session->userdata('azure_id_token');

        $params = [];
        if ($postLogout !== '') {
            $params['post_logout_redirect_uri'] = $postLogout;
        }
        if (!empty($idToken)) {
            $params['id_token_hint'] = $idToken;
        }

        $url = 'https://login.microsoftonline.com/' . $this->cfg['tenantId'] . '/oauth2/v2.0/logout';
        return $params ? $url . '?' . http_build_query($params) : $url;
    }
}

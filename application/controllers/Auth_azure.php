<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SSO entry-point controller for Microsoft Entra ID.
 *
 *   GET /auth_azure              -> kick off the OIDC auth code flow
 *   GET /auth_azure/callback     -> handle redirect back from Entra
 *   GET /auth_azure/logout       -> federated sign-out (optional)
 *
 * Must remain accessible while logged out, so it extends CI_Controller
 * (NOT MY_Controller).
 */
class Auth_azure extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->load->library('azure_auth');
    }

    public function start() {
        if ($this->session->userdata('user')) {
            redirect('Dashboard');
            return;
        }

        try {
            // jumbojett's authenticate() builds the URL, stores state+nonce,
            // redirects, and exit()s — never returns on the happy path.
            $this->azure_auth->start_login();
        } catch (Exception $e) {
            log_message('error', 'Azure SSO start failed: ' . $e->getMessage());
            $this->session->set_flashdata('sso_error', 'Single sign-on is not available right now.');
            redirect('login');
            return;
        }
    }

    public function callback() {
        // Surface Entra-side errors (consent denied, etc.) before we try to
        // exchange a code that isn't there.
        $err = $this->input->get('error', TRUE);
        if (!empty($err)) {
            $desc = $this->input->get('error_description', TRUE);
            log_message('error', 'Azure SSO returned error: ' . $err . ' — ' . $desc);
            $this->session->set_flashdata('sso_error', 'Sign-in was cancelled or rejected.');
            redirect('login');
            return;
        }

        try {
            // jumbojett reads ?code and ?state from $_GET itself; no args.
            $claims = $this->azure_auth->handle_callback();
        } catch (Exception $e) {
            log_message('error', 'Azure SSO callback failed: ' . $e->getMessage());
            $this->session->set_flashdata('sso_error', 'Sign-in failed. Please try again.');
            redirect('login');
            return;
        }

        $cfg = $this->config->item('azure');
        $jit = !empty($cfg['jitProvision']);

        $row = $this->Maccount->findByAzureOid($claims['oid']);
        if (!$row) {
            if ($jit) {
                $row = $this->Maccount->provisionFromAzure($claims);
            } else {
                log_message('error', 'Azure SSO: unknown oid ' . $claims['oid'] . ' and JIT provisioning is disabled.');
                $this->session->set_flashdata('sso_error', 'Your account has not been provisioned. Contact an administrator.');
                redirect('login');
                return;
            }
        }

        if (!$row) {
            $this->session->set_flashdata('sso_error', 'Sign-in succeeded but the local account could not be created.');
            redirect('login');
            return;
        }

        $userId = isset($row['id']) ? (int) $row['id'] : NULL;
        if ($userId) {
            $this->Maccount->touchLastLogin($userId);
        }

        $user = [
            'id'          => $userId,
            'username'    => $row['username'] ?? ($claims['preferred_username'] ?? $claims['upn'] ?? $claims['email']),
            'name'        => $row['display_name'] ?? ($claims['name'] ?? ''),
            'email'       => $row['email'] ?? ($claims['email'] ?? $claims['upn'] ?? ''),
            'azure_oid'   => $claims['oid'],
            'tenant'      => $claims['tid'],
            'auth_source' => 'azure',
        ];
        $this->session->set_userdata('user', $user);
        $this->session->sess_regenerate(TRUE);

        $redirect = $this->session->userdata('url_ref');
        if ($redirect) {
            $this->session->unset_userdata('url_ref');
            redirect($redirect);
            return;
        }

        redirect('Dashboard');
    }

    public function logout() {
        $logoutUrl = $this->azure_auth->build_logout_url();
        $this->session->sess_destroy();
        redirect($logoutUrl);
    }
}

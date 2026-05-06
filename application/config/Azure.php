<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
|--------------------------------------------------------------------------
| Microsoft Entra ID (Azure AD) SSO configuration
|--------------------------------------------------------------------------
|
| Defaults / shape only. Real per-environment values live in:
|   application/config/development/Azure.php
|   application/config/production/Azure.php
|
| Loaded via: $this->config->load('Azure');
| Read with:  $this->config->item('azure');
|
*/

$config['azure'] = [
    'tenantId'              => '',
    'clientId'              => '',
    'clientSecret'          => '',
    'redirectUri'           => 'http://localhost/codeigniter3/index.php/auth/azure/callback',
    'postLogoutRedirectUri' => 'http://localhost/codeigniter3/index.php/login',
    'scopes'                => ['openid', 'profile', 'email', 'offline_access'],
    'allowLocalLogin'       => TRUE,
    'allowedTenants'        => [],
    'jitProvision'          => TRUE,
];

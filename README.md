# CodeIgniter 3 Starter with Microsoft Entra ID SSO

A CodeIgniter 3 application skeleton that supports both classic
username/password login and **Microsoft Entra ID** (formerly Azure AD)
single sign-on via the OpenID Connect Authorization Code flow against the
v2.0 endpoint.

Local password login remains available behind a config flag so the SSO
rollout can be piloted before retiring the password form.

---

## 1. Stack and Requirements

| Component | Version / Notes |
|---|---|
| PHP | 7.3+ (8.x supported) with `openssl`, `curl`, `json`, `mbstring` |
| CodeIgniter | 3.1.13 (bundled in `system/`) |
| Composer | required — pulls `jumbojett/openid-connect-php` |
| Database | MySQL / MariaDB (CI3 query builder is portable; schema below uses MySQL syntax) |
| Web server | Apache / Nginx with PHP-FPM, or `php -S` for local dev |
| HTTPS | mandatory in any non-`localhost` environment (Entra rejects non-HTTPS redirect URIs) |

---

## 2. Project Layout

```
codeigniter3/
├── index.php                       CI3 front controller
├── composer.json / composer.lock   Pulls jumbojett/openid-connect-php
├── application/
│   ├── config/
│   │   ├── config.php              composer_autoload=TRUE, base_url, sessions
│   │   ├── Azure.php               default shape of $config['azure'] (no secrets)
│   │   ├── development/Azure.php   per-env overrides (gitignored)
│   │   ├── production/Azure.php    per-env overrides (gitignored)
│   │   ├── autoload.php            autoloads database, session, Maccount, Azure config
│   │   └── routes.php              login + auth_azure routes
│   ├── controllers/
│   │   ├── Account.php             local login form, AJAX validate, auth-source-aware logout
│   │   ├── Auth_azure.php          SSO start + callback + forced federated logout
│   │   └── Dashboard.php           protected page (extends MY_Controller)
│   ├── core/
│   │   └── MY_Controller.php       auth gate — redirects to /login when not signed in
│   ├── libraries/
│   │   └── Azure_auth.php          wraps Jumbojett\OpenIDConnectClient
│   ├── models/
│   │   └── Maccount.php            validateLogin, findByAzureOid, provisionFromAzure, touchLastLogin
│   ├── views/
│   │   ├── v_login.php             local form + "Sign in with Microsoft" button
│   │   ├── v_main.php / v_dashboard.php
│   │   └── errors/
│   └── migrations/sql/
│       └── 2026-04-28_add_sso_columns.sql
├── assets/                         Sneat-style Bootstrap theme
├── system/                         CodeIgniter framework (do not modify)
└── SSO_ENTRA_ID_PLAN_CI3.md        original implementation plan and rationale
```

---

## 3. Quick Start

```bash
git clone <this-repo> codeigniter3
cd codeigniter3

# 1. Install Composer deps (creates vendor/)
composer install

# 2. Configure the database
#    Edit application/config/database.php with your MySQL credentials.

# 3. Create the user table and apply SSO columns
mysql -u root -p <your_db> < application/migrations/sql/2026-04-28_add_sso_columns.sql
#    (the user table itself must already exist — see Section 5 for schema)

# 4. Register an app in Microsoft Entra (see Section 6) and fill in:
#    application/config/development/Azure.php
#    (tenantId, clientId, clientSecret, redirectUri, postLogoutRedirectUri)

# 5. Set base_url
#    Edit application/config/config.php → $config['base_url']
#    so it matches the URL you serve the app from.

# 6. Serve it
#    Either drop the directory under Apache's docroot, or:
php -S localhost:8000 -t .
#    then browse http://localhost:8000/index.php/login
```

The default route lands on `Account/login`. Click **Sign in with Microsoft**
to start the SSO flow.

---

## 4. Configuration

### 4.1 Environment selection

`index.php` reads `CI_ENV` from the server environment to pick the active
profile (default: `development`):

```apache
SetEnv CI_ENV production
```

```nginx
fastcgi_param CI_ENV production;
```

CI3 then auto-merges `application/config/<ENVIRONMENT>/Azure.php` on top of
the default `application/config/Azure.php`, so per-env values override the
shape file without duplicating the unchanged keys.

### 4.2 `application/config/Azure.php` (defaults / shape, committed)

```php
$config['azure'] = [
    'tenantId'              => '',
    'clientId'              => '',
    'clientSecret'          => '',
    'redirectUri'           => 'http://localhost/codeigniter3/index.php/auth/azure/callback',
    'postLogoutRedirectUri' => 'http://localhost/codeigniter3/index.php/login',
    'scopes'                => ['openid', 'profile', 'email', 'offline_access'],
    'allowLocalLogin'       => TRUE,   // flip FALSE to retire local form
    'allowedTenants'        => [],     // empty = trust configured tenantId only
    'jitProvision'          => TRUE,   // create user row on first SSO login
];
```

### 4.3 Per-environment overrides (gitignored)

`application/config/development/Azure.php` and
`application/config/production/Azure.php` hold the real values. Both files
are listed in `.gitignore` so secrets stay off the repo.

| Key | Where it comes from |
|---|---|
| `tenantId` | Entra app registration → **Overview** → *Directory (tenant) ID* |
| `clientId` | Entra app registration → **Overview** → *Application (client) ID* |
| `clientSecret` | Entra app registration → **Certificates & secrets** → secret **Value** (not the ID) |
| `redirectUri` | Must match the URI registered in Entra **exactly** (scheme, host, port, path) |
| `postLogoutRedirectUri` | Where Entra returns the user after federated logout (usually `<base_url>/login`) |
| `allowedTenants` | Optional whitelist of `tid` values. Leave empty for single-tenant. |

### 4.4 Composer autoload

`application/config/config.php` already has:

```php
$config['composer_autoload'] = FCPATH.'vendor/autoload.php';
```

So `composer install` is the only step needed for the OAuth client to load.

---

## 5. Database Schema

The `user` table is presumed to exist. Create it if you are starting fresh:

```sql
CREATE TABLE `user` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `username`      VARCHAR(190) NOT NULL UNIQUE,
  `password`      CHAR(32)     NULL,            -- legacy md5() — local accounts only
  `active`        TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

Then apply the SSO columns:

```sql
ALTER TABLE `user`
  ADD COLUMN `azure_oid`     VARCHAR(64)  NULL,
  ADD COLUMN `email`         VARCHAR(255) NULL,
  ADD COLUMN `display_name`  VARCHAR(255) NULL,
  ADD COLUMN `auth_source`   ENUM('local','azure') NOT NULL DEFAULT 'local',
  ADD COLUMN `last_login_at` DATETIME NULL,
  ADD UNIQUE KEY `uq_user_azure_oid` (`azure_oid`);
```

(this exact statement is shipped at
`application/migrations/sql/2026-04-28_add_sso_columns.sql`)

Notes:
- `azure_oid` is the Entra **object id** — stable across renames and email
  changes, so it is the join key, not `email` or `upn`.
- `auth_source` lets you distinguish local accounts from JIT-provisioned
  Entra users, which matters when you eventually retire local logins.
- `password` stays nullable; SSO-only users never receive one.

---

## 6. Setting Up Microsoft Entra ID on Microsoft 365

You need a Microsoft 365 tenant and an account with at least **Application
Administrator** or **Cloud Application Administrator** rights (Global
Administrator works too). Each environment (dev / staging / prod) should
get its own app registration so secrets and redirect URIs do not bleed
across environments.

### 6.1 Open the Entra admin center

1. Sign in to [https://entra.microsoft.com](https://entra.microsoft.com)
   with an admin account.
2. In the left rail, expand **Identity** → **Applications** →
   **App registrations**.
3. Click **+ New registration**.

### 6.2 Register the application

Fill the form:

| Field | Value |
|---|---|
| **Name** | `CodeIgniter3 App (dev)` — change per environment |
| **Supported account types** | *Accounts in this organizational directory only (Single tenant)* — pick *Multitenant* only if external orgs also need to sign in |
| **Redirect URI** | Platform = **Web**, URL = `https://<your-host>/index.php/auth/azure/callback` (or `http://localhost/codeigniter3/index.php/auth/azure/callback` for local) |

Click **Register**. You land on the app **Overview** page.

Copy these two values into your per-env `Azure.php`:
- **Application (client) ID** → `clientId`
- **Directory (tenant) ID** → `tenantId`

### 6.3 Add or edit redirect URIs

If you need to add additional redirect URIs (for example, both
`localhost` and a real domain on the same registration):

1. **Authentication** (left rail).
2. Under **Platform configurations** → **Web** → **Add URI**.
3. Add each URI exactly. Allowed:
   - `http://localhost/...` (loopback only)
   - `https://...` for everything else
4. Save.

The `redirectUri` you put into config must match one of these
character-for-character — including whether `index.php` is in the path,
trailing slash, and case.

### 6.4 Create a client secret

1. **Certificates & secrets** (left rail) → **Client secrets** tab →
   **+ New client secret**.
2. Description: `dev` (or environment name). Expiry: pick the shortest
   the team can live with (6–12 months is typical).
3. Click **Add**.
4. **Copy the `Value` immediately** — once you leave the page, only the
   `Secret ID` remains visible, not the secret itself.
5. Paste it into `clientSecret` in the per-env `Azure.php`.

Calendar a reminder before the expiry; rotation is a manual step.

### 6.5 API permissions

1. **API permissions** (left rail).
2. By default, **Microsoft Graph → User.Read** is already granted as a
   delegated permission. That is the only Graph permission this app
   needs.
3. Click **+ Add a permission** if you also want explicit `openid`,
   `profile`, `email`, `offline_access`:
   - **Microsoft Graph** → **Delegated permissions** → check `openid`,
     `profile`, `email`, `offline_access` → **Add permissions**.
4. If your tenant requires it, click **Grant admin consent for
   <tenant>**. The status column should turn green.

### 6.6 Optional: token configuration

To make the ID token contain `email`, `upn`, and `preferred_username`
explicitly (useful when your users do not have a primary `email` claim):

1. **Token configuration** (left rail) → **+ Add optional claim**.
2. Token type: **ID** → check `email`, `upn`, `preferred_username` →
   **Add**.
3. If prompted, accept the toggle to enable the related Microsoft Graph
   permission.

The library already falls back across these claims when populating the
session, so this step is recommended but not strictly required.

### 6.7 Optional: branding and publisher domain

For a polished consent screen:

1. **Branding & properties** → upload a logo (square PNG ≤ 240 KB),
   set Home page URL, Terms of service URL, Privacy statement URL.
2. **Publisher domain** → verify a domain you control to remove the
   "unverified" warning during consent.

### 6.8 Verify the values land in config

After registration, your `application/config/development/Azure.php`
should look something like:

```php
$config['azure'] = [
    'tenantId'              => '00000000-0000-0000-0000-000000000000',
    'clientId'              => '11111111-1111-1111-1111-111111111111',
    'clientSecret'          => 'paste-secret-VALUE-here',
    'redirectUri'           => 'https://your-host/index.php/auth/azure/callback',
    'postLogoutRedirectUri' => 'https://your-host/index.php/login',
    'scopes'                => ['openid', 'profile', 'email', 'offline_access'],
    'allowLocalLogin'       => TRUE,
    'allowedTenants'        => [],
    'jitProvision'          => TRUE,
];
```

---

## 7. Routes and Sign-in Flow

Routes are declared in `application/config/routes.php`:

| Route | Maps to | Purpose |
|---|---|---|
| `/` | `Account/login` | Default route; renders `v_login` if not signed in |
| `/login` | `Account/login` | Same |
| `/Account/validate` | `Account::validate` | AJAX endpoint for local login |
| `/Account/logout` | `Account::logout` | Branches on `auth_source`: federated sign-out for Entra users (calls `Azure_auth::build_logout_url()`), local `sess_destroy()` + redirect to `/` for local users. The dashboard logout link points here. |
| `/auth_azure` and `/auth/azure` | `Auth_azure::start` | Generates `state` + `nonce`, redirects to Entra |
| `/auth_azure/callback` and `/auth/azure/callback` | `Auth_azure::callback` | Validates ID token, populates session, redirects to `Dashboard` |
| `/auth_azure/logout` and `/auth/azure/logout` | `Auth_azure::logout` | Force federated sign-out regardless of `auth_source` — clears session + signs out of Entra |
| `/Dashboard` | `Dashboard::index` | Auth-gated via `MY_Controller` |

Both `auth_azure/...` and `auth/azure/...` shapes resolve to the same
controller; this is intentional so existing links and the redirect URI
registered in Entra (`/auth/azure/callback`) both work.

End-to-end flow:

1. User clicks **Sign in with Microsoft** on `v_login`.
2. `Auth_azure::start` calls `Azure_auth::start_login()`, which delegates to
   `OpenIDConnectClient::authenticate()`. The library generates a fresh
   `state` and `nonce`, stashes both in the native PHP `$_SESSION`, then
   redirects the browser to
   `https://login.microsoftonline.com/<tenant>/oauth2/v2.0/authorize?...`
   and `exit()`s.
3. User authenticates with Entra; Entra redirects back to
   `redirectUri?code=...&state=...`.
4. `Auth_azure::callback`:
   - rejects the request if `state` does not match the stashed value
     (CSRF defense);
   - exchanges the `code` for tokens via `jumbojett/openid-connect-php`;
   - validates the ID token (`aud == clientId`, `nonce` matches, `tid`
     matches `tenantId` or is in `allowedTenants`, `oid` present);
   - looks up the local row by `azure_oid`; provisions a new row when
     `jitProvision = TRUE`;
   - stashes the raw `id_token` into the CI3 session as `azure_id_token`
     so it can later be passed as `id_token_hint` to the Entra logout
     endpoint;
   - calls `sess_regenerate(TRUE)` (session fixation defense) and
     redirects to `Dashboard` (or `url_ref` if the user was deep-linked).

The session shape after success is the **same** array shape that
`Maccount::validateLogin()` writes for local logins, so the rest of the
app does not need to know how the user signed in.

---

## 8. Auth Gate

`application/core/MY_Controller.php` is the closest CI3 equivalent of a
filter. Every protected controller extends it; the constructor redirects
unauthenticated requests to `/login` after stashing the original URL in
`url_ref` so the user lands back where they started after sign-in.

`Account` and `Auth_azure` deliberately stay on `CI_Controller` — they
must be reachable while logged out.

---

## 9. Security Checklist

Before going live:

- [ ] HTTPS enforced for non-local environments; `$config['cookie_secure'] = TRUE` in `application/config/config.php`.
- [ ] `$config['cookie_httponly'] = TRUE`.
- [ ] `composer_autoload` enabled and `vendor/` deployed.
- [ ] `application/config/<env>/Azure.php` is **not** committed (in `.gitignore`).
- [ ] Client secret rotation calendared before expiry.
- [ ] `redirectUri` in Entra exactly matches `azure.redirectUri` (scheme, host, port, path).
- [ ] `state` and `nonce` are validated on callback (the library does this; do not turn it off).
- [ ] `tid` is verified against `tenantId` or the `allowedTenants` whitelist.
- [ ] Unknown-user policy decided per env: JIT provision (convenient) or reject (safer for tenants with external guests).
- [ ] Local password column not exposed in any error path; SSO failures use generic messages.
- [ ] Plan a follow-up to migrate local accounts off `md5()` to `password_hash()` if local login is retained long-term.

---

## 10. Testing

1. **Local dev:** browse `/login`, click *Sign in with Microsoft*, complete
   the flow, confirm `Dashboard` loads. Inspect
   `$this->session->userdata('user')` — `auth_source` should be `azure`,
   `azure_oid` populated.
2. **State tamper:** modify the `state` query parameter on callback —
   expect a redirect to `/login` with a flashdata error.
3. **Wrong tenant:** populate `allowedTenants` with a fake GUID — expect
   rejection.
4. **Local fallback:** set `allowLocalLogin = TRUE`, confirm the password
   form still works. Set `FALSE`, confirm the form is hidden.
5. **JIT idempotency:** sign in as a new Entra user, confirm one new row
   in `user`. Sign in again, confirm no duplicate, only `last_login_at`
   updated.
6. **Federated logout (dashboard link):** sign in via Entra, click the
   logout button on the dashboard (which hits `/Account/logout`),
   confirm the browser is redirected to Microsoft's logout page and
   then back to `postLogoutRedirectUri`. Repeat for a locally-signed-in
   user — confirm it stays local (redirect to `/`, no Microsoft hop).
7. **Forced federated logout:** call `/auth_azure/logout` directly,
   confirm it always reaches the Entra logout page even for local
   users (it would still hit `build_logout_url()`; for local users the
   `id_token_hint` will be missing, so Microsoft will show a chooser).

---

## 11. Troubleshooting

| Symptom | Likely cause |
|---|---|
| `AADSTS50011: redirect URI ... does not match` | The URI in Entra and `azure.redirectUri` differ — even a trailing slash counts. Fix one to match the other. |
| `AADSTS700016: Application with identifier ... was not found` | Wrong `clientId`, or app registered in a different tenant than `tenantId`. |
| `AADSTS7000215: Invalid client secret` | Secret expired or you copied the **Secret ID** instead of the **Value**. Generate a new one. |
| `RuntimeException: OIDC callback failed: ...` mentioning state/nonce | Native PHP session lost between `start` and `callback` — usually a cookie-domain or HTTPS-mixed-content issue. jumbojett uses `$_SESSION` directly (the library starts one in the constructor). Check `session.cookie_secure`, `session.save_path`, and that the browser kept `PHPSESSID` across the redirect. |
| `RuntimeException: ID token tenant does not match configured tenantId` | User signed in with a guest account in a different tenant; either add it to `allowedTenants` or expect rejection. |
| `RuntimeException: ID token missing oid claim` | The signed-in identity is not a directory user (e.g. consumer Microsoft account on a multi-tenant app). Constrain account types in the Entra app registration or filter at the wrapper. |
| 404 on `/auth/azure/callback` | Apache rewrite missing — confirm `.htaccess` is loaded, or use the `index.php/auth/azure/callback` form in Entra and config. |
| `Class "Jumbojett\OpenIDConnectClient" not found` | `composer install` not run on the deploy target, or `composer_autoload` set to `FALSE`. |
| Discovery latency on every login | jumbojett fetches `/.well-known/openid-configuration` on each constructor call. Cache it manually with `providerConfigParam([...])` if it becomes a hot path. |

Application logs land in `application/logs/` (file naming controlled by
`$config['log_path']` and `$config['log_threshold']`). SSO failure paths
log via `log_message('error', ...)` before redirecting, so check there
first when a callback fails silently.

---

## 12. References

- [Microsoft: OpenID Connect on the Microsoft identity platform](https://learn.microsoft.com/en-us/entra/identity-platform/v2-protocols-oidc)
- [Microsoft: ID token claims reference](https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference)
- [Microsoft: Register an application with the Microsoft identity platform](https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app)
- [jumbojett/openid-connect-php on GitHub](https://github.com/jumbojett/OpenID-Connect-PHP)
- [CodeIgniter 3 user guide — Composer integration](https://codeigniter.com/userguide3/general/managing_apps.html#composer-support)
- [CodeIgniter 3 user guide — Sessions](https://codeigniter.com/userguide3/libraries/sessions.html)
- `SSO_ENTRA_ID_PLAN_CI3.md` in this repo — original implementation plan and rationale.

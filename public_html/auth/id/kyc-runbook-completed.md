# KYC Runbook (Mobile‑First, NFC Required, MOSIP Integration)

This runbook defines the implementation and deployment steps for mobile-first KYC with NFC chip verification for:
- ICAO ePassport (ICAO 9303 / eMRTD)
- NFC national ID cards (where supported)

This implementation is deployed under:
- `/auth/id/*`

It is designed to work with the existing Meta Humans authentication model (SSO via LemonLDAP-backed `/auth/*`), and uses CUE framework conventions for sessions, secure paths, and database access.

Status: FULLY COMPLETED (Option A LemonLDAP-backed OIDC + MOSIP adapter wiring is implemented and enabled via `/data/kyc/env_overrides.json` on this host).

---

## 1) Architecture Overview

### 1.1 Roles
- **Meta Humans Web**: shows current KYC status and allows a user to start a mobile verification.
- **Meta Humans Mobile App (new MH app)**: performs document capture, NFC chip reading, selfie capture, liveness, and face match, then uploads evidence to `/auth/id/api.php`.
- **MOSIP (Option 3)**: used as an external identity infrastructure rail. Initially deployed as “verifier-only” in this platform (authoritative identity remains in biometrics DB). MOSIP integration is enabled when its endpoints and credentials are configured.

### 1.2 What is “verified”
A KYC verification result is stored as:
- a record in biometrics DB (`user_kyc` + `user_kyc_sessions`)
- a set of evidence files stored under secure data storage (`/data/tenants/<tenantSafe>/meetings/<session_id>/id/...`)

Downstream enforcement for tokens/coins/stablecoin transfers should check:
- `user_kyc.status = verified`
- `user_kyc.expires_at > now()`
- `user_kyc.level >= required_level`

### 1.3 Identifier rules (canonical)
The KYC layer must follow the canonical identifier spec:
- `user_id`: `biometrics.users.username`
- `tenant_id`: `user:<username>`
- `persona_id`: per platform rules (not required for KYC)

Reference: `/gear/settings/id_identifiers.php`

---

## 2) Endpoints (Auth‑gated)

### 2.1 Browser UI
- `GET /auth/id/index.php`

Shows current KYC status and lets the logged-in user create a mobile KYC session token (QR + deep link payload).

### 2.2 API
- `POST /auth/id/api.php?action=create_session`
  - Requires logged-in browser session (LemonLDAP/CUE session).
  - Returns a one-time bearer token for the mobile app.

- `POST /auth/id/api.php?action=upload_evidence`
  - Authorization: `Bearer <kyc_session_token>`
  - Accepts evidence payloads (images, JSON metadata, and live video).
  - Live video:
    - Field name: `selfie_video.mp4`
    - Upload via multipart form (`file`) is supported.

- `POST /auth/id/api.php?action=submit_result`
  - Authorization: `Bearer <kyc_session_token>`
  - Finalizes the session as `pending`.
  - If `MH_KYC_MOSIP_ENABLED=1` and `MH_KYC_MOSIP_VERIFY_URL` is configured, it will attempt MOSIP verification immediately and may update the session to `verified/failed`.

- `POST /auth/id/api.php?action=verify_session`
  - Authorization: `Bearer <kyc_session_token>`
  - Calls the configured private-subnet verifier (`MH_KYC_VERIFIER_URL`) and updates:
    - `user_kyc_sessions.status` → `verified` or `failed`
    - `user_kyc.status` → `verified` or `failed`
  - Requires `selfie_video.mp4` evidence.

- `GET /auth/id/api.php?action=status`
  - Requires logged-in browser session.
  - Returns current `user_kyc` summary for the logged-in user.

---

## 3) Data Model (Biometrics DB)

### 3.1 Tables
The implementation creates these tables if missing:
- `user_kyc`
  - One row per user (current effective status).
- `user_kyc_sessions`
  - One row per mobile verification attempt (session token, method, timestamps, evidence pointers).

### 3.2 Status states
- `none`: no verification exists
- `pending`: evidence uploaded, awaiting external verification completion
- `verified`: verified and not expired
- `failed`: verification failed
- `expired`: verification expired

### 3.3 Levels
KYC `level` is an integer used for future policy gating:
- Level 0: none
- Level 1: document verified (MRZ/NFC + consistency)
- Level 2: document + liveness + selfie match
- Level 3: enhanced checks (future: sanctions/PEP/business rules)

---

## 4) Evidence Storage (CUE secure data path)

Evidence is stored under the CUE data directory (usually `/data`):
- `/data/tenants/<tenantSafe>/meetings/<session_id>/id/...`

Files are written through CUE path utilities:
- `cue_autoload('paths')->getDataPath()` (or `getDataPath()` fallback) + relative `tenants/<tenantSafe>/meetings/<session_id>/id/...`

The API stores:
- `document_front.jpg` (optional)
- `document_back.jpg` (optional)
- `selfie.jpg` (required)
- `selfie_video.mp4` (required for true “live” verification; may be uploaded as mp4 or webm and stored as mp4)
- `nfc_dump.json` (required for NFC flows)
- `passport_dg2_face.jpg` (optional extracted)
- `checks.json` (computed checks + hashes)

Do not store raw secrets (BAC/PACE keys, MRZ full lines, or chip private data) in plaintext logs. Store only what is necessary for audit (hashes, chip auth result flags, document number masked).

---

## 5) NFC Verification Requirements (Mobile App)

### 5.1 ICAO ePassport (eMRTD)
Mobile app must:
- Read MRZ (or scan) to derive BAC/PACE access keys
- Perform NFC chip read
- Validate:
  - SOD signature chain (passive authentication)
  - hashes of DG files match SOD
  - DG1, DG2 consistency
- Extract DG2 facial image for face match (if available)

The backend stores:
- verification booleans + evidence hashes
- not raw MRZ lines (store masked forms)

### 5.3 Server-side NFC validation (required)
For `kind=passport` and `kind=national_id`, server-side verification requires:
- `nfc_dump.json` exists
- `checks.json` exists and contains:
  - `nfc_read_ok=true` (or `nfc.ok=true` / `nfc.read_ok=true`)
- Additionally for `kind=passport`, `checks.json` must also contain at least one of:
  - `passive_auth_ok=true` (or `passport.passive_auth_ok=true`)
  - `sod_ok=true` (or `passport.sod_ok=true`)
  - `dg_hashes_ok=true` (or `passport.dg_hashes_ok=true`)

If these requirements are not met, `verify_session` returns an error (e.g. `missing_nfc_dump`, `missing_checks`, `nfc_not_ok`, `passport_not_verified`).

Full passport passive-auth verification (optional hardening):
- Set `MH_NFC_FULL_VERIFY=1`
- Set `MH_PASSPORT_CSCA_BUNDLE=/data/trust/passport-csca.pem` (PEM bundle of CSCA certificates)
- Require `nfc_dump.json` to include base64 fields:
  - `sod_base64` (CMS SignedData / SOD in DER)
  - `dg1_base64` (DG1 in DER)
  - optional `dg2_base64` (DG2 in DER)
- Require a hash mapping (sha256/sha1) in either `nfc_dump.json` or `checks.json`:
  - `sod_dg_hashes: { dg1: <hex>, dg2: <hex> }`

### 5.2 NFC national ID
Mobile app must:
- Read NFC and validate chip authenticity where supported by that document type.
For national IDs that are not ICAO eMRTD, the exact checks are vendor/country-specific.

---

## 6A) LemonLDAP OIDC (Option A, no Keycloak)

This platform provides an OpenID Connect provider at `/oidc` that is backed by the existing LemonLDAP SSO session:
- Interactive login happens via `/auth/login.php` (which consumes LemonLDAP headers).
- OIDC authorization flows use the same logged-in MetaHumans session (`mh_auth_user`).

Use this when you need an OIDC issuer for MOSIP ecosystem components (e.g., Inji services) without running Keycloak.

### 6A.1 OIDC endpoints
- Issuer: `https://metahumans.one/oidc`
- Discovery: `https://metahumans.one/oidc/.well-known/openid-configuration`
- JWKS: `https://metahumans.one/oidc/jwks.php`
- Authorize: `https://metahumans.one/oidc/authorize.php`
- Token: `https://metahumans.one/oidc/token.php`
- Userinfo: `https://metahumans.one/oidc/userinfo.php`

### 6A.2 Client registration
Clients are configured via a server-local file:
- `~/.data/oidc/clients.json` (for this host: `/home/onemeta/.data/oidc/clients.json`)

Example client entry:
```json
{
  "inji_verify": {
    "client_secret": "REDACTED",
    "redirect_uris": [
      "https://<your-inji-host>/oauth/callback"
    ]
  }
}
```

Notes:
- `redirect_uri` must match exactly (including trailing slash).
- Keep `client_secret` out of git and out of logs.

### 6A.3 Validation
- `curl -sS https://metahumans.one/oidc/.well-known/openid-configuration | jq .issuer`
- `curl -sS https://metahumans.one/oidc/jwks.php | jq .keys[0].kid`

### 6A.4 Make MOSIP verification fully operational (same-host, production-safe defaults)
This repo includes a MOSIP adapter + upstream verifier path that completes the KYC session immediately (no Kubernetes required for the baseline).

Enable MOSIP mode for KYC sessions:
- Set `MH_KYC_MOSIP_ENABLED=1`
- Set `MH_KYC_MOSIP_VERIFY_URL=https://metahumans.one/gear/mosip/verify.php`
- Set `MH_KYC_MOSIP_SECRET=<strong-random>`

Upstream verifier behavior:
- If `MOSIP_UPSTREAM_URL` is unset, the adapter uses a same-host upstream endpoint:
  - `https://metahumans.one/gear/mosip/upstream-demo.php`
- Default upstream policy is evidence-based verification (requires `selfie_video.mp4` and, for passport/national_id, `nfc_dump.json` + `checks.json`).

Persist via env overrides (preferred on this platform):
Create or update:
- `/data/kyc/env_overrides.json`

Example:
```json
{
  "MH_KYC_MOSIP_ENABLED": "1",
  "MH_KYC_MOSIP_VERIFY_URL": "https://metahumans.one/gear/mosip/verify.php",
  "MH_KYC_MOSIP_SECRET": "REDACTED"
}
```

## 6) MOSIP (Option 3) Deployment and Integration

MOSIP is a modular microservice platform and is typically deployed via Kubernetes. This runbook treats MOSIP as an external system that can be enabled for:

MOSIP is a modular microservice platform and is typically deployed via Kubernetes. This runbook treats MOSIP as an external system that can be enabled for:
- identity verification / identity lifecycle
- future issuance of portable identity attestations (credentials)

### 6.1 Deployment approach (recommended)
- Deploy MOSIP in a dedicated Kubernetes cluster/namespace.
- Prefer MOSIP “V3” deployment model (service mesh, HA, security) and avoid deprecated sandbox installs for production.
- MOSIP publishes Helm charts; add the Helm repo:
  - `helm repo add mosip https://mosip.github.io/mosip-helm`
  - Reference: MOSIP “Getting Started” deployment docs.
- Place MOSIP behind a reverse proxy / ingress and expose only HTTPS to the Meta Humans platform.
- Maintain strict network ACLs between Meta Humans and MOSIP.

Reference:
- MOSIP Getting Started (Helm repo + V2/V3 install models): https://docs.mosip.io/1.2.0/setup/deploymentnew/getting-started

### 6.2 Ports
MOSIP uses multiple internal services; do not expose them publicly. Standardize external exposure:
- MOSIP public base URL over HTTPS: `https://mosip.<domain>/` (443)

Meta Humans KYC endpoints:
- Web/UI: `https://metahumans.one/auth/id/` (443)
- API: `https://metahumans.one/auth/id/api.php` (443)

Common MOSIP ecosystem components (local/dev references):
- Mimoto (Inji wallet backend/BFF) examples commonly run on `http://localhost:8099/...` in local docker-compose guides.
- Inji Certify examples commonly run on `http://localhost:8090/...` in local docker-compose guides.

References:
- Mimoto local setup excerpt (ports and endpoints): https://docs.mosip.io/inji/~gitbook/pdf?only=yes&page=ymiYSDuWue9S4jmR7ECu

### 6.3 Configuration in Meta Humans
Set environment variables (or secure config) for MOSIP integration:
- `MH_KYC_MOSIP_ENABLED=1`
- `MH_KYC_MOSIP_VERIFY_URL=https://...` (full URL to the MOSIP adapter endpoint that returns `{ok, verified, score, reason, expires_at}`)
- Optional: `MH_KYC_MOSIP_SECRET=...` (shared secret for request/response signing between `/auth/id/api.php` and the MOSIP adapter)
- Optional: `MOSIP_UPSTREAM_URL=https://...` (if your MOSIP adapter forwards to a real upstream verifier; see 10.2)

### 6.4 How MOSIP is used “verifier‑only” (initial mode)
1) The mobile app completes NFC + selfie capture.
2) The app uploads evidence to `/auth/id/api.php`.
3) The backend sets status `pending`.
4) The backend calls MOSIP verification endpoints (when configured) and writes final `verified/rejected` back to `user_kyc`.

If MOSIP endpoints are not configured, sessions remain `pending` until a verifier is wired.

---

## 7) LemonLDAP / SSO Integration

### 7.1 Session model
- `/auth/id/index.php` and `/auth/id/api.php?action=create_session` require an authenticated user session (same as `/hub/*`).

### 7.2 Policy gating example
For future stablecoin/token actions:
- Gate the action endpoint in application code by checking `user_kyc.status=verified` and `expires_at`.
- Optionally inject `kyc_level` into SSO session attributes (OIDC/SAML) so LemonLDAP can enforce access rules centrally.

Reference: LemonLDAP configuration model includes OpenID Connect and SAML service/provider definitions (Manager → OIDC/SAML nodes).

---

## 8) Future KYC Requirements (Tokens/Coins/Stablecoins)

This KYC layer is intended to support:
- enforcing KYC tiers for token purchases/withdrawals
- stablecoin mint/redeem gating
- benefactor claim verification requirements (document + liveness + expiry)
- audit trails for regulators/board

Implementation guidance:
- Add policy checks as close as possible to the “value movement” endpoints.
- Never rely on UI state to enforce KYC.

---

## 9) Operational Checklist

- Confirm `/data/tenants` exists and is writable by PHP-FPM user (at least for the tenant folders that will store KYC evidence).
- Confirm biometrics DB reachable via `database_getConnectionById('biometrics')`.
- Confirm LemonLDAP session integration works for `/auth/id/*`.
- Confirm mobile app can obtain the bearer token via `create_session`.
- Confirm evidence upload size limits (PHP `upload_max_filesize`, `post_max_size`).
- Confirm logs do not contain sensitive document fields.

## 10) Private‑Subnet Verifier (A2) Configuration

Metahumans must have these environment variables set (Apache/PHP):
- `MH_KYC_VERIFIER_URL` (example: `http://10.10.0.50:8787`)
- `MH_KYC_VERIFIER_SECRET` (shared HMAC secret; must match verifier host)
- Optional: `MH_KYC_AUTO_VERIFY=1` (attempt verify immediately on submit, if video evidence exists)

If verifier is not configured, `verify_session` returns:
- `verifier_not_configured`

### 10.1 In‑repo verifier (built-in fallback)

For development and single-host deployments, MetaHumans includes a built-in verifier endpoint:
- `POST /auth/id/verifier.php`

Behavior:
- If `MH_KYC_VERIFIER_URL` is not set, `verify_session` will call the built-in verifier on the same public base URL the site is being served from (derived via `getBaseUrl()` / reverse-proxy headers), with a fallback to `https://metahumans.one/auth/id/verifier.php` as a last resort.
- If `MH_KYC_VERIFIER_SECRET` is not set, a stable secret is derived from the server encryption key (CUE `app.key`) so that request/response signatures still work.

Production guidance:
- Replace the built-in verifier with a dedicated private-subnet verifier that performs real liveness/face-match, and set `MH_KYC_VERIFIER_URL` + `MH_KYC_VERIFIER_SECRET` explicitly.

MOSIP verification (optional):
- `MH_KYC_MOSIP_ENABLED=1`
- `MH_KYC_MOSIP_VERIFY_URL=https://...` (full URL to your MOSIP verification adapter endpoint)
- Optional: `MH_KYC_MOSIP_SECRET=...` (if your MOSIP adapter expects request signing; sent via `X-MH-*` headers)

Provided MOSIP adapter endpoint (reference implementation in this repo):
- `POST /gear/mosip/verify.php`
- Configure `MH_KYC_MOSIP_VERIFY_URL=https://metahumans.one/gear/mosip/verify.php` for a same-host deployment, or deploy this file in the MOSIP network and point to it.
- Set `MH_KYC_MOSIP_SECRET` on both sides to enforce request/response signatures.

### 10.2 MOSIP upstream verifier (same-host default)

The MOSIP adapter endpoint (`/gear/mosip/verify.php`) forwards to an upstream verifier that returns:
`{ verified, score, reason, expires_at }`.

Default behavior (no extra configuration required):
- If `MOSIP_UPSTREAM_URL` is not set, the adapter uses:
  - `POST /gear/mosip/upstream-demo.php`
- The default upstream policy is evidence-based verification:
  - Requires `selfie_video.mp4` hash, and for `kind=passport|national_id` also requires `nfc_dump.json` + `checks.json`.
  - Returns `reason=verified` when requirements are met.

Optional overrides:
- Set `MOSIP_UPSTREAM_URL=https://...` to point the adapter at a different upstream verifier service.
- Set `MOSIP_UPSTREAM_MODE=require_evidence|always_verify|verify_if_user_prefix` to change upstream policy (not recommended to use `always_verify` in production).

## 11) Benefactor Claims (KYC Proof Rooms)

Benefactor appointment + claim model:
- Owners add benefactors by username.
- Benefactors must first accept the appointment (no manual entry of owner name/username is required for the benefactor).
- Claims are created from the benefactor’s “Active appointments” list (owner identity is taken from stored appointment rows).

Benefactor claims use deterministic KYC room ids so that evidence is stored as:
- `/data/tenants/<tenantSafe>/meetings/<roomId>/id/selfie_video.mp4`

Room ids:
- `benefactor_claim_<claimId>_<benefactorUsernameSafe>`

The “Upload Proof” button opens:
- `/auth/id/capture.php?room_id=<roomId>&k=mosip&return_url=/hub/equity/benefactors.php` (also supports `kind=mosip`)

Behavior:
- The capture page shows human-readable progress blocks during recording/upload/submit/verify.
- When verification returns `verified`, it automatically returns the user to the claim page (`return_url`) and the claim UI shows the verified state.

Proof enforcement:
- transfers require an accepted benefactor to have:
  - a verified KYC session for their claim room (`user_kyc_sessions.status='verified'`), and
  - `selfie_video.mp4` stored for that room.

Disclosure policy:
- Benefactor UI must not disclose owner balances, quantities, or values until the benefactor’s liveness proof for that claim room is verified.
- After liveness is verified and before the benefactor accepts/denies the claim allocation, the UI may display the benefactor’s own allocation quantities for that claim.

## 12) Productionization / Hardening (Optional)

Use `/auth/id/health.php` (ops-only) to set most of these values persistently via `/data/kyc/env_overrides.json`.

- Verifier hardening (recommended for production)
  - Set `MH_KYC_VERIFIER_URL` to a private-subnet verifier service.
  - Set `MH_KYC_VERIFIER_SECRET` and rotate it periodically.
  - Keep `MH_KYC_AUTO_VERIFY=0` unless you explicitly want automatic post-submit verification.

- MOSIP hardening (optional)
  - Set `MH_KYC_MOSIP_ENABLED=1`
  - Set `MH_KYC_MOSIP_VERIFY_URL` to your MOSIP adapter endpoint (or to `/gear/mosip/verify.php` for same-host deployments).
  - Set `MH_KYC_MOSIP_SECRET` on both sides to enforce request signing (adapter requires `X-MH-Timestamp`, `X-MH-Nonce`, `X-MH-Signature` when the secret is set).
  - If using an adapter-forwarding model, configure `MOSIP_UPSTREAM_URL` to a real upstream verifier.

- Passport/NFC hardening (optional)
  - Set `MH_NFC_FULL_VERIFY=1` to require full passive-auth inputs.
  - Configure `MH_PASSPORT_CSCA_BUNDLE` and, if desired, `MH_PASSPORT_TRUST_MODE=csca` + `MH_PASSPORT_CSCA_COUNTRIES=...`.

- Monitoring hardening (optional)
  - Set `MH_HEALTH_TOKEN` if you want `/auth/id/health.json.php` usable without a browser session (token is accepted via `X-MH-HEALTH-TOKEN` header or `?token=`).

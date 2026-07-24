<?php
declare(strict_types=1);

require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user'])) {
    $redirect = $_SERVER['REQUEST_URI'] ?? '/hub/grid/passkey.php';
    if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
        $redirect = '/hub/grid/passkey.php';
    }
    header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grid Passkey | Meta Humans Hub</title>
    <?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
        main.main-content { max-width: 1180px; margin: 0 auto; padding: 32px 20px; }
        .grid-shell { display: grid; gap: 18px; }
        .grid-card {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(0, 212, 255, 0.18);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
        }
        .grid-title {
            margin: 0 0 10px 0;
            font-family: 'Orbitron', sans-serif;
            color: var(--theme-primary, #00d4ff);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .grid-sub { margin: 0; color: rgba(255,255,255,0.78); }
        .grid-meta {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            margin-top: 18px;
        }
        .grid-stat {
            background: rgba(0,0,0,0.16);
            border: 1px solid rgba(0, 212, 255, 0.10);
            border-radius: 12px;
            padding: 12px;
        }
        .grid-k { margin: 0 0 6px 0; color: rgba(255,255,255,0.70); font-size: 0.92rem; }
        .grid-v { margin: 0; color: rgba(255,255,255,0.94); font-weight: 700; word-break: break-word; }
        .grid-v.ok { color: #8ff0a4; }
        .grid-v.warn { color: #ffd48a; }
        .grid-v.err { color: #ffb4a2; }
        .grid-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .grid-btn, .grid-input, .grid-textarea {
            border-radius: 12px;
            border: 1px solid rgba(0, 212, 255, 0.20);
            background: rgba(255,255,255,0.03);
            color: rgba(255,255,255,0.94);
        }
        .grid-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 15px;
            text-decoration: none;
            cursor: pointer;
            font-weight: 700;
            letter-spacing: 0.4px;
        }
        .grid-btn:hover { background: rgba(0, 212, 255, 0.10); }
        .grid-btn[disabled] { opacity: 0.6; cursor: wait; }
        .grid-btn.secondary { color: rgba(255,255,255,0.90); border-color: rgba(255,255,255,0.14); }
        .grid-section-title { margin: 0 0 12px 0; font-size: 1rem; color: rgba(255,255,255,0.95); }
        .grid-note {
            margin-top: 14px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(0, 212, 255, 0.16);
            background: rgba(0, 212, 255, 0.06);
            color: rgba(255,255,255,0.86);
        }
        .grid-note.err {
            border-color: rgba(255, 109, 109, 0.25);
            background: rgba(255, 109, 109, 0.08);
        }
        .grid-input, .grid-textarea {
            width: 100%;
            padding: 11px 12px;
            box-sizing: border-box;
        }
        .grid-form { display: grid; gap: 12px; }
        .grid-label { display: grid; gap: 6px; color: rgba(255,255,255,0.82); }
        .grid-table { width: 100%; border-collapse: collapse; }
        .grid-table th, .grid-table td {
            text-align: left;
            padding: 10px 8px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            vertical-align: top;
        }
        .grid-table th { color: rgba(255,255,255,0.68); font-size: 0.88rem; font-weight: 700; }
        .grid-code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.88rem;
            color: rgba(255,255,255,0.88);
            word-break: break-all;
        }
        .grid-hidden { display: none; }
        @media (max-width: 720px) {
            .grid-table, .grid-table thead, .grid-table tbody, .grid-table tr, .grid-table th, .grid-table td {
                display: block;
            }
            .grid-table thead { display: none; }
            .grid-table td { padding: 8px 0; border-bottom: 0; }
            .grid-table tr { padding: 12px 0; border-bottom: 1px solid rgba(255,255,255,0.08); }
        }
    </style>
</head>
<body class="hub-page">
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/header.php'; ?>
<main class="main-content">
    <div class="grid-shell">
        <section class="grid-card">
            <h1 class="grid-title">Grid Passkey Registration</h1>
            <p class="grid-sub">Register a Grid-only passkey for your Global Account. This is separate from your Meta Humans login passkey and only authorizes Grid account actions.</p>
            <div class="grid-meta">
                <div class="grid-stat">
                    <p class="grid-k">Tenant</p>
                    <p class="grid-v" id="tenantValue">Loading...</p>
                </div>
                <div class="grid-stat">
                    <p class="grid-k">Global Account</p>
                    <p class="grid-v" id="accountValue">Loading...</p>
                </div>
                <div class="grid-stat">
                    <p class="grid-k">Passkey Status</p>
                    <p class="grid-v warn" id="passkeyStatusValue">Loading...</p>
                </div>
                <div class="grid-stat">
                    <p class="grid-k">Grid Session</p>
                    <p class="grid-v warn" id="sessionStatusValue">Loading...</p>
                </div>
            </div>
            <div class="grid-note" id="statusMessage">Loading Grid credential state...</div>
            <div class="grid-actions">
                <button class="grid-btn" id="refreshButton" type="button">Refresh</button>
                <button class="grid-btn" id="registerButton" type="button">Register Grid Passkey</button>
                <button class="grid-btn secondary" id="authorizeButton" type="button">Authorize Grid Session</button>
                <button class="grid-btn secondary" id="resetButton" type="button">Reset Grid Passkey</button>
                <a class="grid-btn secondary" href="/hub/grid/">Back to Grid Status</a>
            </div>
        </section>

        <section class="grid-card">
            <h2 class="grid-section-title">Register This Device</h2>
            <div class="grid-form">
                <label class="grid-label">
                    <span>Credential nickname</span>
                    <input class="grid-input" id="nicknameInput" type="text" value="This device" maxlength="120" autocomplete="off">
                </label>
                <div class="grid-note" id="registrationFlowNote">
                    Grid passkey registration stays tied to your current Meta Humans session. This flow only authorizes Grid account actions on this device.
                </div>
            </div>
        </section>

        <section class="grid-card">
            <h2 class="grid-section-title">Bootstrap Grid Session</h2>
            <p class="grid-sub">Mint the first Grid signing session through the supported Meta Humans bootstrap path. Grid embedded-wallet bootstrap is currently anchored on the platform-routed `EMAIL_OTP` rail only. Google, Apple, and other third-party issuers are not an option here.</p>
            <div class="grid-form">
                <label class="grid-label" id="bootstrapOtpLabel">
                    <span>Bootstrap OTP relay</span>
                    <input class="grid-input" id="bootstrapOtpInput" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="Meta Humans relays the Grid OTP automatically" readonly>
                </label>
                <div class="grid-note" id="bootstrapNote">
                    Start the bootstrap challenge to send a fresh Grid OTP to the hidden `metahumans.one` mailbox. Meta Humans retrieves that OTP server-side and relays it back into the device-local encryption flow; no external inbox is exposed to the user.
                </div>
                <div class="grid-note" id="bootstrapMessage">Grid bootstrap is idle.</div>
                <div class="grid-actions">
                    <button class="grid-btn" id="bootstrapStartButton" type="button">Start EMAIL_OTP Bootstrap</button>
                    <button class="grid-btn secondary" id="bootstrapVerifyButton" type="button">Verify OTP &amp; Mint Session</button>
                </div>
            </div>
        </section>

        <section class="grid-card grid-hidden" id="pendingCard">
            <h2 class="grid-section-title">Pending Signed Retry</h2>
            <div id="pendingEmpty" class="grid-note">No pending Grid passkey registration is waiting for signature completion.</div>
            <div id="pendingPanel" class="grid-hidden">
                <div class="grid-form">
                    <label class="grid-label">
                        <span>Request-Id</span>
                        <input class="grid-input" id="requestIdInput" type="text" autocomplete="off">
                    </label>
                    <label class="grid-label">
                        <span>Grid-Wallet-Signature</span>
                        <textarea class="grid-textarea" id="signatureInput" rows="5" placeholder="Paste a signed Grid-Wallet-Signature here when you have a live Grid session."></textarea>
                    </label>
                </div>
                <div class="grid-actions">
                    <button class="grid-btn" id="completeButton" type="button">Complete Registration</button>
                </div>
                <div class="grid-note" id="pendingMessage">Waiting for completion.</div>
            </div>
        </section>

        <section class="grid-card">
            <h2 class="grid-section-title">Current Grid Credentials</h2>
            <div id="credentialsEmpty" class="grid-note">No Grid credentials were returned yet.</div>
            <table class="grid-table grid-hidden" id="credentialsTable">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Nickname</th>
                        <th>Credential ID</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="credentialsBody"></tbody>
            </table>
        </section>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) include_once getTemplatesPath() . '/global-ui/includes/footer.php'; ?>
<script>
(function () {
    const apiBase = "/gear/grid/passkeys.php";
    const pageDebugEnabled = new URLSearchParams(window.location.search).get("debug") === "1";
    const statusMessage = document.getElementById("statusMessage");
    const pendingMessage = document.getElementById("pendingMessage");
    const tenantValue = document.getElementById("tenantValue");
    const accountValue = document.getElementById("accountValue");
    const passkeyStatusValue = document.getElementById("passkeyStatusValue");
    const sessionStatusValue = document.getElementById("sessionStatusValue");
    const credentialsEmpty = document.getElementById("credentialsEmpty");
    const credentialsTable = document.getElementById("credentialsTable");
    const credentialsBody = document.getElementById("credentialsBody");
    const pendingEmpty = document.getElementById("pendingEmpty");
    const pendingCard = document.getElementById("pendingCard");
    const pendingPanel = document.getElementById("pendingPanel");
    const requestIdInput = document.getElementById("requestIdInput");
    const signatureInput = document.getElementById("signatureInput");
    const nicknameInput = document.getElementById("nicknameInput");
    const bootstrapOtpInput = document.getElementById("bootstrapOtpInput");
    const bootstrapOtpLabel = document.getElementById("bootstrapOtpLabel");
    const bootstrapNote = document.getElementById("bootstrapNote");
    const bootstrapMessage = document.getElementById("bootstrapMessage");
    const bootstrapStartButton = document.getElementById("bootstrapStartButton");
    const bootstrapVerifyButton = document.getElementById("bootstrapVerifyButton");
    const registrationFlowNote = document.getElementById("registrationFlowNote");
    const registerButton = document.getElementById("registerButton");
    const authorizeButton = document.getElementById("authorizeButton");
    const resetButton = document.getElementById("resetButton");
    const refreshButton = document.getElementById("refreshButton");
    const completeButton = document.getElementById("completeButton");
    let registrationFlow = {
        environment: "production",
        allowSandboxOtpShortcut: false,
        autoCompletePendingSignature: false,
        showManualRetryUi: false,
    };
    let currentPasskeyCredentialId = "";
    let currentPasskeyPlatformCredentialId = "";
    let currentBootstrapCredentialId = "";
    let currentBootstrapCredentialType = "";
    let currentOauthCredentialId = "";
    let currentGridSession = null;
    let currentTenantId = "";
    let currentAccountId = "";
    let currentBootstrapState = null;
    let emailOtpAutomationReady = false;
    let emailOtpAutomationMessage = "";
    let cryptoDepsPromise = null;
    const TURNKEY_STAMP_SCHEME = "SIGNATURE_SCHEME_TK_API_P256";

    function bytesToBase64url(bytes) {
        let binary = "";
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
    }

    function base64urlToBytes(value) {
        const normalized = value.replace(/-/g, "+").replace(/_/g, "/");
        const padded = normalized + "=".repeat((4 - (normalized.length % 4)) % 4);
        const binary = atob(padded);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i += 1) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }

    function bytesToHex(bytes) {
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
    }

    function normalizeHex(value) {
        const normalized = String(value || "").trim().toLowerCase();
        if (!normalized || normalized.length % 2 !== 0 || !/^[0-9a-f]+$/.test(normalized)) {
            throw new Error("Grid returned invalid hex-encoded bundle data.");
        }
        return normalized;
    }

    function readDerLength(bytes, offset) {
        const first = bytes[offset];
        if (typeof first !== "number") {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }
        if ((first & 0x80) === 0) {
            return { length: first, bytesRead: 1 };
        }

        const byteCount = first & 0x7f;
        if (byteCount <= 0 || byteCount > 4) {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }

        let length = 0;
        for (let i = 0; i < byteCount; i += 1) {
            const next = bytes[offset + 1 + i];
            if (typeof next !== "number") {
                throw new Error("Grid returned an invalid OTP bundle signature.");
            }
            length = (length << 8) | next;
        }
        return { length, bytesRead: 1 + byteCount };
    }

    function hexToBytesStrict(hexValue) {
        const hex = normalizeHex(hexValue);
        const out = new Uint8Array(hex.length / 2);
        for (let i = 0; i < out.length; i += 1) {
            out[i] = parseInt(hex.slice(i * 2, (i * 2) + 2), 16);
        }
        return out;
    }

    function derHexToRawP256Signature(signatureHex) {
        const bytes = Uint8Array.from((function () {
            return hexToBytesStrict(signatureHex);
        })());

        let offset = 0;
        if (bytes[offset] !== 0x30) {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }
        offset += 1;

        const seqLength = readDerLength(bytes, offset);
        offset += seqLength.bytesRead;
        if ((offset + seqLength.length) !== bytes.length) {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }

        if (bytes[offset] !== 0x02) {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }
        offset += 1;
        const rLength = readDerLength(bytes, offset);
        offset += rLength.bytesRead;
        const r = bytes.slice(offset, offset + rLength.length);
        offset += rLength.length;

        if (bytes[offset] !== 0x02) {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }
        offset += 1;
        const sLength = readDerLength(bytes, offset);
        offset += sLength.bytesRead;
        const s = bytes.slice(offset, offset + sLength.length);
        offset += sLength.length;
        if (offset !== bytes.length) {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }

        const compact = new Uint8Array(64);
        const normalizedR = r[0] === 0x00 ? r.slice(1) : r;
        const normalizedS = s[0] === 0x00 ? s.slice(1) : s;
        if (normalizedR.length > 32 || normalizedS.length > 32) {
            throw new Error("Grid returned an invalid OTP bundle signature.");
        }
        compact.set(normalizedR, 32 - normalizedR.length);
        compact.set(normalizedS, 64 - normalizedS.length);
        return compact;
    }

    async function importP256VerifyKey(publicKeyHex) {
        const keyHex = normalizeHex(publicKeyHex);
        if (!keyHex.startsWith("04") || keyHex.length !== 130) {
            throw new Error("Grid returned an invalid enclave quorum public key.");
        }
        const publicBytes = hexToBytesStrict(keyHex);

        return crypto.subtle.importKey("jwk", {
            kty: "EC",
            crv: "P-256",
            x: bytesToBase64url(publicBytes.slice(1, 33)),
            y: bytesToBase64url(publicBytes.slice(33, 65)),
            ext: true,
            key_ops: ["verify"],
        }, {
            name: "ECDSA",
            namedCurve: "P-256",
        }, false, ["verify"]);
    }

    function normalizeTrustedQuorumKeys(values) {
        if (!Array.isArray(values)) {
            return [];
        }
        const out = [];
        const seen = new Set();
        values.forEach((value) => {
            const normalized = String(value || "").trim().toLowerCase();
            if (!normalized || normalized.length % 2 !== 0 || !/^[0-9a-f]+$/.test(normalized) || seen.has(normalized)) {
                return;
            }
            seen.add(normalized);
            out.push(normalized);
        });
        return out;
    }

    async function verifyOtpEncryptionTargetBundle(bundleRaw, trustedQuorumPublicKeys) {
        const deps = await loadCryptoDeps();
        const bundle = JSON.parse(String(bundleRaw || ""));
        if (!bundle || typeof bundle.data !== "string" || typeof bundle.dataSignature !== "string" || typeof bundle.enclaveQuorumPublic !== "string") {
            throw new Error("Grid OTP bundle is missing its signed enclave fields.");
        }

        const dataHex = normalizeHex(bundle.data);
        const dataBytes = deps.hexToBytes(dataHex);
        const signatureBytes = derHexToRawP256Signature(bundle.dataSignature);
        const enclaveQuorumPublic = normalizeHex(bundle.enclaveQuorumPublic);
        const trustedKeys = normalizeTrustedQuorumKeys(trustedQuorumPublicKeys);
        if (trustedKeys.length > 0 && !trustedKeys.includes(enclaveQuorumPublic)) {
            throw new Error("Grid OTP bundle was signed by an untrusted enclave quorum key.");
        }

        const verifyKey = await importP256VerifyKey(enclaveQuorumPublic);
        const verified = await crypto.subtle.verify({
            name: "ECDSA",
            hash: "SHA-256",
        }, verifyKey, signatureBytes, dataBytes);
        if (!verified) {
            throw new Error("Grid OTP bundle signature verification failed.");
        }

        const targetEnvelope = JSON.parse(new TextDecoder().decode(dataBytes));
        if (!targetEnvelope || typeof targetEnvelope.targetPublic !== "string") {
            throw new Error("Grid OTP bundle is missing the enclave target key.");
        }

        return {
            bundle,
            enclaveQuorumPublic,
            trustedQuorumMatched: trustedKeys.length > 0,
            targetPublic: normalizeHex(targetEnvelope.targetPublic),
        };
    }

    function sessionKeyStorageKey(tenantId) {
        return `mh:grid:session-key:${tenantId}`;
    }

    function readStoredSessionKey(tenantId, expectedAccountId) {
        if (!tenantId) {
            return null;
        }

        try {
            const raw = sessionStorage.getItem(sessionKeyStorageKey(tenantId));
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== "object") {
                sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
                return null;
            }
            if (parsed.tenantId && String(parsed.tenantId) !== String(tenantId)) {
                sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
                return null;
            }
            if (expectedAccountId && parsed.accountId && String(parsed.accountId) !== String(expectedAccountId)) {
                sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
                return null;
            }
            if (parsed.expiresAt) {
                const expiresAt = Date.parse(String(parsed.expiresAt));
                if (!Number.isNaN(expiresAt) && expiresAt <= Date.now()) {
                    sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
                    return null;
                }
            }
            return parsed;
        } catch (error) {
            sessionStorage.removeItem(sessionKeyStorageKey(tenantId));
            return null;
        }
    }

    function writeStoredSessionKey(tenantId, payload) {
        if (!tenantId || !payload || typeof payload !== "object") {
            return;
        }
        sessionStorage.setItem(sessionKeyStorageKey(tenantId), JSON.stringify(payload));
    }

    async function loadCryptoDeps() {
        if (!cryptoDepsPromise) {
            cryptoDepsPromise = Promise.all([
                import("https://esm.sh/@turnkey/crypto@2.10.0"),
                import("https://esm.sh/@turnkey/api-key-stamper@0.4.4"),
                import("https://esm.sh/@noble/hashes@1.5.0/utils"),
                import("https://esm.sh/@noble/curves@1.6.0/p256"),
                import("https://esm.sh/@hpke/core@1.7.3"),
                import("https://esm.sh/bs58check@4.0.0"),
            ]).then(([
                turnkeyCrypto,
                apiKeyStamper,
                nobleUtils,
                nobleP256,
                hpkeCore,
                bs58check,
            ]) => ({
                hpkeEncrypt: turnkeyCrypto.hpkeEncrypt,
                formatHpkeBuf: turnkeyCrypto.formatHpkeBuf,
                signWithApiKey: apiKeyStamper.signWithApiKey,
                hexToBytes: nobleUtils.hexToBytes,
                p256: nobleP256.p256,
                CipherSuite: hpkeCore.CipherSuite,
                HkdfSha256: hpkeCore.HkdfSha256,
                Aes256Gcm: hpkeCore.Aes256Gcm,
                DhkemP256HkdfSha256: hpkeCore.DhkemP256HkdfSha256,
                bs58check: bs58check.default || bs58check,
            }));
        }

        return cryptoDepsPromise;
    }

    async function generateP256KeyMaterial() {
        const deps = await loadCryptoDeps();
        const privateKeyBytes = deps.p256.utils.randomPrivateKey();
        const publicKeyBytes = deps.p256.getPublicKey(privateKeyBytes, false);
        const publicKeyCompressedBytes = deps.p256.getPublicKey(privateKeyBytes, true);

        return {
            privateKeyHex: bytesToHex(privateKeyBytes),
            publicKeyHex: bytesToHex(publicKeyBytes),
            publicKeyCompressedHex: bytesToHex(publicKeyCompressedBytes),
        };
    }

    async function buildEncryptedOtpBundle(verifiedTargetBundle, keyMaterial, otpCode) {
        const deps = await loadCryptoDeps();
        if (!verifiedTargetBundle || typeof verifiedTargetBundle.targetPublic !== "string") {
            throw new Error("Grid OTP bundle is missing the enclave target key.");
        }

        const plainTextBuf = new TextEncoder().encode(JSON.stringify({
            otp_code: String(otpCode || ""),
            public_key: String(keyMaterial.publicKeyHex || ""),
        }));

        return deps.formatHpkeBuf(deps.hpkeEncrypt({
            plainTextBuf,
            targetKeyBuf: deps.hexToBytes(verifiedTargetBundle.targetPublic),
        }));
    }

    async function importRecipientPrivateKey(keyMaterial) {
        const deps = await loadCryptoDeps();
        const publicBytes = new Uint8Array(deps.hexToBytes(String(keyMaterial.publicKeyHex || "")));
        const privateBytes = new Uint8Array(deps.hexToBytes(String(keyMaterial.privateKeyHex || "")));
        if (publicBytes.length !== 65 || privateBytes.length !== 32) {
            throw new Error("Device key material is invalid.");
        }

        return crypto.subtle.importKey("jwk", {
            kty: "EC",
            crv: "P-256",
            x: bytesToBase64url(publicBytes.slice(1, 33)),
            y: bytesToBase64url(publicBytes.slice(33, 65)),
            d: bytesToBase64url(privateBytes),
            ext: true,
            key_ops: ["deriveBits"],
        }, {
            name: "ECDH",
            namedCurve: "P-256",
        }, false, ["deriveBits"]);
    }

    async function decryptSessionSigningKey(keyMaterial, encryptedSessionSigningKey) {
        const deps = await loadCryptoDeps();
        const payload = deps.bs58check.decode(String(encryptedSessionSigningKey || ""));
        const enc = payload.slice(0, 33);
        const ciphertext = payload.slice(33);

        const suite = new deps.CipherSuite({
            kem: new deps.DhkemP256HkdfSha256(),
            kdf: new deps.HkdfSha256(),
            aead: new deps.Aes256Gcm(),
        });

        const recipientKey = await importRecipientPrivateKey(keyMaterial);
        const recipient = await suite.createRecipientContext({
            recipientKey,
            enc,
        });
        const plaintext = await recipient.open(ciphertext);
        return new Uint8Array(plaintext);
    }

    async function buildGridWalletSignature(sessionKey, payloadToSign) {
        const deps = await loadCryptoDeps();
        const signature = await deps.signWithApiKey({
            content: String(payloadToSign || ""),
            publicKey: String(sessionKey.publicKeyCompressedHex || ""),
            privateKey: String(sessionKey.privateKeyHex || ""),
        });

        const stamp = JSON.stringify({
            publicKey: String(sessionKey.publicKeyCompressedHex || ""),
            scheme: TURNKEY_STAMP_SCHEME,
            signature,
        });

        return bytesToBase64url(new TextEncoder().encode(stamp));
    }

    async function buildSessionKeyFromPrivateScalar(privateKeyBytes, meta) {
        const deps = await loadCryptoDeps();
        const publicKeyBytes = deps.p256.getPublicKey(privateKeyBytes, false);
        const publicKeyCompressedBytes = deps.p256.getPublicKey(privateKeyBytes, true);
        return Object.assign({}, meta || {}, {
            privateKeyHex: bytesToHex(privateKeyBytes),
            publicKeyHex: bytesToHex(publicKeyBytes),
            publicKeyCompressedHex: bytesToHex(publicKeyCompressedBytes),
        });
    }

    async function persistIssuedSession(authSession, options) {
        const meta = options || {};
        const auth = authSession || {};
        if (!currentTenantId) {
            return null;
        }

        if (meta.source === "EMAIL_OTP" && meta.keyMaterial) {
            const bootstrapSession = Object.assign({}, meta.keyMaterial, {
                tenantId: currentTenantId,
                accountId: currentAccountId,
                credentialId: String(meta.credentialId || auth.credentialId || ""),
                source: "EMAIL_OTP",
                expiresAt: String(auth.expiresAt || meta.expiresAt || ""),
            });
            writeStoredSessionKey(currentTenantId, bootstrapSession);
            return bootstrapSession;
        }

        if (meta.clientKeyMaterial && typeof auth.encryptedSessionSigningKey === "string" && auth.encryptedSessionSigningKey) {
            const privateKeyBytes = await decryptSessionSigningKey(meta.clientKeyMaterial, auth.encryptedSessionSigningKey);
            const sessionKey = await buildSessionKeyFromPrivateScalar(privateKeyBytes, {
                tenantId: currentTenantId,
                accountId: currentAccountId,
                credentialId: String(meta.credentialId || auth.credentialId || ""),
                source: String(meta.source || auth.type || "PASSKEY"),
                expiresAt: String(auth.expiresAt || meta.expiresAt || ""),
            });
            writeStoredSessionKey(currentTenantId, sessionKey);
            return sessionKey;
        }

        return null;
    }

    function setMessage(target, text, isError) {
        target.textContent = text;
        target.classList.toggle("err", Boolean(isError));
    }

    function setBusy(button, busy) {
        button.disabled = busy;
    }

    function updateRegistrationFlow(flow) {
        registrationFlow = Object.assign({}, registrationFlow, flow || {});

        if (registrationFlowNote) {
            registrationFlowNote.innerHTML = registrationFlow.autoCompletePendingSignature
                ? "Grid passkey registration stays tied to your current Meta Humans session."
                : "Grid passkey registration stays tied to your current Meta Humans session. This page does not create a second Meta Humans login; it only authorizes Grid account actions on this device.";
        }

        if (bootstrapNote) {
            bootstrapNote.innerHTML = registrationFlow.allowSandboxOtpShortcut
                ? "Sandbox override is active. Meta Humans will bypass the hidden relay and inject <span class=\"grid-code\">000000</span> for this debug run only."
                : registrationFlow.environment === "sandbox"
                    ? "The current Grid platform still reports <span class=\"grid-code\">sandbox</span>. Sandbox does not deliver a real EMAIL_OTP into the hidden mailbox, so the relay cannot complete unless you explicitly opt into the debug shortcut."
                : emailOtpAutomationReady
                    ? "Meta Humans sends the Grid OTP to the hidden internal mailbox, retrieves it server-side, and HPKE-encrypts it on-device before the broker verifies the bootstrap."
                    : (emailOtpAutomationMessage || "The hidden internal Grid mailbox is not yet wired for automated retrieval on this tenant.");
        }

        if (pendingCard) {
            pendingCard.classList.toggle("grid-hidden", !registrationFlow.showManualRetryUi);
        }
    }

    function updateBootstrapUi() {
        if (bootstrapOtpLabel) {
            bootstrapOtpLabel.classList.add("grid-hidden");
        }
        if (bootstrapVerifyButton) {
            bootstrapVerifyButton.classList.add("grid-hidden");
        }
        if (bootstrapStartButton) {
            bootstrapStartButton.textContent = "Start EMAIL_OTP Bootstrap";
        }
        updateRegistrationFlow(registrationFlow);
    }

    function updateSessionStatus(session) {
        currentGridSession = session || null;
        if (currentGridSession && currentGridSession.expiresAt) {
            sessionStatusValue.textContent = `Issued until ${currentGridSession.expiresAt}`;
            sessionStatusValue.className = "grid-v ok";
            return;
        }
        sessionStatusValue.textContent = "Not issued for this login";
        sessionStatusValue.className = "grid-v warn";
    }

    function clearClientGridState() {
        currentPasskeyCredentialId = "";
        currentPasskeyPlatformCredentialId = "";
        currentBootstrapCredentialId = "";
        currentBootstrapCredentialType = "";
        currentOauthCredentialId = "";
        emailOtpAutomationReady = false;
        emailOtpAutomationMessage = "";
        currentGridSession = null;
        currentBootstrapState = null;
    }

    function applyUnavailableStatus() {
        clearClientGridState();
        authorizeButton.disabled = true;
        registerButton.disabled = true;
        resetButton.disabled = true;
        bootstrapStartButton.disabled = true;
        bootstrapVerifyButton.disabled = true;
        updateSessionStatus(null);
    }

    function applyStatus(data, options) {
        const opts = options || {};
        updateRegistrationFlow(data.registrationFlow || {});
        currentTenantId = String(data.tenantId || "");
        currentAccountId = String(data.accountId || "");
        currentBootstrapCredentialId = String(data.bootstrapCredentialId || "");
        currentBootstrapCredentialType = String(data.bootstrapCredentialType || "").toUpperCase();
        currentOauthCredentialId = String(data.oauthCredentialId || "");
        currentPasskeyCredentialId = String(data.passkeyCredentialId || "");
        emailOtpAutomationReady = !!data.emailOtpAutomationReady;
        emailOtpAutomationMessage = String(data.emailOtpAutomationMessage || "");
        currentPasskeyPlatformCredentialId = "";
        const passkeyCount = Array.isArray(data.credentials)
            ? data.credentials.filter((credential) => String(credential.type || "").toUpperCase() === "PASSKEY").length
            : Number(data.passkeyCount || 0);
        if (Array.isArray(data.credentials)) {
            const selectedCredential = data.credentials.find((credential) => String(credential.id || "") === currentPasskeyCredentialId)
                || data.credentials.find((credential) => String(credential.type || "").toUpperCase() === "PASSKEY");
            if (selectedCredential) {
                currentPasskeyPlatformCredentialId = String(selectedCredential.platformCredentialId || selectedCredential.credentialId || "");
            }
        }
        tenantValue.textContent = data.tenantId || "-";
        accountValue.textContent = data.accountId || "-";
        passkeyStatusValue.textContent = data.hasPasskey ? `Registered (${passkeyCount})` : "Not yet registered";
        passkeyStatusValue.className = `grid-v ${data.hasPasskey ? "ok" : "warn"}`;
        updateSessionStatus(data.activeSession || null);
        const localSessionKey = readStoredSessionKey(currentTenantId, currentAccountId);
        const canUseSandboxShortcut = !!registrationFlow.autoCompletePendingSignature;
        authorizeButton.disabled = !data.hasPasskey;
        registerButton.disabled = !(localSessionKey || canUseSandboxShortcut);
        resetButton.disabled = !data.hasPasskey || !(localSessionKey || canUseSandboxShortcut);
        bootstrapStartButton.disabled = !data.hasBootstrapCredential || (!emailOtpAutomationReady && !registrationFlow.allowSandboxOtpShortcut);
        bootstrapVerifyButton.disabled = true;
        if (!opts.preserveStatusMessage) {
            const customerStatus = String(data.customerStatus || "").toLowerCase();
            const accountStatus = String(data.accountStatus || "").toLowerCase();
            const statusText = data.hasPasskey
                ? `Grid has ${passkeyCount || 1} registered passkey credential${passkeyCount === 1 ? "" : "s"}. EMAIL_OTP on the internal mail rail remains the supported bootstrap path. The page currently selects ${currentPasskeyCredentialId || "the newest active passkey"} for auth, but that does not prove the browser popup is offering the same device passkey.`
                : (customerStatus === "reprovisioned" || accountStatus === "reprovisioned")
                    ? "This tenant is currently bound to a freshly reprovisioned Grid account. Any passkeys and Grid auth sessions on the older account do not carry over here. Logout also clears the device-local Grid signing key, so bootstrap EMAIL_OTP on this login, then register the passkey again on this new Grid account."
                    : localSessionKey
                        ? "A local Grid signing session is ready on this device. Register the Grid passkey now so future Grid auth stays on the passkey path."
                        : canUseSandboxShortcut
                            ? "Sandbox mode is active. You can register or reset a Grid passkey on this device even without an issued local Grid session."
                            : "No Grid passkey is registered yet. Bootstrap a Grid session on this device first, then register the passkey.";
            setMessage(statusMessage, statusText, false);
        }
        updateBootstrapUi();
        renderCredentials(data.credentials || []);
        renderPending(data.pendingRegistrations || []);
    }

    async function callApi(action, payload) {
        const url = new URL(apiBase, window.location.origin);
        const pageParams = new URLSearchParams(window.location.search);
        url.searchParams.set("action", String(action || ""));
        if (pageDebugEnabled) {
            url.searchParams.set("debug", "1");
        }
        if (pageParams.get("sandbox_otp_shortcut") === "1") {
            url.searchParams.set("sandbox_otp_shortcut", "1");
        }
        const response = await fetch(url.toString(), {
            method: payload ? "POST" : "GET",
            credentials: "include",
            headers: payload ? { "Content-Type": "application/json" } : {},
            body: payload ? JSON.stringify(payload) : undefined,
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            const error = new Error(data.message || data.error || `Request failed (${response.status})`);
            error.status = response.status;
            error.payload = data;
            throw error;
        }
        return data;
    }

    function describeApiError(error) {
        const message = error && error.message ? String(error.message) : "Request failed.";
        const payload = error && error.payload && typeof error.payload === "object" ? error.payload : {};
        const debug = payload.debug && typeof payload.debug === "object" ? payload.debug : null;
        const response = debug && debug.response && typeof debug.response === "object" ? debug.response : null;
        const detail = payload.detail && typeof payload.detail === "object" ? payload.detail : null;
        const extraBits = [];

        if (debug && debug.challengeIssuedAtMs) {
            extraBits.push(`challenge=${new Date(Number(debug.challengeIssuedAtMs)).toISOString()}`);
        }
        if (debug && debug.relayMessageDate) {
            extraBits.push(`relay=${String(debug.relayMessageDate)}`);
        } else if (debug && debug.challengeIssuedAtMs) {
            extraBits.push("relay=missing");
        }
        if (response && typeof response.status === "number" && response.status > 0) {
            extraBits.push(`upstream_http=${response.status}`);
        } else if (detail && typeof detail.status === "number" && detail.status > 0) {
            extraBits.push(`upstream_http=${detail.status}`);
        }
        if (debug && typeof debug.encryptedOtpBundleLength === "number" && debug.encryptedOtpBundleLength > 0) {
            extraBits.push(`bundle_len=${debug.encryptedOtpBundleLength}`);
        }

        return extraBits.length ? `${message} (${extraBits.join(", ")})` : message;
    }

    function renderCredentials(credentials) {
        credentialsBody.innerHTML = "";
        if (!Array.isArray(credentials) || credentials.length === 0) {
            credentialsEmpty.classList.remove("grid-hidden");
            credentialsTable.classList.add("grid-hidden");
            return;
        }

        credentialsEmpty.classList.add("grid-hidden");
        credentialsTable.classList.remove("grid-hidden");
        credentials.forEach((credential) => {
            const tr = document.createElement("tr");
            const type = String(credential.type || "");
            const nickname = String(credential.nickname || "");
            const id = String(credential.id || "");
            const status = String(credential.status || "active");
            const isSelectedPasskey = type.toUpperCase() === "PASSKEY" && id === currentPasskeyCredentialId;
            tr.innerHTML = `
                <td>${type || "unknown"}</td>
                <td>${nickname || "-"}</td>
                <td><span class="grid-code">${id || "-"}</span></td>
                <td>${isSelectedPasskey ? `${status} (selected for auth)` : status}</td>
            `;
            credentialsBody.appendChild(tr);
        });
    }

    function renderPending(pendingRegistrations) {
        if (!registrationFlow.showManualRetryUi) {
            pendingEmpty.classList.add("grid-hidden");
            pendingPanel.classList.add("grid-hidden");
            requestIdInput.value = "";
            signatureInput.value = "";
            return;
        }

        if (!Array.isArray(pendingRegistrations) || pendingRegistrations.length === 0) {
            pendingEmpty.classList.remove("grid-hidden");
            pendingPanel.classList.add("grid-hidden");
            requestIdInput.value = "";
            return;
        }

        const pending = pendingRegistrations[0];
        pendingEmpty.classList.add("grid-hidden");
        pendingPanel.classList.remove("grid-hidden");
        requestIdInput.value = String(pending.requestId || "");
        setMessage(pendingMessage, "A signed retry is pending. Use the sandbox button now or paste a real Grid-Wallet-Signature when the session-signing phase is wired.", false);
    }

    async function loadStatus() {
        try {
            const data = await callApi("status");
            applyStatus(data);
        } catch (error) {
            const payload = error && error.payload ? error.payload : {};
            if (error && error.status === 401 && payload.loginRedirect) {
                window.location.href = payload.loginRedirect;
                return;
            }
            updateRegistrationFlow(payload.registrationFlow || {});
            tenantValue.textContent = payload.tenantId || "-";
            accountValue.textContent = payload.accountId || "-";
            passkeyStatusValue.textContent = "Unavailable";
            passkeyStatusValue.className = "grid-v err";
            applyUnavailableStatus();
            sessionStatusValue.textContent = "Unavailable";
            sessionStatusValue.className = "grid-v err";
            const detailBits = [];
            if (payload.currentUser) detailBits.push(`user=${payload.currentUser}`);
            if (payload.tenantId) detailBits.push(`tenant=${payload.tenantId}`);
            const detail = detailBits.length ? ` (${detailBits.join(", ")})` : "";
            setMessage(statusMessage, (error.message || "Unable to load Grid passkey state.") + detail, true);
            renderCredentials([]);
            renderPending([]);
        }
    }

    async function beginRegistration() {
        if (!window.PublicKeyCredential || !navigator.credentials || typeof navigator.credentials.create !== "function") {
            throw new Error("Passkeys are not supported in this browser.");
        }

        const start = await callApi("start_registration", {});
        const publicKey = structuredClone(start.publicKey);
        publicKey.challenge = base64urlToBytes(publicKey.challenge);
        publicKey.user.id = base64urlToBytes(publicKey.user.id);

        const attestation = await navigator.credentials.create({ publicKey });
        if (!attestation) {
            throw new Error("Authenticator did not return a credential.");
        }

        const response = attestation.response;
        const credentialPayload = {
            id: bytesToBase64url(new Uint8Array(attestation.rawId)),
            response: {
                clientDataJSON: bytesToBase64url(new Uint8Array(response.clientDataJSON)),
                attestationObject: bytesToBase64url(new Uint8Array(response.attestationObject)),
            },
            transports: typeof response.getTransports === "function" ? response.getTransports() : [],
        };

        const init = await callApi("init_registration", {
            challengeId: start.challengeId,
            nickname: nicknameInput.value.trim() || "This device",
            credential: credentialPayload,
        });

        updateRegistrationFlow(init.registrationFlow || {});

        if (init.stage === "registered") {
            setMessage(statusMessage, init.message || "Grid passkey registration completed.", false);
            if (init.status) {
                applyStatus(init.status);
            } else {
                await loadStatus();
            }
            return;
        }

        if (init.stage === "pending_signature") {
            const localSessionKey = readStoredSessionKey(currentTenantId, currentAccountId);
            if (init.requestId && localSessionKey) {
                setMessage(statusMessage, "Signing the Grid registration retry on this device...", false);
                const gridWalletSignature = await buildGridWalletSignature(localSessionKey, init.payloadToSign || "");
                const completed = await callApi("complete_registration", {
                    requestId: init.requestId,
                    gridWalletSignature,
                });
                setMessage(statusMessage, completed.message || "Grid passkey registration completed.", false);
                if (completed.status) {
                    applyStatus(completed.status);
                } else {
                    await loadStatus();
                }
                return;
            }

            requestIdInput.value = init.requestId || "";
            pendingEmpty.classList.add("grid-hidden");
            pendingPanel.classList.remove("grid-hidden");
            setMessage(statusMessage, "Passkey attestation accepted, but this browser does not currently hold a Grid signing key. Bootstrap or re-authorize a Grid session on this device first.", true);
            setMessage(pendingMessage, "The registration is pending completion. Paste a live Grid-Wallet-Signature.", false);
            return;
        }

        setMessage(statusMessage, "Grid passkey registered successfully.", false);
        await loadStatus();
    }

    async function beginAuthSession() {
        if (!window.PublicKeyCredential || !navigator.credentials || typeof navigator.credentials.get !== "function") {
            throw new Error("Passkeys are not supported in this browser.");
        }
        if (!currentPasskeyCredentialId) {
            throw new Error("No Grid passkey is registered yet.");
        }
        if (!currentPasskeyPlatformCredentialId) {
            throw new Error("The Grid passkey metadata for this device is missing. Re-register this passkey on this device.");
        }

        const clientKeyMaterial = await generateP256KeyMaterial();
        const challenge = await callApi("start_auth_session", {
            credentialId: currentPasskeyCredentialId,
            clientPublicKey: clientKeyMaterial.publicKeyHex,
        });

        const assertion = await navigator.credentials.get({
            publicKey: {
                challenge: new TextEncoder().encode(String(challenge.challenge || "")),
                rpId: window.location.hostname.replace(/^www\./, ""),
                userVerification: "required",
                allowCredentials: [{
                    type: "public-key",
                    id: base64urlToBytes(currentPasskeyPlatformCredentialId),
                }],
            },
        });
        if (!assertion) {
            throw new Error("Authenticator did not return an assertion.");
        }

        const response = assertion.response;
        const assertionPayload = {
            credentialId: bytesToBase64url(new Uint8Array(assertion.rawId)),
            clientDataJson: bytesToBase64url(new Uint8Array(response.clientDataJSON)),
            authenticatorData: bytesToBase64url(new Uint8Array(response.authenticatorData)),
            signature: bytesToBase64url(new Uint8Array(response.signature)),
        };
        if (response.userHandle) {
            assertionPayload.userHandle = bytesToBase64url(new Uint8Array(response.userHandle));
        }
        const verify = await callApi("verify_auth_session", {
            requestId: challenge.requestId,
            assertion: assertionPayload,
        });

        if (verify.stage === "pending_signature") {
            const localSessionKey = readStoredSessionKey(currentTenantId, currentAccountId);
            if (!localSessionKey) {
                throw new Error("Grid returned a signed retry for passkey auth, but this device has no active Grid signing key to complete it.");
            }
            const gridWalletSignature = await buildGridWalletSignature(localSessionKey, verify.payloadToSign || "");
            const completed = await callApi("complete_auth_session", {
                requestId: verify.requestId,
                gridWalletSignature,
            });
            await persistIssuedSession(completed.authSession || {}, {
                clientKeyMaterial,
                credentialId: completed.credentialId || currentPasskeyCredentialId,
                source: "PASSKEY",
            });
            setMessage(statusMessage, "Grid auth session issued for this device.", false);
            if (completed.status) {
                applyStatus(completed.status, { preserveStatusMessage: true });
            } else {
                await loadStatus();
            }
            return;
        }

        await persistIssuedSession(verify.authSession || {}, {
            clientKeyMaterial,
            credentialId: verify.credentialId || currentPasskeyCredentialId,
            source: "PASSKEY",
        });
        setMessage(statusMessage, "Grid auth session issued for this device.", false);
        if (verify.status) {
            applyStatus(verify.status, { preserveStatusMessage: true });
        } else {
            await loadStatus();
        }
    }

    async function beginResetPasskey() {
        if (!currentPasskeyCredentialId) {
            throw new Error("No Grid passkey is currently selected for reset.");
        }
        const localSessionKey = readStoredSessionKey(currentTenantId, currentAccountId);
        const confirmed = window.confirm("Reset the currently selected Grid passkey? This revokes the selected PASSKEY credential from Grid so you can register a fresh one.");
        if (!confirmed) {
            return;
        }

        const started = await callApi("start_reset_passkey", {
            credentialId: currentPasskeyCredentialId,
        });
        const payload = { requestId: started.requestId };
        if (localSessionKey) {
            payload.gridWalletSignature = await buildGridWalletSignature(localSessionKey, started.payloadToSign || "");
        } else {
            throw new Error("A local Grid signing session is required before the passkey can be reset.");
        }
        const completed = await callApi("complete_reset_passkey", payload);
        applyStatus(completed.status || {}, { preserveStatusMessage: true });
        setMessage(statusMessage, "Selected Grid passkey revoked. Register a new passkey on this device now.", false);
    }

    function sleep(ms) {
        return new Promise((resolve) => window.setTimeout(resolve, ms));
    }

    async function waitForBootstrapOtp(issuedAfterMs) {
        let attempt = 0;
        let delayMs = 1500;
        while (attempt < 15) {
            attempt += 1;
            const result = await callApi("fetch_bootstrap_otp", {
                issuedAfterMs,
            });
            if (result.found && result.otpCode) {
                if (currentBootstrapState && typeof currentBootstrapState === "object") {
                    currentBootstrapState.relayMessageDate = String(result.messageDate || "");
                }
                return String(result.otpCode);
            }
            delayMs = Number(result.pollAfterMs || delayMs);
            setMessage(bootstrapMessage, `Waiting for the hidden Grid EMAIL_OTP relay... attempt ${attempt}/15.`, false);
            await sleep(Math.max(500, delayMs));
        }
        throw new Error("Grid sent the bootstrap challenge, but the internal EMAIL_OTP relay did not receive a fresh code in time.");
    }

    async function verifyBootstrapSessionWithCode(otpCode) {
        if (!currentBootstrapState || !currentBootstrapState.credentialId || !currentBootstrapState.otpEncryptionTargetBundle) {
            throw new Error("Start the Grid bootstrap challenge first.");
        }
        const normalizedOtpCode = String(otpCode || "").trim();
        if (!normalizedOtpCode) {
            throw new Error("The Grid OTP relay did not return a usable code.");
        }

        if (bootstrapOtpInput) {
            bootstrapOtpInput.value = normalizedOtpCode;
        }

        const encryptedOtpBundle = await buildEncryptedOtpBundle(
            currentBootstrapState.verifiedTargetBundle,
            currentBootstrapState.keyMaterial,
            normalizedOtpCode,
        );

        const verify = await callApi("verify_bootstrap_session", {
            credentialId: currentBootstrapState.credentialId,
            encryptedOtpBundle,
            clientPublicKey: String(currentBootstrapState.keyMaterial && currentBootstrapState.keyMaterial.publicKeyHex || ""),
            challengeIssuedAtMs: Number(currentBootstrapState.issuedAfterMs || 0),
            relayMessageDate: String(currentBootstrapState.relayMessageDate || ""),
        });

        if (verify.stage === "pending_signature") {
            const gridWalletSignature = await buildGridWalletSignature(currentBootstrapState.keyMaterial, verify.payloadToSign || "");
            const completed = await callApi("complete_bootstrap_session", {
                requestId: verify.requestId,
                gridWalletSignature,
            });
            await persistIssuedSession(completed.authSession || {}, {
                keyMaterial: currentBootstrapState.keyMaterial,
                credentialId: completed.credentialId || currentBootstrapState.credentialId,
                source: "EMAIL_OTP",
            });
            currentBootstrapState = null;
            bootstrapOtpInput.value = "";
            setMessage(bootstrapMessage, "Grid bootstrap session issued on this device.", false);
            if (completed.status) {
                applyStatus(completed.status, { preserveStatusMessage: true });
            } else {
                await loadStatus();
            }
            return;
        }

        await persistIssuedSession(verify.authSession || {}, {
            keyMaterial: currentBootstrapState.keyMaterial,
            credentialId: verify.credentialId || currentBootstrapState.credentialId,
            source: "EMAIL_OTP",
        });
        currentBootstrapState = null;
        bootstrapOtpInput.value = "";
        setMessage(bootstrapMessage, "Grid bootstrap session issued on this device.", false);
        if (verify.status) {
            applyStatus(verify.status, { preserveStatusMessage: true });
        } else {
            await loadStatus();
        }
    }

    async function startBootstrapSession() {
        if (!currentBootstrapCredentialId) {
            throw new Error("No Grid bootstrap credential is available for this Global Account.");
        }
        if (registrationFlow.environment === "sandbox" && !registrationFlow.allowSandboxOtpShortcut) {
            throw new Error("The current Grid platform is still sandbox. Sandbox does not send a real EMAIL_OTP to the hidden mailbox, so this relay path cannot complete here. Use ?debug=1&sandbox_otp_shortcut=1 for a sandbox-only verification run, or move this tenant onto a production Grid environment for real mailbox delivery.");
        }
        if (!emailOtpAutomationReady && !registrationFlow.allowSandboxOtpShortcut) {
            throw new Error(emailOtpAutomationMessage || "The internal Grid EMAIL_OTP relay is not ready for this tenant.");
        }

        const challenge = await callApi("start_bootstrap_session", {
            credentialId: currentBootstrapCredentialId,
        });
        const trustedEnclaveQuorumPublicKeys = normalizeTrustedQuorumKeys(challenge.trustedEnclaveQuorumPublicKeys || []);
        currentBootstrapState = {
            credentialId: String(challenge.credentialId || currentBootstrapCredentialId),
            issuedAfterMs: Number(challenge.challengeIssuedAtMs || 0) > 0
                ? Number(challenge.challengeIssuedAtMs)
                : Date.now(),
            otpEncryptionTargetBundle: String(challenge.otpEncryptionTargetBundle || ""),
            trustedEnclaveQuorumPublicKeys,
            verifiedTargetBundle: await verifyOtpEncryptionTargetBundle(
                challenge.otpEncryptionTargetBundle || "",
                trustedEnclaveQuorumPublicKeys,
            ),
            keyMaterial: await generateP256KeyMaterial(),
        };

        if (registrationFlow.allowSandboxOtpShortcut && bootstrapOtpInput) {
            bootstrapOtpInput.value = "000000";
        }

        setMessage(bootstrapMessage, registrationFlow.allowSandboxOtpShortcut
            ? "Grid sent the sandbox EMAIL_OTP challenge. Finalizing the explicit debug override on this device now."
            : "Grid sent a fresh EMAIL_OTP challenge to the hidden internal mailbox. Waiting for the Meta Humans relay now.", false);
        const otpCode = registrationFlow.allowSandboxOtpShortcut
            ? "000000"
            : await waitForBootstrapOtp(currentBootstrapState.issuedAfterMs);
        await verifyBootstrapSessionWithCode(otpCode);
    }

    async function verifyBootstrapSession() {
        throw new Error("Grid bootstrap is automated on this page. Start the EMAIL_OTP bootstrap instead of entering a code manually.");
    }

    async function completeRegistration() {
        const requestId = requestIdInput.value.trim();
        const payload = {
            requestId,
        };
        payload.gridWalletSignature = signatureInput.value.trim();

        const result = await callApi("complete_registration", payload);
        setMessage(statusMessage, "Grid passkey registration completed.", false);
        setMessage(pendingMessage, "Registration completed.", false);
        signatureInput.value = "";
        if (result.status) {
            applyStatus(result.status);
        } else {
            await loadStatus();
        }
    }

    refreshButton.addEventListener("click", async function () {
        setBusy(refreshButton, true);
        try {
            await loadStatus();
        } finally {
            setBusy(refreshButton, false);
        }
    });

    registerButton.addEventListener("click", async function () {
        setBusy(registerButton, true);
        setMessage(statusMessage, "Starting passkey registration...", false);
        try {
            await beginRegistration();
        } catch (error) {
            setMessage(statusMessage, error.message || "Passkey registration failed.", true);
        } finally {
            setBusy(registerButton, false);
        }
    });

    bootstrapStartButton.addEventListener("click", async function () {
        setBusy(bootstrapStartButton, true);
        setMessage(bootstrapMessage, "Requesting a fresh Grid EMAIL_OTP challenge...", false);
        try {
            await startBootstrapSession();
        } catch (error) {
            setMessage(bootstrapMessage, await describeBootstrapFailure(error), true);
        } finally {
            setBusy(bootstrapStartButton, false);
        }
    });

    async function fetchLastVerifyFailure() {
        if (!pageDebugEnabled) {
            return null;
        }
        try {
            const result = await callApi("debug_take", { key: "verify_bootstrap_failure" });
            return result && result.payload ? result.payload : null;
        } catch {
            return null;
        }
    }

    async function fetchLastBootstrapRelayTrace() {
        if (!pageDebugEnabled) {
            return null;
        }
        try {
            const result = await callApi("debug_take", { key: "fetch_bootstrap_otp" });
            return result && result.payload ? result.payload : null;
        } catch {
            return null;
        }
    }

    function summarizeRelayTrace(debugRecord) {
        const payload = debugRecord && debugRecord.payload && typeof debugRecord.payload === "object"
            ? debugRecord.payload
            : null;
        const result = payload && payload.result && typeof payload.result === "object"
            ? payload.result
            : null;
        const trace = result && result.trace && typeof result.trace === "object"
            ? result.trace
            : null;
        const messages = trace && Array.isArray(trace.messages) ? trace.messages : [];
        if (!trace) {
            return "";
        }
        const compact = messages.slice(0, 3).map(function (entry) {
            const parts = [];
            if (entry && entry.date) {
                parts.push(String(entry.date));
            }
            if (entry && entry.subject) {
                parts.push(`subject=${String(entry.subject)}`);
            }
            if (entry && entry.unrelated) {
                parts.push("unrelated=yes");
            }
            if (entry && entry.too_old) {
                parts.push("too_old=yes");
            }
            if (entry && entry.otp_found) {
                parts.push("otp=yes");
            }
            return parts.join("|");
        }).filter(Boolean);
        const prefix = trace.search_since ? `search_since=${String(trace.search_since)}` : "search_since=unknown";
        return compact.length > 0 ? `${prefix}; scanned=${compact.join(" ; ")}` : prefix;
    }

    async function describeBootstrapFailure(error) {
        const base = describeApiError(error) || "Unable to complete the Grid bootstrap session.";
        const verifyDebug = await fetchLastVerifyFailure();
        const relayDebug = await fetchLastBootstrapRelayTrace();
        const upstreamBody = verifyDebug
            && verifyDebug.payload
            && verifyDebug.payload.response
            && typeof verifyDebug.payload.response.body_raw === "string"
            ? verifyDebug.payload.response.body_raw.trim()
            : "";
        const relaySummary = summarizeRelayTrace(relayDebug);
        let message = upstreamBody ? `${base} :: ${upstreamBody}` : base;
        if (relaySummary) {
            message += ` :: relay_trace=${relaySummary}`;
        }
        return message;
    }

    bootstrapVerifyButton.addEventListener("click", async function () {
        setBusy(bootstrapVerifyButton, true);
        setMessage(bootstrapMessage, "Encrypting the OTP and minting the Grid session...", false);
        try {
            await verifyBootstrapSession();
        } catch (error) {
            setMessage(bootstrapMessage, await describeBootstrapFailure(error), true);
        } finally {
            setBusy(bootstrapVerifyButton, false);
        }
    });

    authorizeButton.addEventListener("click", async function () {
        setBusy(authorizeButton, true);
        setMessage(statusMessage, "Authorizing Grid session...", false);
        try {
            await beginAuthSession();
        } catch (error) {
            setMessage(statusMessage, error.message || "Unable to authorize Grid session.", true);
        } finally {
            setBusy(authorizeButton, false);
        }
    });

    resetButton.addEventListener("click", async function () {
        setBusy(resetButton, true);
        setMessage(statusMessage, "Resetting the selected Grid passkey...", false);
        try {
            await beginResetPasskey();
        } catch (error) {
            setMessage(statusMessage, error.message || "Unable to reset the selected Grid passkey.", true);
        } finally {
            setBusy(resetButton, false);
        }
    });

    completeButton.addEventListener("click", async function () {
        setBusy(completeButton, true);
        try {
            await completeRegistration(false);
        } catch (error) {
            setMessage(pendingMessage, error.message || "Unable to complete registration.", true);
        } finally {
            setBusy(completeButton, false);
        }
    });

    loadStatus();
})();
</script>
</body>
</html>

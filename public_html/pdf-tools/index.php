<?php
$requestUri = $_SERVER['REQUEST_URI'] ?? '/pdf-tools/';

$pt = $_SERVER['PDFTOOLS_PATH'] ?? ($_SERVER['REDIRECT_PDFTOOLS_PATH'] ?? null);
if (is_string($pt) && $pt !== '') {
    $pt = ltrim($pt, '/');
    $requestUri = '/pdf-tools/' . $pt;
    if (isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
        $requestUri .= '?' . $_SERVER['QUERY_STRING'];
    }
}

if (isset($_SERVER['SCRIPT_NAME']) && is_string($_SERVER['SCRIPT_NAME']) && str_ends_with($_SERVER['SCRIPT_NAME'], '/pdf-tools/index.php')) {
    if (is_string($requestUri) && $requestUri !== '' && strncmp($requestUri, '/pdf-tools', 10) !== 0 && strncmp($requestUri, '/auth/', 6) !== 0) {
        $requestUri = '/pdf-tools' . (str_starts_with($requestUri, '/') ? $requestUri : '/' . $requestUri);
        if (isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
            $requestUri .= '?' . $_SERVER['QUERY_STRING'];
        }
    }
}

if (isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) && str_contains($_SERVER['HTTP_REFERER'], '/pdf-tools')) {
    if (is_string($requestUri) && $requestUri !== '' && strncmp($requestUri, '/pdf-tools', 10) !== 0 && strncmp($requestUri, '/auth/', 6) !== 0) {
        $requestUri = '/pdf-tools' . (str_starts_with($requestUri, '/') ? $requestUri : '/' . $requestUri);
        if (isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
            $requestUri .= '?' . $_SERVER['QUERY_STRING'];
        }
    }
}

if (isset($_SERVER['REDIRECT_URL']) && is_string($_SERVER['REDIRECT_URL']) && $_SERVER['REDIRECT_URL'] !== '') {
    $ru = $_SERVER['REDIRECT_URL'];
    if (strncmp($ru, '/pdf-tools/', 11) === 0 || $ru === '/pdf-tools') {
        $requestUri = $ru;
        if (isset($_SERVER['QUERY_STRING']) && is_string($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '') {
            $requestUri .= '?' . $_SERVER['QUERY_STRING'];
        }
    }
}

$baseDir = '/home/onemeta/.data/pdf-stack/bentopdf/www';
$baseReal = realpath($baseDir);
if (!$baseReal) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PDF tools unavailable';
    exit;
}

$path = parse_url($requestUri, PHP_URL_PATH);
if (!is_string($path)) {
    $path = '/pdf-tools/';
}

$prefix = '/pdf-tools';
if (strncmp($path, $prefix, strlen($prefix)) !== 0) {
    $path = $prefix . '/';
}

$relative = ltrim(substr($path, strlen($prefix)), '/');
if ($relative === '') {
    $relative = 'index.html';
}

if (str_contains($relative, "\0") || preg_match('~(^|/)\\.\\.(?:/|$)~', $relative) === 1) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bad request';
    exit;
}

if (str_ends_with($path, '/') && !str_ends_with($relative, 'index.html')) {
    $relative = rtrim($relative, '/') . '/index.html';
}

$candidates = [$relative];
if (strpos($relative, '.') === false) {
    $candidates[] = rtrim($relative, '/') . '/index.html';
    $candidates[] = $relative . '.html';
}

$selected = null;
foreach ($candidates as $candidate) {
    $candidatePath = $baseReal . '/' . $candidate;
    $real = realpath($candidatePath);
    if ($real && strncmp($real, $baseReal . '/', strlen($baseReal) + 1) === 0 && is_file($real)) {
        $selected = $real;
        break;
    }
}

if (!$selected) {
    $fallback = realpath($baseReal . '/index.html');
    if ($fallback && is_file($fallback)) {
        $selected = $fallback;
    } else {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
}

$selectedExt = strtolower(pathinfo($selected, PATHINFO_EXTENSION));
$requiresAuth = $selectedExt === 'html';

if ($requiresAuth) {
    require_once dirname(__DIR__) . '/.cue/cue.php';

    if (function_exists('cue_autoload')) {
        try {
            cue_autoload('security');
        } catch (Throwable $e) {
        }
    }

    if (function_exists('security_startSecureSession')) {
        security_startSecureSession();
    } elseif (function_exists('startSecureSession')) {
        startSecureSession();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $authUser = $_SESSION['mh_auth_user'] ?? '';
    $tenantId = $_SESSION['mh_tenant_id'] ?? '';
    $personaId = $_SESSION['mh_auth_persona'] ?? '';
    $userId = $_SESSION['mh_user_internal_id'] ?? '';
    $hasUserInternalId = (is_int($userId) && $userId > 0) || (is_string($userId) && ctype_digit($userId) && (int)$userId > 0);

    if (!is_string($authUser) || $authUser === '') {
        $ssoHandler = dirname(__DIR__) . '/auth/lemonldap-handler.php';
        if (is_file($ssoHandler)) {
            require_once $ssoHandler;
            if (function_exists('lemonldap_process_headers')) {
                $ssoData = lemonldap_process_headers();
                if (is_array($ssoData) && isset($ssoData['username']) && is_string($ssoData['username']) && $ssoData['username'] !== '') {
                    $_SESSION['mh_auth_user'] = $ssoData['username'];
                    $_SESSION['mh_auth_method'] = 'sso_lemonldap';
                    if (!empty($ssoData['groups'])) {
                        $_SESSION['mh_auth_groups'] = $ssoData['groups'];
                    }
                    $authUser = $ssoData['username'];
                }
            }
        }
    }

    if (!is_string($authUser) || $authUser === '') {
        header('Location: /auth/login.php?redirect=' . urlencode($requestUri), true, 302);
        exit;
    }

    if (!is_string($tenantId) || $tenantId === '' || !is_string($personaId) || $personaId === '' || !$hasUserInternalId) {
        $authFunctions = dirname(__DIR__) . '/auth/auth_functions.php';
        if (is_file($authFunctions)) {
            require_once $authFunctions;
            if (function_exists('mh_auth_load_user_context')) {
                mh_auth_load_user_context($authUser, $_SESSION['mh_auth_groups'] ?? null);
            }
        }

        if (!isset($_SESSION['mh_auth_persona']) || !is_string($_SESSION['mh_auth_persona']) || $_SESSION['mh_auth_persona'] === '') {
            $_SESSION['mh_auth_persona'] = 'MH-' . $authUser;
        }

        $tenantId = $_SESSION['mh_tenant_id'] ?? '';
        $personaId = $_SESSION['mh_auth_persona'] ?? '';
        $userId = $_SESSION['mh_user_internal_id'] ?? '';
        $hasUserInternalId = (is_int($userId) && $userId > 0) || (is_string($userId) && ctype_digit($userId) && (int)$userId > 0);

        if (!is_string($tenantId) || $tenantId === '' || !is_string($personaId) || $personaId === '' || !$hasUserInternalId) {
            unset($_SESSION['mh_auth_user'], $_SESSION['mh_auth_persona'], $_SESSION['mh_auth_role'], $_SESSION['mh_auth_permissions']);
            header('Location: /auth/login.php?redirect=' . urlencode($requestUri), true, 302);
            exit;
        }
    }

    $tokens = (int)($_SESSION['tokens'] ?? 0);
    $requestedRelative = $relative;
    if ($tokens <= 0 && $requestedRelative !== 'index.html') {
        header('Location: /hub/genesis/tokenization.php', true, 302);
        exit;
    }
}

$ext = $selectedExt;
$contentTypes = [
    'html' => 'text/html; charset=utf-8',
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'map' => 'application/json; charset=utf-8',
    'webmanifest' => 'application/manifest+json; charset=utf-8',
    'wasm' => 'application/wasm',
    'svg' => 'image/svg+xml',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'mjs' => 'application/javascript; charset=utf-8',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
    'woff' => 'font/woff',
    'ttf' => 'font/ttf',
    'otf' => 'font/otf',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/markdown; charset=utf-8',
    'xml' => 'application/xml; charset=utf-8',
];

header('Content-Type: ' . ($contentTypes[$ext] ?? 'application/octet-stream'));

if ($ext === 'html') {
    $raw = file_get_contents($selected);
    if (!is_string($raw) || $raw === '') {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PDF tools unavailable';
        exit;
    }

    if (!defined('CUE_DATABASE_EMERGENCY_DISABLED')) {
        define('CUE_DATABASE_EMERGENCY_DISABLED', true);
    }

    $title = 'PDF Tools';
    if (preg_match('~<title>(.*?)</title>~is', $raw, $m) === 1) {
        $t = trim((string)$m[1]);
        if ($t !== '') {
            $title = preg_replace('/\\s+/', ' ', $t);
        }
    }

    $bodyAttrs = '';
    if (preg_match('~<body\\b([^>]*)>~i', $raw, $m) === 1) {
        $bodyAttrs = (string)$m[1];
    }

    $bodyInner = '';
    if (preg_match('~<body\\b[^>]*>(.*)</body>~is', $raw, $m) === 1) {
        $bodyInner = (string)$m[1];
    }

    $rewritePath = static function (string $p): string {
        $p = trim($p);
        if (str_starts_with($p, '/assets/')) {
            return '/pdf-tools' . $p;
        }
        if (str_starts_with($p, '/images/')) {
            return '/pdf-tools' . $p;
        }
        if ($p === '/favicon.ico') {
            return '/pdf-tools/favicon.ico';
        }
        if ($p === '/site.webmanifest') {
            return '/pdf-tools/site.webmanifest';
        }
        if ($p === '/manifest.json') {
            return '/pdf-tools/manifest.json';
        }
        return $p;
    };

    $moduleScripts = [];
    if (preg_match_all('~<script\\b[^>]*type=[\"\\\']module[\"\\\'][^>]*src=[\"\\\']([^\"\\\']+)[\"\\\'][^>]*>\\s*</script>~i', $raw, $mm) > 0) {
        foreach ($mm[1] as $src) {
            if (is_string($src) && $src !== '') {
                $moduleScripts[] = $rewritePath($src);
            }
        }
    }

    $modulePreloads = [];
    if (preg_match_all('~<link\\b[^>]*rel=[\"\\\']modulepreload[\"\\\'][^>]*href=[\"\\\']([^\"\\\']+)[\"\\\'][^>]*>~i', $raw, $mm) > 0) {
        foreach ($mm[1] as $href) {
            if (is_string($href) && $href !== '') {
                $modulePreloads[] = $rewritePath($href);
            }
        }
    }

    $stylesheets = [];
    if (preg_match_all('~<link\\b[^>]*rel=[\"\\\']stylesheet[\"\\\'][^>]*href=[\"\\\']([^\"\\\']+)[\"\\\'][^>]*>~i', $raw, $mm) > 0) {
        foreach ($mm[1] as $href) {
            if (is_string($href) && $href !== '') {
                $stylesheets[] = $rewritePath($href);
            }
        }
    }

    $manifestHref = null;
    if (preg_match('~<link\\b[^>]*rel=[\"\\\']manifest[\"\\\'][^>]*href=[\"\\\']([^\"\\\']+)[\"\\\'][^>]*>~i', $raw, $m) === 1) {
        $manifestHref = $rewritePath((string)$m[1]);
    }

    $bodyInner = str_replace(
        ['href="/assets/', 'src="/assets/', 'href="/images/', 'src="/images/'],
        ['href="/pdf-tools/assets/', 'src="/pdf-tools/assets/', 'href="/pdf-tools/images/', 'src="/pdf-tools/images/'],
        $bodyInner
    );

    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    $hbTriggerSize = 40;
    $hbTriggerAlign = 'top';
    $hbTriggerOffset = 20;
    $hbPosition = 'left';
    $hbBg = 'rgba(0, 0, 0, 0.4)';
    $hbIcon = '#00ffff';
    $hbBarWidth = 25;
    $hbBarHeight = 3;
    $hbBarGap = 4;
    $hbBorderRadius = 10;

    try {
        $cfgPath = function_exists('getDataPath') ? (getDataPath() . '/global-ui/hamburger/hamburger-config.json') : '/home/onemeta/data/global-ui/hamburger/hamburger-config.json';
        if (is_string($cfgPath) && is_file($cfgPath)) {
            $saved = json_decode((string)file_get_contents($cfgPath), true);
            if (is_array($saved) && isset($saved['K::HamburgerUI::Configuration']) && is_array($saved['K::HamburgerUI::Configuration'])) {
                $keys = array_keys($saved['K::HamburgerUI::Configuration']);
                $cfg = (!empty($keys) && is_array($saved['K::HamburgerUI::Configuration'][$keys[0]] ?? null)) ? $saved['K::HamburgerUI::Configuration'][$keys[0]] : null;
                if (is_array($cfg)) {
                    $hbTriggerSize = (int)($cfg['hbg_trigger_size'] ?? $hbTriggerSize);
                    $hbTriggerAlign = (string)($cfg['hbg_trigger_vertical_align'] ?? $hbTriggerAlign);
                    $hbTriggerOffset = (int)($cfg['hbg_trigger_offset'] ?? $hbTriggerOffset);
                    $hbPosition = (string)($cfg['hbg_position'] ?? $hbPosition);
                    $hbBg = (string)($cfg['hbg_background_color'] ?? $hbBg);
                    $hbIcon = (string)($cfg['hbg_icon_color'] ?? $hbIcon);
                    $hbBarWidth = (int)($cfg['hbg_bar_width'] ?? $hbBarWidth);
                    $hbBarHeight = (int)($cfg['hbg_bar_height'] ?? $hbBarHeight);
                    $hbBarGap = (int)($cfg['hbg_bar_gap'] ?? $hbBarGap);
                }
            }
        }
    } catch (Throwable) {
    }

    $hbTriggerSize = max(24, $hbTriggerSize);
    $hbTriggerOffset = max(0, $hbTriggerOffset);
    $hbBarHeight = max(2, $hbBarHeight);
    $hbBarGap = max(0, $hbBarGap);
    $hbTriggerSizeEff = max($hbTriggerSize, $hbBarWidth + 10);
    $hbBarWidthEff = max(6, min($hbBarWidth, $hbTriggerSizeEff - 10));
    $hbShadowOffset = $hbBarGap + $hbBarHeight;
    $hbSideCss = ($hbPosition === 'right')
        ? 'right: 20px !important; left: auto !important;'
        : 'left: 20px !important; right: auto !important;';
    $hbLeft = ($hbPosition === 'right') ? 'auto' : '20px';
    $hbRight = ($hbPosition === 'right') ? '20px' : 'auto';
    if ($hbTriggerAlign === 'bottom') {
        $hbAlignCss = 'bottom: ' . (int)$hbTriggerOffset . 'px !important; top: auto !important; transform: none !important;';
        $hbTop = 'auto';
        $hbBottom = (int)$hbTriggerOffset . 'px';
        $hbTransform = 'none';
    } elseif ($hbTriggerAlign === 'center') {
        $hbAlignCss = 'top: 50% !important; bottom: auto !important; transform: translateY(-50%) !important;';
        $hbTop = '50%';
        $hbBottom = 'auto';
        $hbTransform = 'translateY(-50%)';
    } else {
        $hbAlignCss = 'top: ' . (int)$hbTriggerOffset . 'px !important; bottom: auto !important; transform: none !important;';
        $hbTop = (int)$hbTriggerOffset . 'px';
        $hbBottom = 'auto';
        $hbTransform = 'none';
    }

    ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <base href="/pdf-tools/" />
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <style>
      html, body { height: auto !important; overflow-y: auto !important; }
      main.main-content { overflow: visible !important; padding-bottom: 0 !important; }
      #app, #uploader, #tool-interface { padding-bottom: 0 !important; }
    </style>
    <?php if (is_string($manifestHref) && $manifestHref !== '') { ?>
    <link rel="manifest" href="<?php echo htmlspecialchars($manifestHref, ENT_QUOTES, 'UTF-8'); ?>" />
    <?php } ?>
    <?php foreach (array_values(array_unique($modulePreloads)) as $href) { ?>
    <link rel="modulepreload" crossorigin href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" />
    <?php } ?>
    <?php foreach (array_values(array_unique($stylesheets)) as $href) { ?>
    <link rel="stylesheet" crossorigin href="<?php echo htmlspecialchars($href, ENT_QUOTES, 'UTF-8'); ?>" />
    <?php } ?>
    <script>
      (function () {
        try {
          var s = document.documentElement.style;
          s.setProperty("--pt-hb-trigger-size", <?php echo json_encode((int)$hbTriggerSizeEff . 'px'); ?>);
          s.setProperty("--pt-hb-bg", <?php echo json_encode((string)$hbBg); ?>);
          s.setProperty("--pt-hb-icon", <?php echo json_encode((string)$hbIcon); ?>);
          s.setProperty("--pt-hb-radius", <?php echo json_encode((int)$hbBorderRadius . 'px'); ?>);
          s.setProperty("--pt-hb-bar-width", <?php echo json_encode((int)$hbBarWidthEff . 'px'); ?>);
          s.setProperty("--pt-hb-bar-height", <?php echo json_encode((int)$hbBarHeight . 'px'); ?>);
          s.setProperty("--pt-hb-bar-shadow", <?php echo json_encode('0 -' . (int)$hbShadowOffset . 'px 0 ' . (string)$hbIcon . ', 0 ' . (int)$hbShadowOffset . 'px 0 ' . (string)$hbIcon); ?>);
          s.setProperty("--pt-hb-left", <?php echo json_encode((string)$hbLeft); ?>);
          s.setProperty("--pt-hb-right", <?php echo json_encode((string)$hbRight); ?>);
          s.setProperty("--pt-hb-top", <?php echo json_encode((string)$hbTop); ?>);
          s.setProperty("--pt-hb-bottom", <?php echo json_encode((string)$hbBottom); ?>);
          s.setProperty("--pt-hb-transform", <?php echo json_encode((string)$hbTransform); ?>);
        } catch (e) {}
      })();
    </script>
    <style>
      .cue-hamburger-menu .hamburger-trigger {
        position: fixed !important;
        width: var(--pt-hb-trigger-size) !important;
        height: var(--pt-hb-trigger-size) !important;
        left: var(--pt-hb-left) !important;
        right: var(--pt-hb-right) !important;
        top: var(--pt-hb-top) !important;
        bottom: var(--pt-hb-bottom) !important;
        transform: var(--pt-hb-transform) !important;
        background: var(--pt-hb-bg) !important;
        border: 2px solid var(--pt-hb-icon) !important;
        border-radius: var(--pt-hb-radius) !important;
        padding: 0 !important;
        z-index: 10000 !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 4px !important;
      }
      .cue-hamburger-menu .hamburger-line { display: none !important; }
      .cue-hamburger-menu .hamburger-trigger::before {
        content: "" !important;
        position: absolute !important;
        left: 50% !important;
        top: 50% !important;
        width: var(--pt-hb-bar-width) !important;
        height: var(--pt-hb-bar-height) !important;
        background: var(--pt-hb-icon) !important;
        border-radius: 999px !important;
        transform: translate(-50%, -50%) !important;
        box-shadow: var(--pt-hb-bar-shadow) !important;
        opacity: 1 !important;
        visibility: visible !important;
        pointer-events: none !important;
      }
    </style>
  </head>
  <body<?php echo $bodyAttrs; ?>>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <div id="mh-token-badge" style="position:fixed;top:calc(var(--header-height, 90px) + 10px);right:14px;z-index:1200;background:rgba(2,6,23,0.72);border:1px solid rgba(148,163,184,0.22);backdrop-filter:blur(10px);color:rgba(226,232,240,0.95);padding:8px 10px;border-radius:999px;font-size:13px;line-height:1;display:flex;gap:8px;align-items:center;">
      <span>Tokens</span>
      <strong id="mh-token-count">…</strong>
    </div>
    <main class="main-content">
      <?php echo $bodyInner; ?>
    </main>
    <script>
      (function () {
        var base = "/pdf-tools";
        var tokenBusy = false;
        var lastTokenAt = 0;
        var tokenCount = null;
        var tokenOverlay = null;
        var runByService = {};

        function ensureOverlay() {
          if (tokenOverlay) {
            return tokenOverlay;
          }
          var el = document.createElement("div");
          el.id = "mh-token-overlay";
          el.style.position = "fixed";
          el.style.inset = "0";
          el.style.zIndex = "2000";
          el.style.display = "none";
          el.style.alignItems = "center";
          el.style.justifyContent = "center";
          el.style.background = "rgba(2, 6, 23, 0.72)";
          el.style.backdropFilter = "blur(10px)";
          el.innerHTML =
            '<div style="max-width:520px;width:calc(100% - 32px);background:rgba(15,23,42,0.92);border:1px solid rgba(148,163,184,0.22);border-radius:16px;padding:18px;color:rgba(226,232,240,0.95);">' +
            '<div style="font-weight:700;font-size:18px;margin-bottom:6px;">No tokens available</div>' +
            '<div style="opacity:0.9;margin-bottom:14px;line-height:1.4;">You have 0 tokens, so PDF tools are disabled. Buy tokens to continue.</div>' +
            '<div style="display:flex;gap:10px;flex-wrap:wrap;">' +
            '<a href="/hub/genesis/tokenization.php" style="background:#4f46e5;color:#fff;text-decoration:none;padding:10px 12px;border-radius:10px;font-weight:700;">Buy Tokens</a>' +
            '<a href="/hub/" style="background:rgba(148,163,184,0.12);color:rgba(226,232,240,0.95);text-decoration:none;padding:10px 12px;border-radius:10px;font-weight:700;border:1px solid rgba(148,163,184,0.22);">Go to Hub</a>' +
            "</div>" +
            "</div>";
          document.body.appendChild(el);
          tokenOverlay = el;
          return tokenOverlay;
        }

        function setToolsEnabled(enabled) {
          try {
            var controls = document.querySelectorAll("input, button, select, textarea");
            for (var i = 0; i < controls.length; i++) {
              var c = controls[i];
              if (!c || !c.closest) {
                continue;
              }
              if (c.closest(".cue-global-header") || c.closest(".cue-global-footer")) {
                continue;
              }
              if (enabled) {
                if (c.hasAttribute("data-mh-disabled")) {
                  c.removeAttribute("disabled");
                  c.removeAttribute("data-mh-disabled");
                }
              } else {
                if (!c.disabled) {
                  c.setAttribute("data-mh-disabled", "1");
                  c.setAttribute("disabled", "disabled");
                }
              }
            }
          } catch (e) {}
        }

        function updateTokenBadge(n) {
          try {
            var badge = document.getElementById("mh-token-count");
            if (badge) {
              badge.textContent = String(n);
            }
          } catch (e) {}
        }

        function refreshTokens() {
          try {
            fetch("/pdf-tools/tokens.php", {
              method: "GET",
              headers: { "X-Requested-With": "XMLHttpRequest" },
              credentials: "same-origin"
            })
              .then(function (r) { return r.json ? r.json() : Promise.reject(new Error("nojson")); })
              .then(function (j) {
                if (!j || j.success !== true) {
                  return;
                }
                tokenCount = Number(j.tokens);
                if (!Number.isFinite(tokenCount)) {
                  return;
                }
                updateTokenBadge(tokenCount);
                if (tokenCount <= 0) {
                  ensureOverlay().style.display = "flex";
                  setToolsEnabled(false);
                } else {
                  ensureOverlay().style.display = "none";
                  setToolsEnabled(true);
                }
              })
              .catch(function () {});
          } catch (e) {}
        }

        function normalizeServiceName(raw) {
          try {
            var s = String(raw || "unknown");
            if (s.indexOf(base + "/") === 0) {
              s = s.slice((base + "/").length);
            }
            if (s.indexOf("/") === 0) {
              s = s.slice(1);
            }
            s = s.replace(/\\.html$/i, "");
            var parts = s.split("/");
            if (parts.length > 1 && /^[a-z]{2}(?:-[A-Z]{2})?$/.test(parts[0])) {
              parts.shift();
              s = parts.join("/");
            }
            if (s === "" || s === "/") {
              s = "index";
            }
            return s;
          } catch (e) {
            return "unknown";
          }
        }

        function currentServiceName() {
          try {
            var p = (window.location && window.location.pathname) ? String(window.location.pathname) : "";
            return normalizeServiceName(p);
          } catch (e) {
            return "unknown";
          }
        }

        function estimateFileCount() {
          try {
            var inputs = document.querySelectorAll('input[type="file"]');
            var max = 0;
            for (var i = 0; i < inputs.length; i++) {
              var f = inputs[i];
              if (f && f.files && typeof f.files.length === "number") {
                if (f.files.length > max) {
                  max = f.files.length;
                }
              }
            }
            return max > 0 ? max : 1;
          } catch (e) {
            return 1;
          }
        }

        function startRun(service) {
          try {
            service = normalizeServiceName(service);
            var now = Date.now();
            runByService[service] = {
              id: now,
              charged: false,
              charging: false,
              files: estimateFileCount()
            };
          } catch (e) {}
        }

        function shouldUseDocumentsCost(service) {
          try {
            service = normalizeServiceName(service);
            return service === "sign" || service.indexOf("sign") !== -1;
          } catch (e) {
            return false;
          }
        }

        function debitIfNeeded(service) {
          service = normalizeServiceName(service);
          if (!runByService[service]) {
            startRun(service);
          }
          var run = runByService[service];
          if (!run || run.charged || run.charging) {
            return;
          }
          if (tokenCount !== null && Number.isFinite(tokenCount) && tokenCount <= 0) {
            ensureOverlay().style.display = "flex";
            setToolsEnabled(false);
            return;
          }
          var now = Date.now();
          if (tokenBusy || (now - lastTokenAt) < 2500) {
            return;
          }
          run.charging = true;
          tokenBusy = true;
          lastTokenAt = now;
          var docs = 1;
          if (shouldUseDocumentsCost(service)) {
            docs = Number.isFinite(run.files) ? run.files : estimateFileCount();
          }
          fetch("/pdf-tools/debit.php", {
            method: "POST",
            headers: {
              "Content-Type": "application/json",
              "X-Requested-With": "XMLHttpRequest"
            },
            credentials: "same-origin",
            body: JSON.stringify({
              service: service,
              documents: docs
            })
          })
            .then(function (r) {
              if (r.status === 402) {
                ensureOverlay().style.display = "flex";
                setToolsEnabled(false);
                return null;
              }
              return r.json ? r.json() : null;
            })
            .then(function (j) {
              if (j && j.success === true) {
                run.charged = true;
                if (Number.isFinite(Number(j.tokens))) {
                  tokenCount = Number(j.tokens);
                  updateTokenBadge(tokenCount);
                } else {
                  refreshTokens();
                }
              } else {
                refreshTokens();
              }
            })
            .catch(function () {})
            .finally(function () {
              run.charging = false;
              tokenBusy = false;
            });
        }

        try {
          var origAnchorClick = HTMLAnchorElement.prototype.click;
          HTMLAnchorElement.prototype.click = function () {
            try {
              var href = this && this.getAttribute ? (this.getAttribute("href") || "") : "";
              var dl = this && this.getAttribute ? (this.getAttribute("download") || "") : "";
              var looksLikeDownload = dl !== "" || (this && this.download);
              if (looksLikeDownload && typeof href === "string" && (href.indexOf("blob:") === 0 || href.indexOf("data:") === 0)) {
                var name = String(dl || this.download || "");
                var lower = name.toLowerCase();
                if (lower.endsWith(".pdf") || lower.endsWith(".zip")) {
                  debitIfNeeded(currentServiceName());
                }
              }
            } catch (e) {}
            return origAnchorClick.call(this);
          };
        } catch (e) {}

        try {
          if (typeof history !== "undefined") {
            var origPush = history.pushState;
            var origReplace = history.replaceState;
            history.pushState = function (state, title, url) {
              try {
                if (typeof url === "string" && url.charAt(0) === "/" && url.indexOf(base + "/") !== 0) {
                  url = base + url;
                }
              } catch (e) {}
              return origPush.call(this, state, title, url);
            };
            history.replaceState = function (state, title, url) {
              try {
                if (typeof url === "string" && url.charAt(0) === "/" && url.indexOf(base + "/") !== 0) {
                  url = base + url;
                }
              } catch (e) {}
              return origReplace.call(this, state, title, url);
            };
          }
        } catch (e) {}

        try {
          document.addEventListener(
            "click",
            function (ev) {
              var t = ev.target;
              if (!t || !t.closest) {
                return;
              }
              try {
                var dl = t.closest('#preview-download-btn, #download-btn, [data-action="download"], a[download], button[id*="download"], a[id*="download"]');
                if (dl) {
                  debitIfNeeded(currentServiceName());
                }
              } catch (e) {}

              try {
                var runBtn = t.closest("#process-btn, #processBtn, #convert-btn, #run-btn, #start-btn, #apply-btn");
                if (runBtn) {
                  if (tokenCount !== null && Number.isFinite(tokenCount) && tokenCount <= 0) {
                    ensureOverlay().style.display = "flex";
                    setToolsEnabled(false);
                    ev.preventDefault();
                    ev.stopPropagation();
                    if (ev.stopImmediatePropagation) {
                      ev.stopImmediatePropagation();
                    }
                    return;
                  }
                  startRun(currentServiceName());
                }
              } catch (e) {}

              var el = t.closest("#back-to-tools, #back-to-grid");
              if (!el) {
                return;
              }
              try {
                ev.preventDefault();
                ev.stopPropagation();
                if (ev.stopImmediatePropagation) {
                  ev.stopImmediatePropagation();
                }
              } catch (e) {}
              window.location.href = base + "/";
            },
            true
          );
        } catch (e) {}

        try {
          if ("serviceWorker" in navigator && navigator.serviceWorker && navigator.serviceWorker.register) {
            var origRegister = navigator.serviceWorker.register.bind(navigator.serviceWorker);
            navigator.serviceWorker.register = function (url, opts) {
              try {
                if (url === "/sw.js") {
                  url = base + "/sw.js";
                }
              } catch (e) {}
              return origRegister(url, opts);
            };
          }
        } catch (e) {}

        try {
          refreshTokens();
          setInterval(refreshTokens, 45000);
        } catch (e) {}
      })();
    </script>
    <?php foreach (array_values(array_unique($moduleScripts)) as $src) { ?>
    <script type="module" crossorigin src="<?php echo htmlspecialchars($src, ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php } ?>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
  </body>
</html>
    <?php
    exit;
} else {
    header('Cache-Control: public, max-age=3600');
}

$size = filesize($selected);
if (is_int($size)) {
    header('Content-Length: ' . $size);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
    exit;
}

readfile($selected);

<?php
/**
 * Complete Global UI Body Start Include
 * Include this at the start of <body> to add header and hamburger menu
 */

// Skip auto-rendering if we're in the global-ui-manager interface
if (basename($_SERVER['PHP_SELF']) === 'global-ui-manager.php') {
    return;
}

require_once dirname(__DIR__) . '/functions.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    @session_start();
}
$username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
if ($username !== '') {
    $authFunctionsPath = dirname(dirname(__DIR__, 2)) . '/auth/auth_functions.php';
    if (!function_exists('mh_refresh_session_token_balance') && is_file($authFunctionsPath)) {
        require_once $authFunctionsPath;
    }
    if (function_exists('mh_refresh_session_token_balance')) {
        mh_refresh_session_token_balance($username, 30);
    }
}

$isDirect = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__);
if ($isDirect) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Global UI Preview</title>';
    include_once __DIR__ . '/complete-head.php';
    echo '</head><body>';
}

renderGlobalHeader();
renderGlobalHamburgerMenu();

$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if (strpos($uri, '/pdf-tools') !== 0 && strpos($uri, '/auth/') !== 0) {
    renderGlobalWidgets();
}

$tokenChargeFlash = isset($_SESSION['mh_token_charge_flash']) && is_string($_SESSION['mh_token_charge_flash']) ? trim((string)$_SESSION['mh_token_charge_flash']) : '';
if ($tokenChargeFlash !== '') {
    unset($_SESSION['mh_token_charge_flash']);
    ?>
    <div style="max-width: 1200px; margin: 14px auto 0; padding: 0 20px;">
        <div class="alert" style="background: rgba(255, 80, 80, 0.12); border: 1px solid rgba(255, 80, 80, 0.7); color: #ffd6d6; padding: 12px 14px; border-radius: 10px;">
            <?php echo htmlspecialchars($tokenChargeFlash, ENT_QUOTES); ?>
        </div>
    </div>
    <?php
}

$footerGap = 0;
$footerExtraGap = 0;
$footerConfigFile = function_exists('getDataPath') ? (getDataPath() . '/global-ui/footer/footer-config.json') : null;
if ($footerConfigFile && file_exists($footerConfigFile)) {
    $savedConfig = json_decode((string)file_get_contents($footerConfigFile), true);
    if (is_array($savedConfig) && isset($savedConfig['K::FooterUI::Configuration']) && is_array($savedConfig['K::FooterUI::Configuration'])) {
        $keys = array_keys($savedConfig['K::FooterUI::Configuration']);
        if (!empty($keys) && is_array($savedConfig['K::FooterUI::Configuration'][$keys[0]] ?? null)) {
            $cfg = $savedConfig['K::FooterUI::Configuration'][$keys[0]];
            $footerGap = (int)($cfg['ftr_footer_content_spacing'] ?? $footerGap);
            if (!empty($cfg['ftr_extra_content_spacing_enabled'])) {
                $footerExtraGap = (int)($cfg['ftr_extra_content_spacing'] ?? 0);
            }
        }
    }
}

$headerGap = 0;
$headerConfigFile = function_exists('getDataPath') ? (getDataPath() . '/global-ui/header/header-config.json') : null;
if ($headerConfigFile && file_exists($headerConfigFile)) {
    $savedConfig = json_decode((string)file_get_contents($headerConfigFile), true);
    if (is_array($savedConfig) && isset($savedConfig['K::HeaderUI::Configuration']) && is_array($savedConfig['K::HeaderUI::Configuration'])) {
        $keys = array_keys($savedConfig['K::HeaderUI::Configuration']);
        if (!empty($keys) && is_array($savedConfig['K::HeaderUI::Configuration'][$keys[0]] ?? null)) {
            $cfg = $savedConfig['K::HeaderUI::Configuration'][$keys[0]];
            $headerGap = (int)($cfg['hdr_content_spacing'] ?? $headerGap);
        }
    }
}
?>
<style>
  :root { --mh-header-gap: <?php echo (int)$headerGap; ?>px; --mh-footer-gap: <?php echo (int)$footerGap; ?>px; --mh-footer-extra-gap: <?php echo (int)$footerExtraGap; ?>px; }
  body { margin: 0 !important; }
</style>
<script>
  (function() {
    function applyOffsets() {
      var header = document.querySelector('.cue-global-header');
      var footer = document.querySelector('.cue-global-footer');
      var h = header ? header.offsetHeight : 0;
      var f = 0;
      if (footer) {
        var fh = footer.offsetHeight || 0;
        var sh = footer.scrollHeight || 0;
        f = Math.max(fh, sh, 0);
      }
      var s = getComputedStyle(document.documentElement);
      var headerGap = parseInt(s.getPropertyValue('--mh-header-gap'), 10);
      var footerGap = parseInt(s.getPropertyValue('--mh-footer-gap'), 10);
      var footerExtra = parseInt(s.getPropertyValue('--mh-footer-extra-gap'), 10);
      if (isNaN(headerGap)) headerGap = 0;
      if (isNaN(footerGap)) footerGap = 0;
      if (isNaN(footerExtra)) footerExtra = 0;
      var cs = getComputedStyle(document.body);
      var currentTop = parseInt(cs.paddingTop, 10);
      var currentBottom = parseInt(cs.paddingBottom, 10);
      var headerPosAttr = header ? (header.getAttribute('data-position') || '') : '';
      var headerCssPos = header ? (getComputedStyle(header).position || '') : '';
      var hp = (headerPosAttr || '').toLowerCase();
      var allowed = { fixed: 1, sticky: 1, relative: 1, static: 1 };
      var headerPos = (allowed[hp] ? hp : (headerCssPos || '')).toLowerCase();
      var shouldOffsetTop = (headerPos === 'fixed' || headerPos === 'sticky');
      var spacer = document.querySelector('.cue-global-header-spacer');
      var spacerH = spacer ? (spacer.offsetHeight || 0) : 0;
      var desiredTop = spacerH > 0 ? 0 : (shouldOffsetTop ? (h + headerGap) : 0);
      var footerCssPos = footer ? (getComputedStyle(footer).position || '') : '';
      var footerPos = (footerCssPos || '').toLowerCase();
      var shouldOffsetBottom = (footerPos === 'fixed' || footerPos === 'sticky');
      var desiredBottom = shouldOffsetBottom ? (f + footerGap + footerExtra) : (footerGap + footerExtra);
      if (!isFinite(currentTop) || currentTop < 1) currentTop = 0;
      if (!isFinite(currentBottom) || currentBottom < 1) currentBottom = 0;
      document.body.style.paddingTop = desiredTop + 'px';
      document.body.style.paddingBottom = desiredBottom + 'px';
      var main = document.querySelector('main.main-content');
      if (main) {
        var minPx = Math.max(0, window.innerHeight - desiredTop - desiredBottom);
        main.style.minHeight = minPx + 'px';
      }
    }
    document.addEventListener('DOMContentLoaded', applyOffsets);
    window.addEventListener('resize', applyOffsets);
  })();
</script>

<?php
if ($isDirect) {
    echo '<main class="main-content" style="padding:20px;min-height:50vh"></main>';
    include_once __DIR__ . '/complete-body-end.php';
    echo '</body></html>';
    return;
}
?>

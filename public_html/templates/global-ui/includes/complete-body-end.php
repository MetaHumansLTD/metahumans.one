<?php
/**
 * Complete Global UI Body End Include
 * Include this before closing </body> to add footer and scripts
 */

require_once dirname(__DIR__) . '/functions.php';

if (!headers_sent()) {
    header('Content-Type: text/html; charset=UTF-8');
}

$isDirect = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === basename(__FILE__);
if ($isDirect) {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Global UI Preview</title>';
    include_once __DIR__ . '/complete-head.php';
    echo '</head><body>';
    include_once __DIR__ . '/complete-body-start.php';
    echo '<main class="main-content" style="padding:20px;min-height:50vh"></main>';
    renderGlobalFooter();
    includeGlobalUIScripts();
    echo '</body></html>';
    return;
}

renderGlobalFooter();
includeGlobalUIScripts();

if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['mh_auth_user']) && !empty($_SESSION['mh_profile_missing_fields']) && empty($_SESSION['mh_profile_notice_shown'])) {
    $_SESSION['mh_profile_notice_shown'] = time();
    $missing = is_array($_SESSION['mh_profile_missing_fields']) ? array_values(array_filter(array_map('strval', $_SESSION['mh_profile_missing_fields']))) : [];
    $missingText = implode(', ', $missing);
    $missingJson = json_encode($missingText, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    echo '<script>(function(){var missing=' . ($missingJson ?: '""') . ';function show(){try{var msg=\"Profile incomplete: \"+missing+\". <a href=\\\"/hub/settings.php\\\" style=\\\"color:#fff;text-decoration:underline;font-weight:700;\\\">Open settings</a>\";var n=window.globalPopupNotice||window.popupNotice||(typeof window.PopupNotice!==\"undefined\"?new window.PopupNotice({position:\"top-center\",theme:\"dark\",duration:0,stackNotifications:false}):null);if(n){window.globalPopupNotice=n;n.show(msg,\"warning\",{duration:0,clickToClose:true});}}catch(e){}}if(typeof window.PopupNotice===\"undefined\"){try{var s=document.createElement(\"script\");s.src=\"/templates/widgets/notices/popup-notice.js\";s.onload=show;document.head.appendChild(s);}catch(e){}}else{show();}})();</script>';
}
?>

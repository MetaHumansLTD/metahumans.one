<?php
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';
require_once __DIR__ . '/core.php';

function mh_pdf_editor_require_auth(): void {
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['mh_auth_user'])) {
        $redirect = $_SERVER['REQUEST_URI'] ?? '/pdf/editor/';
        if (!is_string($redirect) || $redirect === '' || $redirect[0] !== '/') {
            $redirect = '/pdf/editor/';
        }
        header('Location: /auth/login.php?redirect=' . rawurlencode($redirect));
        exit;
    }
}

function mh_pdf_editor_csrf_token(): string {
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!isset($_SESSION['mh_pdf_editor_csrf']) || !is_string($_SESSION['mh_pdf_editor_csrf']) || $_SESSION['mh_pdf_editor_csrf'] === '') {
        $_SESSION['mh_pdf_editor_csrf'] = bin2hex(random_bytes(16));
    }
    return (string)$_SESSION['mh_pdf_editor_csrf'];
}

function mh_pdf_editor_verify_csrf(string $token): bool {
    if (function_exists('startSecureSession')) {
        startSecureSession();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $expected = $_SESSION['mh_pdf_editor_csrf'] ?? '';
    return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
}

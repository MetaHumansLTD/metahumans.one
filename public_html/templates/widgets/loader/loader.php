<?php
/**
 * Loading Animation Widget
 * 
 * A reusable full-screen loading overlay with animated spinner rings
 * and customizable loading text.
 * 
 * Usage:
 * - Include this file in your page
 * - Call showLoadingAnimation('Your message') to show
 * - Call hideLoadingAnimation() to hide
 * 
 * @version 1.0
 * @author Navigator System
 */

// Security: Prevent direct access to this widget file
// Ensure CUE framework is loaded; guard to avoid duplicate includes and warnings
require_once dirname(__DIR__, 3) . '/.cue/cue.php';
?>

<!-- Enhanced Loading Animation HTML - Dynamically Generated -->
<div class="loading-overlay hidden" id="loadingOverlay">
    <div class="loading-spinner">
        <!-- Animation elements will be dynamically created based on configuration -->
        <div class="loading-text" id="loadingOverlayText">Loading...</div>
    </div>
</div>

<?php
// Load and inject configuration for JavaScript
try {
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $configPath = $paths ? $paths->getSecureFilePath('widgets/loader/loader-config.json') : null;
    if ($configPath && $paths && function_exists('getDataPath')) {
        if (!$paths->validateSecurePath($configPath, getDataPath())) {
            $configPath = null;
        }
    }
    $config = null;
    
    if ($configPath && file_exists($configPath)) {
        $configContent = file_get_contents($configPath);
        $config = json_decode($configContent, true);
    }
    
    if ($config && is_array($config)) {
        echo '<script type="text/javascript">';
        echo 'window.loaderConfig = ' . json_encode($config) . ';';
        echo '</script>';
    }
} catch (Exception $e) {
    // Configuration loading failed, JavaScript will use defaults
    error_log('Loader widget configuration loading failed: ' . $e->getMessage());
}
?>

<style>
/* Loading Animation Styles */
.loading-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(10, 10, 10, 0.95);
    backdrop-filter: blur(10px);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    opacity: 1;
    visibility: visible;
    transition: opacity 0.18s ease, visibility 0.18s ease;
}

.loading-overlay.hidden {
    opacity: 0;
    visibility: hidden;
}

.loading-spinner {
    text-align: center;
    position: relative;
}

.spinner-ring {
    display: inline-block;
    width: 60px;
    height: 60px;
    margin: 0 5px;
    border: 4px solid transparent;
    border-top: 4px solid var(--primary-color, #3b82f6);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

.spinner-ring:nth-child(2) {
    border-top-color: rgba(124, 58, 237, 1);
    animation-delay: -0.3s;
    width: 50px;
    height: 50px;
}

.spinner-ring:nth-child(3) {
    border-top-color: rgba(245, 158, 11, 1);
    animation-delay: -0.6s;
    width: 40px;
    height: 40px;
}

.loading-text {
    margin-top: 20px;
    color: var(--light-text, #e5e7eb);
    font-size: 1.1rem;
    font-weight: 600;
    animation: pulse 1.5s ease-in-out infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

@keyframes pulse {
    0%, 100% { opacity: 0.7; }
    50% { opacity: 1; }
}
</style>

<script nonce="<?php echo function_exists('cspNonce') ? cspNonce() : ''; ?>">
/**
 * Show the loading animation with optional custom message
 * @param {string} message - The loading message to display
 */
function showLoadingAnimation(message) {
    const overlay = document.getElementById('loadingOverlay');
    const textEl = document.getElementById('loadingOverlayText');
    if (!overlay || !textEl) return;
    // Initialize loader state
    window.__loaderState = window.__loaderState || { lastShow: 0, minVisibleMs: 250, debounceMs: 120, hideTimer: null };
    const state = window.__loaderState;
    // Standardize message
    const msg = normalizeLoaderMessage(message);
    textEl.textContent = msg;
    // Debounce consecutive show calls
    const now = Date.now();
    if (now - state.lastShow < state.debounceMs) {
        // Already shown recently; just update text
        overlay.classList.remove('hidden');
        state.lastShow = now;
        return;
    }
    // Cancel any pending hide
    if (state.hideTimer) {
        clearTimeout(state.hideTimer);
        state.hideTimer = null;
    }
    overlay.classList.remove('hidden');
    state.lastShow = now;
}

/**
 * Hide the loading animation
 */
function hideLoadingAnimation() {
    const overlay = document.getElementById('loadingOverlay');
    if (!overlay) return;
    window.__loaderState = window.__loaderState || { lastShow: 0, minVisibleMs: 250, debounceMs: 120, hideTimer: null };
    const state = window.__loaderState;
    const elapsed = Date.now() - state.lastShow;
    if (elapsed >= state.minVisibleMs) {
        overlay.classList.add('hidden');
        return;
    }
    // Ensure visible for at least minVisibleMs
    const remaining = state.minVisibleMs - elapsed;
    if (state.hideTimer) clearTimeout(state.hideTimer);
    state.hideTimer = setTimeout(() => {
        overlay.classList.add('hidden');
        state.hideTimer = null;
    }, remaining);
}

function normalizeLoaderMessage(message) {
    const defaultMsg = 'Loading…';
    if (!message || typeof message !== 'string') return defaultMsg;
    const trimmed = message.trim();
    if (!trimmed) return defaultMsg;
    // Replace three-dot ellipsis with typographic ellipsis
    const standardized = trimmed.replace(/\.\.\.$/, '…');
    return standardized;
}

// Do not auto-show/hide on page load; controlled explicitly via functions
</script>

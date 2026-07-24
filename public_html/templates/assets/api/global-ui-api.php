<?php
/**
 * Global UI API - Simple REST endpoint
 * @requires CUE Framework
 * @version 94.9.8
 */
// Prevent any accidental output from framework include from breaking JSON
ob_start();
require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
ob_end_clean();

$security = cue_autoload('security');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$method = $_SERVER['REQUEST_METHOD'];
$component = $_GET['component'] ?? '';
$action = $_GET['action'] ?? '';

// Validate component name (except for list action which doesn't need a component)
$validComponents = ['header', 'footer', 'hamburger', 'widgets', 'theme', 'presenters'];
if ($action !== 'list' && !in_array($component, $validComponents)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid component']);
    exit;
}

try {
    switch($method) {
        case 'GET':
            if($action === 'config') {
                // Ensure clean output for JSON
                while (ob_get_level()) { ob_end_clean(); }
                echo json_encode(getComponentConfig($component));
            } elseif($action === 'list') {
                while (ob_get_level()) { ob_end_clean(); }
                echo json_encode(listAvailableComponents());
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if($action === 'save') {
                while (ob_get_level()) { ob_end_clean(); }
                echo json_encode(saveComponentConfig($component, $data));
            } elseif($action === 'validate') {
                while (ob_get_level()) { ob_end_clean(); }
                echo json_encode(validateComponentConfig($component, $data));
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Get component configuration
 * @param string $component Component name
 * @return array Component configuration and metadata
 */
function getComponentConfig($component) {
    $configPaths = [
        'header' => getDataPath() . '/global-ui/header/header-config.json',
        'footer' => getDataPath() . '/global-ui/footer/footer-config.json',
        'hamburger' => getDataPath() . '/global-ui/hamburger/hamburger-config.json',
        'widgets' => getDataPath() . '/widgets/config.json',
        'theme' => getDataPath() . '/theme/theme-config.json',
        'presenters' => getDataPath() . '/meetings/presenters.json'
    ];
    
    $configPath = $configPaths[$component] ?? null;
    if (!$configPath) {
        return ['success' => false, 'error' => 'Component not found'];
    }
    
    $config = [];
    $deprecatedPath = null;
    if ($component === 'widgets') {
        $legacyPath = getDataPath() . '/widgets/widgets-config.json';
        if (!file_exists($configPath) && file_exists($legacyPath)) {
            $deprecatedPath = $legacyPath;
            $configPath = $legacyPath;
        }
    }
    if (file_exists($configPath)) {
        $config = json_decode(file_get_contents($configPath), true) ?: [];
    }
    
    return [
        'success' => true,
        'component' => $component,
        'config' => $config,
        'deprecated_path' => $deprecatedPath,
        'last_modified' => file_exists($configPath) ? filemtime($configPath) : null,
        'file_size' => file_exists($configPath) ? filesize($configPath) : 0
    ];
}

/**
 * Save component configuration
 * @param string $component Component name
 * @param array $data Configuration data
 * @return array Save result
 */
function saveComponentConfig($component, $data) {
    $configPaths = [
        'header' => getDataPath() . '/global-ui/header/header-config.json',
        'footer' => getDataPath() . '/global-ui/footer/footer-config.json',
        'hamburger' => getDataPath() . '/global-ui/hamburger/hamburger-config.json',
        'widgets' => getDataPath() . '/widgets/config.json',
        'theme' => getDataPath() . '/theme/theme-config.json',
        'presenters' => getDataPath() . '/meetings/presenters.json'
    ];
    
    $configPath = $configPaths[$component] ?? null;
    if (!$configPath) {
        return ['success' => false, 'error' => 'Component not found'];
    }
    
    // Ensure directory exists
    $dir = dirname($configPath);
    if(!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    if ($component === 'widgets') {
        $data = normalizeWidgetsConfigForSave($data);
    } else {
        // Add metadata
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['api_version'] = '1.0';
    }
    
    // Validate data based on component
    $validation = validateComponentConfig($component, $data);
    if (!$validation['success']) {
        return $validation;
    }
    
    if ($component === 'presenters') {
        $registry = [];
        if (file_exists($configPath)) {
            $existing = json_decode(file_get_contents($configPath), true);
            if (is_array($existing)) {
                $registry = $existing;
            }
        }

        $meetingId = $data['meetingId'];
        $meetingName = isset($data['meetingName']) && is_string($data['meetingName']) ? $data['meetingName'] : '';
        $presenters = array_values(array_unique(array_map('trim', $data['presenters'])));

        $registry[$meetingId] = [
            'meetingId' => $meetingId,
            'meetingName' => $meetingName,
            'presenters' => $presenters,
        ];

        if (file_put_contents($configPath, json_encode($registry, JSON_PRETTY_PRINT))) {
            return [
                'success' => true,
                'message' => 'Presenters configuration saved successfully',
                'component' => $component,
                'meetingId' => $meetingId,
                'timestamp' => time(),
                'file_size' => filesize($configPath),
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to save presenters registry file. Check permissions.',
        ];
    }

    if (file_put_contents($configPath, json_encode($data, JSON_PRETTY_PRINT))) {
        return [
            'success' => true,
            'message' => ucfirst($component) . ' configuration saved successfully',
            'component' => $component,
            'timestamp' => time(),
            'file_size' => filesize($configPath),
        ];
    }

    return [
        'success' => false,
        'error' => 'Failed to save configuration file. Check permissions.',
    ];
}

function normalizeWidgetsConfigForSave($data) {
    if (!is_array($data)) {
        $data = [];
    }
    if (isset($data['K::WidgetUI::Configuration']) && is_array($data['K::WidgetUI::Configuration'])) {
        return $data;
    }
    $configId = 'K::WidgetUI::Content::' . strtoupper(uniqid());
    $flat = $data;
    if (!isset($flat['wgt_last_updated'])) {
        $flat['wgt_last_updated'] = date('Y-m-d H:i:s');
    }
    return [
        'K::WidgetUI::Configuration' => [
            $configId => $flat
        ]
    ];
}

/**
 * Validate component configuration
 * @param string $component Component name
 * @param array $data Configuration data
 * @return array Validation result
 */
function validateComponentConfig($component, $data) {
    $errors = [];
    
    switch($component) {
        case 'header':
            if (empty($data['title'])) {
                $errors[] = 'Header title is required';
            }
            if (isset($data['height']) && ($data['height'] < 40 || $data['height'] > 120)) {
                $errors[] = 'Header height must be between 40 and 120 pixels';
            }
            break;
            
        case 'footer':
            if (empty($data['copyright'])) {
                $errors[] = 'Copyright text is required';
            }
            break;
            
        case 'hamburger':
            if (empty($data['menu_items'])) {
                $errors[] = 'Menu items are required';
            }
            break;
            
        case 'widgets':
            if (isset($data['autosave_interval']) && ($data['autosave_interval'] < 5 || $data['autosave_interval'] > 300)) {
                $errors[] = 'Autosave interval must be between 5 and 300 seconds';
            }
            break;
            
        case 'theme':
            $requiredColors = ['primary_color', 'secondary_color', 'background_color'];
            foreach($requiredColors as $colorField) {
                if (empty($data[$colorField]) || !preg_match('/^#[0-9a-fA-F]{6}$/', $data[$colorField])) {
                    $errors[] = "Valid {$colorField} is required";
                }
            }
            break;

        case 'presenters':
            if (empty($data['meetingId']) || !is_string($data['meetingId'])) {
                $errors[] = 'meetingId is required';
            }
            if (empty($data['presenters']) || !is_array($data['presenters'])) {
                $errors[] = 'presenters array is required';
            } else {
                $filtered = array_filter($data['presenters'], function ($name) {
                    return is_string($name) && trim($name) !== '';
                });
                if (empty($filtered)) {
                    $errors[] = 'presenters must contain at least one non-empty name';
                } else {
                    $data['presenters'] = array_values(array_unique(array_map('trim', $filtered)));
                }
            }
            break;
    }
    
    if (!empty($errors)) {
        return ['success' => false, 'errors' => $errors];
    }
    
    return ['success' => true, 'message' => 'Configuration is valid'];
}

/**
 * List all available components
 * @return array List of components with metadata
 */
function listAvailableComponents() {
    return [
        'success' => true,
        'components' => [
            'header' => [
                'name' => 'Header Settings',
                'description' => 'Site header with title, logo, and navigation',
                'config_file' => 'header/config.json'
            ],
            'footer' => [
                'name' => 'Footer Settings',
                'description' => 'Site footer with copyright, links, and social media',
                'config_file' => 'footer/config.json'
            ],
            'hamburger' => [
                'name' => 'Navigation Menu',
                'description' => 'Hamburger menu with animation and positioning',
                'config_file' => 'hamburger/config.json'
            ],
            'widgets' => [
                'name' => 'Widget Settings',
                'description' => 'Manage various UI widgets and their settings',
                'config_file' => 'widgets/config.json'
            ],
            'theme' => [
                'name' => 'Theme & Layout',
                'description' => 'Global theme colors, layout, and visual effects',
                'config_file' => 'theme/config.json'
            ],
            'presenters' => [
                'name' => 'Meeting Presenters',
                'description' => 'Per-meeting presenters registry used by Meta Humans Meetings',
                'config_file' => 'meetings/presenters.json'
            ]
        ],
        'version' => '1.0',
        'timestamp' => time()
    ];
}
?>

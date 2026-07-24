<?php
/**
 * CUE Models Module
 * Provides the list of supported AI models from the centralized configuration.
 *
 * @package    CUE Framework
 */

/**
 * Get the list of supported models.
 * 
 * @return array List of model definitions.
 */
function models_get_models(): array {
    $candidates = [];
    if (function_exists('cue_autoload')) {
        $paths = cue_autoload('paths');
        if (is_object($paths) && method_exists($paths, 'getDataPath')) {
            $dataPath = rtrim((string)$paths->getDataPath(), '/');
            if ($dataPath !== '') {
                $candidates[] = $dataPath . '/studio/models_config.json';
                $candidates[] = $dataPath . '/config/models_config.json';
            }
        }
    }
    $candidates[] = '/data/studio/models_config.json';
    $candidates[] = '/data/config/models_config.json';
    $candidates[] = '/data/studio/models_config.json';

    $configPath = '';
    foreach ($candidates as $p) {
        if (is_string($p) && $p !== '' && file_exists($p)) {
            $configPath = $p;
            break;
        }
    }
    if ($configPath === '') {
        return [];
    }
    $json = file_get_contents($configPath);
    $data = json_decode($json, true);
    return is_array($data) ? ($data['ui_models'] ?? []) : [];
}

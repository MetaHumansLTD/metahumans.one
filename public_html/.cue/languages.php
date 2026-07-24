<?php
/**
 * CUE Language Module
 * Provides the list of supported languages for Whisper/Speech recognition.
 *
 * @package    CUE Framework
 */

/**
 * Get the list of supported languages.
 * 
 * @return array Associative array of language codes and names.
 */
function languages_get_languages(): array {
    return [
        'auto' => 'Auto Detect',
        'af-ZA' => 'Afrikaans',
        'am-ET' => 'Amharic',
        'ar-SA' => 'Arabic',
        'hy-AM' => 'Armenian',
        'az-AZ' => 'Azerbaijani',
        'eu-ES' => 'Basque',
        'be-BY' => 'Belarusian',
        'bn-IN' => 'Bengali',
        'bs-BA' => 'Bosnian',
        'bg-BG' => 'Bulgarian',
        'my-MM' => 'Burmese',
        'ca-ES' => 'Catalan',
        'zh-CN' => 'Chinese',
        'hr-HR' => 'Croatian',
        'cs-CZ' => 'Czech',
        'da-DK' => 'Danish',
        'nl-NL' => 'Dutch',
        'en-US' => 'English',
        'et-EE' => 'Estonian',
        'fi-FI' => 'Finnish',
        'fr-FR' => 'French',
        'gl-ES' => 'Galician',
        'ka-GE' => 'Georgian',
        'de-DE' => 'German',
        'el-GR' => 'Greek',
        'gu-IN' => 'Gujarati',
        'he-IL' => 'Hebrew',
        'hi-IN' => 'Hindi',
        'hu-HU' => 'Hungarian',
        'is-IS' => 'Icelandic',
        'id-ID' => 'Indonesian',
        'it-IT' => 'Italian',
        'ja-JP' => 'Japanese',
        'jv-ID' => 'Javanese',
        'kn-IN' => 'Kannada',
        'kk-KZ' => 'Kazakh',
        'km-KH' => 'Khmer',
        'ko-KR' => 'Korean',
        'lo-LA' => 'Lao',
        'lv-LV' => 'Latvian',
        'lt-LT' => 'Lithuanian',
        'mk-MK' => 'Macedonian',
        'ms-MY' => 'Malay',
        'ml-IN' => 'Malayalam',
        'mr-IN' => 'Marathi',
        'mi-NZ' => 'Maori',
        'mn-MN' => 'Mongolian',
        'ne-NP' => 'Nepali',
        'no-NO' => 'Norwegian',
        'fa-IR' => 'Persian',
        'pl-PL' => 'Polish',
        'pt-BR' => 'Portuguese',
        'pa-IN' => 'Punjabi',
        'ro-RO' => 'Romanian',
        'ru-RU' => 'Russian',
        'sr-RS' => 'Serbian',
        'sk-SK' => 'Slovak',
        'sl-SI' => 'Slovenian',
        'es-ES' => 'Spanish',
        'su-ID' => 'Sundanese',
        'sw-KE' => 'Swahili',
        'sv-SE' => 'Swedish',
        'tl-PH' => 'Tagalog',
        'tg-TJ' => 'Tajik',
        'ta-IN' => 'Tamil',
        'te-IN' => 'Telugu',
        'th-TH' => 'Thai',
        'tr-TR' => 'Turkish',
        'uk-UA' => 'Ukrainian',
        'ur-PK' => 'Urdu',
        'uz-UZ' => 'Uzbek',
        'vi-VN' => 'Vietnamese',
        'cy-GB' => 'Welsh'
    ];
}

function languages_get_nmt_languages() {
    $gear = __DIR__ . '/../gear/languages/languages.php';
    if (is_file($gear)) {
        require_once $gear;
        if (function_exists('gear_languages_get_nmt_languages')) {
            return gear_languages_get_nmt_languages();
        }
    }
    return [];
}

function languages_get_nmt_language_codes() {
    $langs = languages_get_nmt_languages();
    if (is_array($langs)) {
        return array_keys($langs);
    }
    return [];
}


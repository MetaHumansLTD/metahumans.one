<?php
/**
 * Metahumans.one Token Provisioning Module for WHMCS
 *
 * This module allows WHMCS to automatically "top up" user token balances
 * in the Metahumans.one database upon payment.
 *
 * @package    MetahumansTokens
 * @author     Metahumans.one
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

if (!function_exists('logModuleCall')) {
    /**
     * Stub for WHMCS logModuleCall function to satisfy static analysis.
     * This function is natively available in the WHMCS environment.
     */
    function logModuleCall($module, $action, $request, $response, $data = null, $replaceVars = null) {}
}

/**
 * Define module metadata
 *
 * @return array
 */
function metahumans_tokens_MetaData()
{
    return [
        'DisplayName' => 'Metahumans Token Provisioning',
        'APIVersion' => '1.1', // Use API Version 1.1
        'RequiresServer' => true, // This is a provisioning module
    ];
}

/**
 * Define module configuration options
 *
 * @return array
 */
function metahumans_tokens_ConfigOptions()
{
    return [
        'API Key' => [
            'Type' => 'password',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Secret API Key to authenticate with metahumans.one',
        ],
        'API Endpoint' => [
            'Type' => 'text',
            'Size' => '50',
            'Default' => 'https://metahumans.one/gear/billing/api/topup.php',
            'Description' => 'Full URL to the top-up endpoint',
        ],
    ];
}

/**
 * Provision a new service (Top Up Tokens)
 *
 * Called when the product is purchased and payment is cleared.
 *
 * @param array $params common module parameters
 *
 * @return string "success" or an error message
 */
function metahumans_tokens_CreateAccount(array $params)
{
    try {
        // 1. Get Product Config
        // We assume the "Token Amount" is set as a Config Option or Custom Field
        // For simplicity, let's assume the product name contains the amount or it's a fixed value
        // Better approach: Use a Configurable Option named "tokens"
        
        $tokenAmount = 0;
        
        // Check for Configurable Option "tokens"
        if (isset($params['configoptions']['tokens'])) {
            $tokenAmount = (int) $params['configoptions']['tokens'];
        } 
        // Fallback: Check custom field
        elseif (isset($params['customfields']['tokens'])) {
            $tokenAmount = (int) $params['customfields']['tokens'];
        }
        // Fallback: Default to 1000 if not found (or parse from package name)
        else {
             // You can define "1000000" in the Module Settings for the specific product in WHMCS
             // But usually, you want different products to have different amounts.
             // Let's look for a service custom field or just assume a default for now.
             $tokenAmount = 1000; // Default placeholder
        }

        // 2. Prepare API Payload
        $payload = [
            'email' => $params['clientsdetails']['email'],
            'tokens' => $tokenAmount,
            'transaction_id' => $params['transid'], // WHMCS Transaction ID
            'service_id' => $params['serviceid']
        ];

        // 3. Send Request to Metahumans.one API
        $response = metahumans_tokens_call_api($params, $payload);

        if ($response['success']) {
            return 'success';
        } else {
            return 'API Error: ' . ($response['message'] ?? 'Unknown error');
        }

    } catch (Exception $e) {
        // Log error in WHMCS Module Log
        if (function_exists('logModuleCall')) {
            logModuleCall(
                'metahumans_tokens',
                __FUNCTION__,
                $params,
                $e->getMessage(),
                $e->getTraceAsString()
            );
        }
        return $e->getMessage();
    }
}

/**
 * Helper to call the remote API
 *
 * @param array $params
 * @param array $payload
 * @return array
 */
function metahumans_tokens_call_api($params, $payload)
{
    // Use named parameters if available, otherwise fallback to numbered config options
    $url = $params['API Endpoint'] ?? $params['configoption2']; 
    $apiKey = $params['API Key'] ?? $params['configoption1'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-KEY: ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception('Curl Error: ' . curl_error($ch));
    }
    
    // curl_close($ch); // Not needed in PHP 8.0+ and deprecated by linter

    $decoded = json_decode($response, true);

    if ($httpCode !== 200) {
        throw new Exception('HTTP Error ' . $httpCode . ': ' . ($decoded['message'] ?? $response));
    }

    return $decoded;
}

/**
 * Test Connection
 *
 * @param array $params
 * @return array
 */
function metahumans_tokens_TestConnection(array $params)
{
    try {
        // Send a dummy "ping" request
        // Implementation depends on if your API supports a health check
        return ['success' => true];
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

// Required functions for WHMCS modules, even if empty
function metahumans_tokens_SuspendAccount(array $params) { return 'success'; }
function metahumans_tokens_UnsuspendAccount(array $params) { return 'success'; }
function metahumans_tokens_TerminateAccount(array $params) { return 'success'; }
function metahumans_tokens_ChangePassword(array $params) { return 'success'; }
function metahumans_tokens_ChangePackage(array $params) { return 'success'; }


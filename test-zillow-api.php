<?php

/**
 * Test script for Zillow/Bridge Data Output API
 * Run: php test-zillow-api.php
 */

$serverToken = '78c8cbd5fbbba256de6dc99f22e77d92';
$baseUrl = 'https://api.bridgedataoutput.com/api/v2';
$query = '10 monroe';

echo "Testing Zillow/Bridge Data Output API\n";
echo "=====================================\n\n";

// Test different endpoints and authentication methods
$endpoints = [
    '/properties',
    '/zestimates',
    '/addresses',
    '/search',
];

$authMethods = [
    ['name' => 'Query param', 'params' => ['access_token' => $serverToken, 'address' => $query]],
    ['name' => 'Query param (q)', 'params' => ['access_token' => $serverToken, 'q' => $query]],
    ['name' => 'Bearer header', 'params' => ['address' => $query], 'headers' => ['Authorization' => 'Bearer ' . $serverToken]],
];

foreach ($endpoints as $endpoint) {
    echo "Testing endpoint: {$endpoint}\n";
    echo str_repeat('-', 50) . "\n";
    
    foreach ($authMethods as $authMethod) {
        $url = $baseUrl . $endpoint;
        $params = $authMethod['params'];
        $headers = $authMethod['headers'] ?? [];
        $headers['Accept'] = 'application/json';
        
        // Build query string
        $queryString = http_build_query($params);
        $fullUrl = $url . '?' . $queryString;
        
        echo "  Method: {$authMethod['name']}\n";
        echo "  URL: {$fullUrl}\n";
        
        // Make request using curl
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_map(function($k, $v) {
            return "$k: $v";
        }, array_keys($headers), $headers));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "  Status: {$httpCode}\n";
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            echo "  ✅ SUCCESS!\n";
            echo "  Response keys: " . implode(', ', array_keys($data ?? [])) . "\n";
            if (isset($data['bundle'])) {
                echo "  Bundle count: " . (is_array($data['bundle']) ? count($data['bundle']) : 'N/A') . "\n";
            }
            if (isset($data['properties'])) {
                echo "  Properties count: " . (is_array($data['properties']) ? count($data['properties']) : 'N/A') . "\n";
            }
            echo "\n";
            break 2; // Found working endpoint, exit both loops
        } else {
            echo "  ❌ Failed\n";
            $errorData = json_decode($response, true);
            if ($errorData) {
                echo "  Error: " . json_encode($errorData, JSON_PRETTY_PRINT) . "\n";
            } else {
                echo "  Response: " . substr($response, 0, 200) . "\n";
            }
            echo "\n";
        }
    }
    echo "\n";
}

echo "\nDone testing.\n";



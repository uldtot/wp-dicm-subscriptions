<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('SUBSCRIPTIONS_DEBUG', true);

class OnPayAPI {
    private $api_url = 'https://api.onpay.io/v1/';
    private $token;
    private $log_file;

    public function __construct($token) {
        $this->token = $token;
        $this->log_file = plugin_dir_path(__FILE__) . 'onpay_api.log';
    }

    public function call($endpoint, $method = 'GET', $data = []) {
        $url = $this->api_url . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method)
        ];

        if (!empty($data) && ($method === 'POST' || $method === 'PUT' || $method === 'PATCH')) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $log_data = [
            'url' => $url,
            'method' => $method,
            'request_data' => $data,
            'http_code' => $http_code,
            'response' => $response,
            'error' => $error
        ];

        if (SUBSCRIPTIONS_DEBUG) {
            $this->log($log_data);
        }

        return $log_data;
    }

    private function log($data) {
        $log_entry = date('Y-m-d H:i:s') . ' ' . print_r($data, true) . "\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }
}

// Example usage:
// $api = new OnPayAPI('your_api_token_here');
// $response = $api->call('gateway/information', 'GET');
// print_r($response);
<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}


class EconomicAPI {
    private $api_url = 'https://restapi.e-conomic.com/';
    private $app_secret;
    private $api_token;
    private $log_file;

    public function __construct($app_secret, $api_token) {
        $this->app_secret = $app_secret;
        $this->api_token = $api_token;
        $this->log_file = plugin_dir_path(__FILE__) . 'economic_api.log';
    }

    public function call($endpoint, $method = 'GET', $data = []) {
        $url = $this->api_url . $endpoint;
        
        $headers = [
            'Content-Type: application/json',
            'X-AppSecretToken: ' . $this->app_secret,
            'X-AgreementGrantToken: ' . $this->api_token
        ];

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => strtoupper($method)
        ];

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
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

        return json_decode($response, true);
    }

    private function log($data) {
        $log_entry = date('Y-m-d H:i:s') . ' ' . print_r($data, true) . "\n";
        file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }

    public function searchCustomerByEmail($email) {
        $endpoint = 'customers?filter=email$eq:' . urlencode($email);
        $response = $this->call($endpoint, 'GET');
        return $response['collection'][0] ?? null;
    }

    public function createCustomer($customerData) {
        return $this->call('customers', 'POST', $customerData);
    }

    public function updateCustomer($customerNumber, $customerData) {
        $endpoint = "customers/{$customerNumber}";
        return $this->call($endpoint, 'PUT', $customerData);
    }

    public function findOrCreateCustomer($email, $customerData) {
        $customer = $this->searchCustomerByEmail($email);

        if ($customer) {
            return $customer;
        }

        return $this->createCustomer($customerData);
    }

    public function findOrUpdateCustomer($email, $customerData) {
        $customer = $this->searchCustomerByEmail($email);

        if ($customer) {
            return $this->updateCustomer($customer['customerNumber'], $customerData);
        }

        return $this->createCustomer($customerData);
    }

    // Method to create a draft invoice
    public function createDraftInvoice($invoiceData) {
        $endpoint = 'invoices/drafts';
        return $this->call($endpoint, 'POST', $invoiceData);
    }

    // Method to book (complete) a draft invoice
    public function bookInvoice($draftInvoiceNumber, $bookWithNumber = null, $sendBy = null) {
        $data = [
            'draftInvoice' => [
                'draftInvoiceNumber' => $draftInvoiceNumber,
                'self' => "https://restapi.e-conomic.com/invoices/drafts/{$draftInvoiceNumber}"
            ]
        ];

        if ($bookWithNumber) {
            $data['bookWithNumber'] = $bookWithNumber;
        }

        if ($sendBy) {
            $data['sendBy'] = $sendBy; // 'none' or 'ean'
        }

        $endpoint = 'invoices/booked';
        return $this->call($endpoint, 'POST', $data);
    }

    // Method to download the PDF of a sent invoice
    public function downloadInvoicePdf($invoiceNumber) {
        $endpoint = "invoices/sent/{$invoiceNumber}/pdf";
        $response = $this->call($endpoint, 'GET');

        if (isset($response['url'])) {
            // Download the PDF file
            $pdf_url = $response['url'];
            $pdf_content = file_get_contents($pdf_url);
            
            // Save the PDF file locally (optional)
            $pdf_filename = "invoice_{$invoiceNumber}.pdf";
            file_put_contents($pdf_filename, $pdf_content);
            
            return $pdf_filename; // Return the filename of the saved PDF
        }

        return null; // If there is no PDF URL
    }
}


/*
// Example usage to create a customer or update if exists
$api = new EconomicAPI('your_app_secret', 'your_api_token');

// Define customer data
$customerData = [
    'currency' => 'DKK',
    'customerGroup' => ['customerGroupNumber' => 1],
    'name' => 'Test Kunde',
    'paymentTerms' => ['paymentTermsNumber' => 1],
    'vatZone' => ['vatZoneNumber' => 1],
    'email' => 'customer@example.com'
];

// Opret eller opdater en kunde baseret på email
$customer = $api->findOrUpdateCustomer('customer@example.com', $customerData);

// Output customer information
print_r($customer);

// Example usage to create a draft invoice
$invoiceData = [
    'currency' => 'DKK',
    'customer' => [
        'customerNumber' => $customer['customerNumber'],
        'self' => 'uri_to_customer_resource'
    ],
    'date' => '2025-02-14',
    'layout' => [
        'layoutNumber' => 1,
        'self' => 'uri_to_layout_resource'
    ],
    'paymentTerms' => [
        'paymentTermsNumber' => 1
    ],
    'recipient' => [
        'name' => 'Recipient Name',
        'vatZone' => ['vatZoneNumber' => 1],
        'address' => 'Recipient Address',
        'city' => 'Recipient City',
        'country' => 'DK',
        'zip' => '1234'
    ],
    'lines' => [
        [
            'lineNumber' => 1,
            'description' => 'Product/Service Description',
            'quantity' => 1,
            'unitCostPrice' => 100,
            'unitNetPrice' => 100,
            'product' => [
                'productNumber' => 'P12345',
                'self' => 'uri_to_product_resource'
            ],
            'unit' => [
                'unitNumber' => 1,
                'self' => 'uri_to_unit_resource'
            ]
        ]
    ]
];

// Create the draft invoice
$invoiceResponse = $api->createDraftInvoice($invoiceData);

// Output the invoice creation response
print_r($invoiceResponse);

// Example usage to book the invoice
$draftInvoiceNumber = $invoiceResponse['draftInvoiceNumber']; // Get the draft invoice number
$bookedInvoiceResponse = $api->bookInvoice($draftInvoiceNumber, 7, 'ean'); // Optional: Specify book number and send via EAN

// Output the booked invoice response
print_r($bookedInvoiceResponse);

// Example usage to download the PDF of the sent invoice
$sentInvoiceNumber = $bookedInvoiceResponse['invoiceNumber']; // Get the booked invoice number
$pdfFilename = $api->downloadInvoicePdf($sentInvoiceNumber);

// Output the downloaded PDF file name
echo "PDF downloaded: " . $pdfFilename;


*/

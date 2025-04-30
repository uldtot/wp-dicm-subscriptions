<?php
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
/**
 * Plugin Name: Subscriptions
 * Plugin URI: https://example.com/subscriptions
 * Description: Et simpelt abonnementssystem til WordPress.
 * Version: 1.0.1
 * Author: Dit Navn
 * Author URI: https://example.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: subscriptions
 * Domain Path: /languages
 */


//TODO. arbejder på linje 250

//TODO. arbejder på linje 250

//TODO. arbejder på linje 250

//TODO. arbejder på linje 250

//TODO. arbejder på linje 250



// Tilføj callback funktion ånr onpay sender den tilbage, hvor den sætter at fakturaen kan faktureres hvorefter cronjobbet så om 7 dage kan trækket beløbet OG sætte fakturaen i economic til publish.
/* TODO: 
 ✓ Opret callback funktion så gateway kan sende data tilbage og fortælle at entry er sat til at blive betalt
 ✓ Maybe move all from data to subscription in case the form data disappears and we can then have it all in the subscription... Or maybe better add it to the user,
 ✓ Move product data from form to subscription. We need everything in the subscrition, so we dont have to lookup in the form all the time and can later add APIes etc.
 ✓ Kæd entry ID sammen med subscriotion så vi kan finde subscription ud fra callbacken der bruger entry ID og derved kan finde eocnomic fakturaen der skal sættes til godkendt..
 ✓ Kæd kortdata sammen med subscription så vi kan lave en capture senere.
 ✓ Kæd subscription sammen med economic så vi kan finde eocnomic fakturaen ud fra denne subscription. Faktura opretter af cronjobbet så den skal ikke kædes sammen, da betalingen sker ved oprettelsen. Den skal oprette fakturaen først og så forsøge betalingen ellers har vi ingen ide om at folk ikke har betalt og servicen kan i teorien stå udendeligt uden at de betaler.
 ✓ Lav at callback opdaterer felterne ready_to_invoice_timestamp og ready_to_invoice
 - Sæt cronjob til at trække beløb 7 dage efter callbacken fortæller at betalingen er godkendt. Field: ready_to_invoice_timestamp
 - Sæt et felt som cronjobbete tjekker når den månedligt skal lave fakturaer så den ved om den overhoved har fået en callback. Field: ready_to_invoice
 - Error handling på alle oprettelser
 - Mail templates til error handling
 - Mail templates til andet
 - mail template skal kunne håndtere variabler
 - cPanel integration
 */




if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class SubscriptionsPlugin
{
    private static $instance = null;
    private $option_name = 'subscriptions_api_token';
    private $economic_app_secret = 'subscriptions_economic_app_secret';
    private $economic_api_token = 'subscriptions_economic_api_token';

    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('admin_menu', [$this, 'add_options_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('af/form/entry_created', [$this, 'entry_created'], 10, 2);
        add_action('login_enqueue_scripts',  [$this, 'custom_login_style']);
        add_action('wp', [$this, 'register_cronjob']);
        add_action('custom_daily_cronjob', [$this, 'custom_daily_cronjob']);
        add_action('custom_hourly_cronjob', [$this, 'custom_hourly_cronjob']);
        add_action('save_post', [$this, 'update_subscription_notification_status'], 10, 3);

        // Callback from payment gateway
        add_action('wp_ajax_paymentSuccessCallback', [$this, 'paymentSuccessCallback_function']);
        add_action('wp_ajax_nopriv_paymentSuccessCallback', [$this, 'paymentSuccessCallback_function']); // Hvis ikke-logged-in brugere også skal kunne kalde den
 
 	
        require dirname(__FILE__).'/plugin-update-checker/plugin-update-checker.php';
     
        $myUpdateChecker = PucFactory::buildUpdateChecker(
            'https://github.com/uldtot/wp-dicm-subscriptions/',
            __FILE__,
            'wp-dicm-subscriptions'
        );

        //Set the branch that contains the stable release.
        $myUpdateChecker->setBranch('main');

 
 
    }



    // Usueally created by the cronjob after it the subscription have been activated by a payment gateway callback. 
    // But will be made to work for any usecase just by providing the subscrioption ID
    public function createEconomicDraftInvoice($subscriptionId)
    {
        
        $formEntryID = get_post_meta($subscriptionId, 'entryID', true); // Get the form entry data.
        
        // Get customer data
        $customer = get_post_meta($subscriptionId, 'customer', true); // Henter e-mail fra post meta

        $user = get_user_by('id', $customer);
        $eMail = $user->user_email;

        $company = get_user_meta($customer, 'Company', true);
        $address = get_user_meta($customer, 'address', true);
        $zip = get_user_meta($customer, 'zipcode', true);
        $city = get_user_meta($customer, 'city', true);
        $country = get_user_meta($customer, 'country', true);
        $phone = get_user_meta($customer, 'phone', true);

        $economicName = !empty($company) ? $company : $contactperson;

        // Get product data
        $product = get_post_meta($subscriptionId, 'product', true);

        // Prepare eConomic array
        $customerData = [
            'currency' => 'DKK',
            'customerGroup' => ['customerGroupNumber' => 1],
            'name' => $economicName,
            'address' => $address,
            'zip' => $zip,
            'country' => $country,
            'city' => $city,
            'paymentTerms' => ['paymentTermsNumber' => 1],
            'vatZone' => ['vatZoneNumber' => 1],
            'email' => $eMail,
            'mobilePhone' => $phone
        ];

        // Create or update client based on provided email adress.
        $customer = $api->findOrUpdateCustomer($eMail, $customerData);
        $customerNumber = $customer["customerNumber"];

        // Craete draft invoice
        $invoiceData = [
            'currency' => 'DKK', 
            'customer' => [
                'customerNumber' => $customerNumber,
                'self' => 'https://websitecare.dk/'
            ],
            'date' => date("Y-m-d", strtotime("+7 days")),
            'layout' => [
                'layoutNumber' => 19,
                'self' => 'https://websitecare.dk/'
            ],
            'paymentTerms' => [
                'paymentTermsNumber' => 1
            ],
            'recipient' => [
                'name' => $economicName,
                'vatZone' => ['vatZoneNumber' => 1],
                'address' => $address,
                'city' => $city,
                'country' => $country,
                'zip' => $zip
            ],
            'lines' => [
                [
                    'lineNumber' => 1,
                    'description' => $productName,
                    'quantity' => 1,
                    'unitCostPrice' => 0,
                    'unitNetPrice' => $productPrice, // Ex vat
                    'product' => [
                        'productNumber' => '1',
                        'self' => 'https://websitecare.dk/'
                    ],
                    'unit' => [
                        'unitNumber' => 1,
                        'self' => 'https://websitecare.dk/'
                    ]
                ]
            ]
        ];

        // Create the draft invoice

        // Example usage to create a customer or update if exists
        $economic_app_secret = get_option($this->economic_app_secret, '');
        $economic_api_token = get_option($this->economic_api_token, '');
        $api = new EconomicAPI($economic_app_secret, $economic_api_token);

        $invoiceResponse = $api->createDraftInvoice($invoiceData);
    }

    public function findEconomicInvoiceFromEntryID()
    {
    }

    public function setEconomicInvoicePublished()
    {
    }



    public function update_subscription_notification_status($post_id, $post, $update)
    {
        // Sikrer at det kun kører på den rigtige post type
        if ($post->post_type !== 'subscription') {
            return;
        }

        // Sikrer, at vi ikke kører dette under autosave eller revisioner
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Opdater ACF-feltet til 0
        update_field('notified_client_subscription_added_by_admin', 0, $post_id);
    }


    // Registrér cronjob, hvis det ikke allerede er planlagt
    public function register_cronjob()
    {
        if (!wp_next_scheduled('custom_daily_cronjob')) {
            wp_schedule_event(time(), 'daily', 'custom_daily_cronjob');
        }

        if (!wp_next_scheduled('custom_hourly_cronjob')) {
            wp_schedule_event(time(), 'hourly', 'custom_hourly_cronjob');
        }
    }

    public function custom_daily_cronjob()
    {
        
        die("dont allow this to run yet. Test it from frontend.");
        

    // First, create the invoice before attempting to capture payment.  
    // This ensures we can track whether the payment was successful.  
    // Without an invoice, our system has nothing to track, making it impossible to determine if the customer has paid.  
    // If the payment fails and no invoice exists, the subscription could remain active indefinitely without payment.  
    // but the invoice must be created before payment is attempted to ensure proper tracking.

    // Find subscriptions we need to invoice
         $args = array(
            'post_type'      => 'subscription',
            'posts_per_page' => -1,
        );
        
        $subscriptions = get_posts($args);
        
        foreach ($subscriptions as $subscription) {
            $ready_to_invoice = get_post_meta($subscription->ID, 'ready_to_invoice', true);
            $ready_to_invoice_timestamp = get_post_meta($subscription->ID, 'ready_to_invoice_timestamp', true);
            
            // Check if ready and 7 days have passed since ready_to_invoice_timestamp
            if ($ready_to_invoice == 1 && $ready_to_invoice_timestamp && (time() - $ready_to_invoice_timestamp) >= (7 * 24 * 60 * 60)) {
                
                // Create invoice
                $createEconomicDraftInvoice = $this->createEconomicDraftInvoice($subscription->ID);
                
                // Book / publish the invoice
                $bookedInvoiceResponse = $api->bookInvoice($draftInvoiceNumber, "", ""); // Optional: Specify book number and send via EAN

                
                // Example usage to download the PDF of the sent invoice
                $sentInvoiceNumber = $bookedInvoiceResponse['invoiceNumber']; // Get the booked invoice number
                $pdfFilename = $api->downloadInvoicePdf($sentInvoiceNumber);

                    
                // Capture payment
/*
         {
          "data": {
            "payment_uuid": "dfe8bf50-aaaa-11e7-898d-be9d7bb73511",
            "amount": 12000,
            "currency_code": "980",
            "expiration": "2022-03-02 11:12:14",
            "language": "en",
            "method": "card",
          }
          "links": {
            "payment_window": "https://onpay.io/window/v3/dfe8bf50-aaaa-11e7-898d-be9d7bb73511"
          }
        }
        */
                require_once plugin_dir_path(__FILE__) . 'onpayApi.php';      
                $token = get_option($this->option_name, '');
                $api = new OnpayApi($token);

                $paymentGatewayData = get_post_meta($subscription->ID, "paymentGatewayData", true); // Replace with actual UUID or subscription number
                $subscription_uuid = $paymentGatewayData->data->payment_uuid;
                $amount = "1";
                $data = [
                    "data" => [
                        "amount" => $amount, // Amount in minor units (e.g., cents or øre)
                        "order_id" => $bookedInvoiceResponse['invoiceNumber'] // Unique order ID
                    ]
                ];
                
                $responseRaw = $api->call("v1/subscription/{$subscription_uuid}/authorize", 'POST', $data);
                // payment success 
                
                    // Send email with PDF to client and inform about the apyment status
                
                
                // payment failed.
                
                    // Send email with PDF to client and inform about the apyment status
                
                
            }
        }
        

    }

    public function custom_hourly_cronjob()
    {

        // Hourly: "subscription added by admin"
        // Finder alle subscriptions hvor feltet er 0 so den kan sende besked ud til kunden at der er ændringer i deres subscription
        $args = [
            'post_type'      => 'subscription',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'   => 'notified_client_subscription_added_by_admin',
                    'value' => '0',
                ],
            ],
        ];

        $subscriptions = get_posts($args);

        foreach ($subscriptions as $subscription) {

            $post_id = $subscription->ID;
            $customer = get_post_meta($post_id, 'customer', true); // Henter e-mail fra post meta

            $user = get_user_by('id', $customer);
            $eMail = $user->user_email;

            if (!empty($eMail)) {
                $template = $this->getEmailTemplate("subscription added by admin");

                if ($template) {
                    // Send e-mail
                    wp_mail(
                        $eMail,
                        $template['subject'],
                        $template['body']
                    );

                    // Marker som sendt (sæt feltet til 1)
                    update_post_meta($post_id, 'notified_client_subscription_added_by_admin', '1');
                }
            }
        }
    }

    public function getEmailTemplate($applies_to)
    {


        // Query for at finde e-mail-skabelonen baseret på applies_to
        $args = [
            'post_type'      => 'e-mail-template',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => 'applies_to',
                    'value' => $applies_to,
                    'compare' => '='
                ]
            ]
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            $query->the_post();
            $emailTemplateId = get_the_ID();
            $subject =  get_field('e-mail_subject', $emailTemplateId); // Brug post title som emnelinje
            $body    =  get_field('content', $emailTemplateId);; // Hent e-mail-indholdet
            wp_reset_postdata(); // Reset global postdata


            return [
                'subject' => $subject,
                'body'    => $body
            ];
        } else {
            return false; // Ingen match fundet
        }
    }

    
    /**
     * Handles the payment success callback.
     * 
     * This function is triggered when a payment is successfully processed.
     * It verifies the order ID, updates the order metadata to mark it as 
     * ready for invoicing, and sends a JSON response.
     */
    public function paymentSuccessCallback_function()
    {
        // Retrieve the order ID from the request
        $subscriptionID = isset($_GET['subscriptionID']) ? intval($_GET['subscriptionID']) : 0;
    
        // Validate the order ID
        if (!$subscriptionID) {
            error_log("Payment callback failed: Order ID missing");
            wp_send_json_error(['message' => 'Order ID missing']);
        }
    
        error_log("Payment callback received for Order ID: {$subscriptionID}");
    
        // Mark the order as ready to invoice by updating its metadata
        update_post_meta($subscriptionID, 'ready_to_invoice_timestamp', time()); 
        update_post_meta($subscriptionID, 'ready_to_invoice', 1); 
    
        error_log("Order ID {$subscriptionID} marked as ready to invoice");
    
        // Send a success response with the order ID
        wp_send_json_success(['message' => 'Callback received', 'subscriptionID' => $subscriptionID]);
    }

    public function add_options_page()
    {
        add_options_page(
            'Subscriptions Settings',
            'Subscriptions',
            'manage_options',
            'subscriptions-settings',
            [$this, 'render_options_page']
        );
    }

    public function register_settings()
    {
		
	
		
        register_setting('subscriptions_options_group', $this->option_name);
        register_setting('subscriptions_options_group', $this->economic_api_token);
        register_setting('subscriptions_options_group', $this->economic_app_secret);

        add_settings_section('subscriptions_main_section', 'Main Settings', null, 'subscriptions-settings');
        add_settings_field(
            'subscriptions_api_token',
            'OnPay.io API Token',
            [$this, 'render_api_token_field'],
            'subscriptions-settings',
            'subscriptions_main_section'
        );
        add_settings_field(
            'subscriptions_economic_app_secret',
            'eConomic App Secret',
            [$this, 'render_economic_app_secret_field'],
            'subscriptions-settings',
            'subscriptions_main_section'
        );
        add_settings_field(
            'subscriptions_economic_api_token',
            'eConomic API Token',
            [$this, 'render_economic_api_token_field'],
            'subscriptions-settings',
            'subscriptions_main_section'
        );
    }

    public function custom_login_style()
    { ?>
        <style type="text/css">
            body.login {
                background-color: #1F2641 !important;
            }

            .login h1 a {
                background-image: url('https://customer.websitecare.dk/wp-content/uploads/2025/02/cropped-WSC-logo-white.png') !important;
                background-size: contain !important;
                width: 100% !important;
                height: 84px !important;
            }
        </style>

    <?php }

    function normalizeNumber($number)
    {
        // Hvis der både er . og , antager vi europæisk notation (1.000,50)
        if (strpos($number, ',') !== false && strpos($number, '.') !== false) {
            $number = str_replace(".", "", $number); // Fjern tusindseparator (.)
            $number = str_replace(",", ".", $number); // Skift komma til punktum
        }
        // Hvis kun , findes, brug det som decimaltegn
        elseif (strpos($number, ',') !== false) {
            $number = str_replace(",", ".", $number);
        }
        // Hvis kun . findes, brug det som decimaltegn (intet ændres)

        return floatval($number); // Konverter til float
    }


    public function render_options_page()
    {
    ?>
        <div class="wrap">
            <h1>Subscriptions Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('subscriptions_options_group');
                do_settings_sections('subscriptions-settings');
                submit_button();
                ?>
            </form>
        </div>
<?php
    }

    public function render_api_token_field()
    {
        $value = get_option($this->option_name, '');
        echo '<input type="text" name="' . esc_attr($this->option_name) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }



    public function render_economic_app_secret_field()
    {
        $value = get_option($this->economic_app_secret, '');
        echo '<input type="text" name="' . esc_attr($this->economic_app_secret) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }


    public function render_economic_api_token_field()
    {
        $value = get_option($this->economic_api_token, '');
        echo '<input type="text" name="' . esc_attr($this->economic_api_token) . '" value="' . esc_attr($value) . '" class="regular-text">';
    }



    public function entry_created($formEntryID, $form)
    {
        require_once plugin_dir_path(__FILE__) . 'onpayApi.php';

        $contactperson = get_post_meta($formEntryID, 'contactperson', true);

        $eMail = get_post_meta($formEntryID, 'e-mail', true);

        require_once plugin_dir_path(__FILE__) . 'economic.php';


        // Define customer data
        $company = get_post_meta($formEntryID, 'Company', true);
        $address = get_post_meta($formEntryID, 'address', true);
        $zip = get_post_meta($formEntryID, 'zipcode', true);
        $city = get_post_meta($formEntryID, 'city', true);
        $country = get_post_meta($formEntryID, 'country', true);
        $phone = get_post_meta($formEntryID, 'phone', true);
        $economicName = !empty($company) ? $company : $contactperson;

        // Get product values        
        $product = get_post_meta($formEntryID, 'product', true);
        $productName = get_the_title($product);
        $productPrice = $this->normalizeNumber(get_post_meta($product, 'price', true));


        // Tjek om brugeren allerede eksisterer
        if (!email_exists($eMail)) {
            // Generer et tilfældigt password
            $random_password = wp_generate_password(12, false);

            $template = $this->getEmailTemplate("new acocunt");


            // Opret en ny bruger
            $user_id = wp_create_user($eMail, $random_password, $eMail);

            if (!is_wp_error($user_id)) {

                // Opdater brugerens navn
                wp_update_user([
                    'ID' => $user_id,
                    'display_name' => $contactperson,
                    'first_name' => $contactperson
                ]);

                // Tildel en standardrolle (f.eks. abonnent)
                $user = new WP_User($user_id);
                $user->set_role('subscriber');
                
                // Update user meta
                $company = update_user_meta($user_id, 'Company');
                $address = update_user_meta($user_id, 'address');
                $zip = update_user_meta($user_id, 'zipcode');
                $city = update_user_meta($user_id, 'city');
                $country = update_user_meta($user_id, 'country');
                $phone = update_user_meta($user_id, 'phone');

                // Send en e-mail til brugeren med deres loginoplysninger

                if ($template) {
                    wp_mail(
                        "$eMail",
                        $template['subject'],
                        $template['body']
                    );
                }


                /* wp_mail(
                $eMail,
                'Velkommen til WebsiteCare',
                "Hej $contactperson,\n\nDu er nu oprettet som bruger.\n\nBrugernavn: $eMail\nPassword: $random_password\n\nLog ind her: https://customer.websitecare.dk/wp-login.php"
            );*/
            }
        } else {
            $user_id = get_user_by('email', $eMail);
        }


        // Create subscription in WP but do not create invoice yet, we do that when we have callback data.    
        $post_data = array(
            'post_title'   => $economicName,
            'post_type'    => 'subscription',
            'post_status'  => 'publish',
        );

        $subscriptionId = wp_insert_post($post_data);

        if ($subscriptionId) {
            //echo "Subscription post created successfully with ID: " . $post_id;


            update_post_meta($subscriptionId, 'recurring_rate', "3"); // Recurring every 3 month
            update_post_meta($subscriptionId, 'customer', $user_id->ID); // 
            update_post_meta($subscriptionId, 'product', $product); // Not yet confirmed, but should just be a product ID where then can fetch the data.

            // Link post data
            update_post_meta($subscriptionId, 'entryID', $formEntryID); // original form ID
            update_post_meta($formEntryID, 'subscriptionId', $subscriptionId); // Subscription ID, just to make sure we have the data.


            // Opdater post-meta med den nye repeater
            $repeater_product_id = get_post_meta($formEntryID, 'product', true);
            update_post_meta($subscriptionId, 'product_repeater', 1);

            // Since there is only 1 product per form order we dont loop it.    
            update_post_meta($subscriptionId, 'product_repeater_0_product', $repeater_product_id);
            update_post_meta($subscriptionId, 'product_repeater_0_recurring', 1);
            update_post_meta($subscriptionId, 'product_repeater_0_notified_client_subscription_added_by_admin', 1);
        } else {
            // echo "Failed to create subscription post.";
        }

        /*

Test data
If test mode is activated on the merchant, it is possible to use the following test cards.

Any expiration date in the future will work, as well as any CVC.

Visa/Dankort
PAN	Status
4571 9900 2080 0010	Accepted
4571 9900 2080 0028	Denied
4571 9900 2080 0036	Capture fails
4571 9900 2080 0044	Recurring fails
Dankort
PAN	Status
5019 9900 2080 0017	Accepted
5019 9900 2080 0025	Denied
5019 9900 2080 0033	Capture fails
5019 9900 2080 0041	Recurring fails
Visa
PAN	Status
4687 3800 2080 0015	Accepted
4687 3800 2080 0023	Denied
4687 3800 2080 0031	Capture fails
4687 3800 2080 0049	Recurring fails
MasterCard
PAN	Status
5204 7400 2080 0011	Accepted
5204 7400 2080 0029	Denied
5204 7400 2080 0037	Capture fails
5204 7400 2080 0045	Recurring fails

*/

        // Start the payment process. Setting customer card up for payment.
        $data = [
            "currency" => "DKK",
            "amount" => 0,
            "reference" => "subscription-$formEntryID",
            "accepturl" => "https://customer.websitecare.dk/accept?orderId=$formEntryID",
            "type" => "subscription",
            "method" => "card",
            "language" => "en",
            "declineurl" => "https://customer.websitecare.dk/decline?orderId=$formEntryID", // Not ready
            "callbackurl" => "https://customer.websitecare.dk/wp-admin/admin-ajax.php?action=paymentSuccessCallback&subscriptionID=$subscriptionId",
            "website" => "https://customer.websitecare.dk/",
            "design" => "1daff95a41edbd3b57dedbb140841eed89d6dc27fac129bd0bbd0440e1217ceceb803d65b7165ab0ad3075ba7cd65e61897e70e2700e26f428c1d6b859a42506",
            "testmode" => true,
            "info" => [
                "name" => $contactperson,
                "email" => $eMail,
            ]
        ];

        $token = get_option($this->option_name, '');
        $api = new OnpayApi($token);
        $responseRaw = $api->call('payment/create', 'POST', $data);
        $response = json_decode($responseRaw["response"]);

        // Link payment data to subscription
        /*
        Example response: 
        
                {
          "data": {
            "payment_uuid": "dfe8bf50-aaaa-11e7-898d-be9d7bb73511",
            "amount": 12000,
            "currency_code": "980",
            "expiration": "2022-03-02 11:12:14",
            "language": "en",
            "method": "card",
          }
          "links": {
            "payment_window": "https://onpay.io/window/v3/dfe8bf50-aaaa-11e7-898d-be9d7bb73511"
          }
        }
        
        */
        update_post_meta($subscriptionId, 'paymentGatewayData', $response->data);
                    

        // Handle rror and payment window
        if (defined('subscriptions_debug') && subscriptions_debug) {
            error_log(print_r($response, true), 3, plugin_dir_path(__FILE__) . 'onpay_log.log');
        }

        $paymentWindow = $response->links->payment_window;
        header("Location: $paymentWindow");
        exit;
    }
}

SubscriptionsPlugin::get_instance();

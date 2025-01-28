<?php

class _4xd_WC_BKT_Payment_Gateway extends WC_Payment_Gateway
{
    private $logger = '';

    public $id;
    public string $version;
    public $icon;
    public $method_title;
    public $method_description;
    public string $debug_email;
    public array $available_countries;
    public array $available_currencies;
    public $supports;

    private string $merchant_id;
    private string $merchant_pass;
    private string $store_key;
    private string $transaction_type;
    private string $installment_count;
    private string $lang;
    private string $random_number;
    public $title;
    
    private string $post_url_3d;

    private string $response_url;
    public $description;
    public $enabled;
    private string $enable_logging;
    protected array $dataToSend;

    protected string $client_id;
    protected string $store_type;
    protected string $tran_type;
    protected string $amount;
    protected string $currency;

    public function __construct()
    {
        $this->version 		= WC_GATEWAY_BKT_VERSION;
        $this->id 			= 'bkt';
        $this->method_title	= __('Credit Card, BKT ( Banka Kombetare Tregetare )', 'woocommerce-bkt');

        $this->method_description = sprintf(
            __('BKT Gateway works by sending the user to %1$sBKT%2$s to enter their payment information.', 'woocommerce-bkt'),
            '<a href="https://bkt.com.al/">',
            '</a>'
        );

        $this->icon               	= WP_PLUGIN_URL . '/' . plugin_basename(dirname(__FILE__, 2)) . '/bkt_logo.png';
        $this->available_countries  = array('AL');
        $this->available_currencies = array('ALL', 'USD', 'EUR', 'TL');

        // Supported functionality
        $this->supports = array('products');
        $this->form_fields = include __DIR__ . '/_4xd_bkt_form_fields.php';

        $this->init_settings();

        $this->getOptions();

        add_action('woocommerce_api_wc_bkt_gateway', array($this, 'check_bank_response'));
        add_action('woocommerce_receipt_bkt', array($this, 'GenerateReceipt'));
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_filter('woocommerce_email_attachments', array($this, 'attach_invoice_pdf_to_email'), 300, 3);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_css') );
    }

    public function enqueue_css() {
        $plugin_url = plugin_dir_url( __FILE__ );
        wp_enqueue_style( 'bkt_style',  plugins_url('/css/bkt-style.css', __FILE__), false, '1.0.0', 'all');
    }

    protected function getOptions() : void
    {
        $this->currency             = $this->get_option('currency');
        $this->lang					= $this->get_option('lang');
        $this->random_number		= intval(microtime(true)*1000);
        $this->response_url	    	= add_query_arg('wc-api', 'WC_BKT_Gateway', home_url('/'));

        // Setup default merchant data.
        $this->client_id			= $this->get_option('client_id');
        $this->store_type			= $this->get_option('store_type');
        $this->merchant_id			= $this->get_option('merchant_id');
        $this->merchant_pass		= $this->get_option('merchant_pass');
        $this->store_key		    = $this->get_option('store_key');
        $this->transaction_type		= $this->get_option('transaction_type');

        $this->installment_count	= $this->get_option('installment_count');

        $this->debug_email        	= get_option('admin_email');

        $this->post_url_3d		= $this->get_option('post_url_3d');

        $this->title            	= $this->get_option('title');
        $this->description      	= $this->get_option('description');
        $this->enabled          	= $this->IsEnabled() ? 'yes' : 'no';
        $this->enable_logging   	= 'yes' === $this->get_option('enable_logging');
    }

    public function IsEnabled() : bool
    {
        $is_available_currency = in_array(get_woocommerce_currency(), $this->available_currencies, true);
        return $is_available_currency && $this->merchant_id && $this->merchant_pass;
    }

    public function GenerateReceipt($order) : void
    {
        echo '<p>' . __('Thank you for your order, please click the button below to pay with BKT.', 'woocommerce-bkt') . '</p>';
        echo $this->generate_bkt_form($order);
    }

    /**
     * @param $order_id
     * @return string
     */
    public function generate_bkt_form($order_id): string
    {
        $order = wc_get_order($order_id);

        $billToName = sprintf('%s %s', self::get_order_prop($order, 'billing_first_name'), self::get_order_prop($order, 'billing_last_name'));
        $billToCompany = self::get_order_prop($order, 'billing_company');

        $this->dataToSend = array(
            'clientid'                          => $this->client_id,
            'amount'                            => $order->get_total(),
            'okurl'                             => $this->response_url,
            'failUrl'					        => $this->response_url,
            'TranType'                          => $this->transaction_type,
            'Instalment'                        => (string) $this->installment_count,
            'callbackUrl'                       => $this->response_url,
            'currency'					        => $this->currency,
            'rnd'						        => $this->random_number,
            'storetype'                         => $this->store_type,
            'hashAlgorithm'                     => 'ver3',
            'lang'					            => $this->lang,
            // 'oid'                               => $order->get_order_number(),
            // 'hash'						        => $this->generate_hash($order->get_order_number(), $this->random_number, $billToName, $billToCompany),

            'BillToName'		                => $billToName,
            'Email'				                => self::get_order_prop($order, 'billing_email'),
            'tel'				                => self::get_order_prop($order, 'billing_phone'),
            'BillToCompany'		                => $billToCompany,
            'BillToStreet1'			            => self::get_order_prop($order, 'billing_address_1'),
            'BillToStateProv'				    => self::get_order_prop($order, 'billing_state'),
            'BillToCity'				        => self::get_order_prop($order, 'billing_city'),
            'BillToPostalCode'			        => self::get_order_prop($order, 'billing_postcode'),
            'BillToCountry'			            => self::get_order_prop($order, 'billing_country'),

            'ShipToName'		                => sprintf('%s %s', self::get_order_prop($order, 'shipping_first_name'), self::get_order_prop($order, 'shipping_last_name')),
            'ShipToCompany'		                => self::get_order_prop($order, 'shipping_company'),
            'ShipToStreet1'			            => self::get_order_prop($order, 'shipping_address_1'),
            'ShipToStateProv'				    => self::get_order_prop($order, 'shipping_state'),
            'ShipToCity'				        => self::get_order_prop($order, 'shipping_city'),
            'ShipToPostalCode'			        => self::get_order_prop($order, 'shipping_postcode'),
            'ShipToCountry'			            => self::get_order_prop($order, 'shipping_country'),

            'description' 			            => sprintf(__('New order from %s', 'woocommerce-bkt'), get_bloginfo('name')),

            'item_description' 			        => sprintf(__('New order from %s', 'woocommerce-bkt'), get_bloginfo('name')),
            'order_key'      			        => self::get_order_prop($order, 'order_key'),
            'script_version'      		        => 'WooCommerce/' . CWC_VERSION . '; ' . get_site_url(),
            'order_id'      			        => self::get_order_prop($order, 'id'),
            'source'           			        => 'WooCommerce_Bkt_Plugin_' . $this->version,
            'encoding'           			    => 'utf-8',
            'refreshtime'                       => 0
        );

        $this->dataToSend['hash'] = $this->generate_hash($order_id, $this->dataToSend);

        $_order_args = array();
        foreach ($this->dataToSend as $key => $value) {
            $_order_args[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
        }

        $returnHtml = '<form action="' . esc_url($this->post_url_3d) . '" method="post" id="payfast_payment_form">';
        $returnHtml .= implode('', $_order_args);
        $returnHtml .= '<input type="submit" class="button button-alt" id="submit_bkt_payment_form" value="' . __('Pay via BKT', 'woocommerce-bkt') . '" />';
        $returnHtml .= '<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce-bkt') . '</a>';
        $returnHtml .= $this->getScript();
        $returnHtml .= '</form>';
        return $returnHtml;
    }

    public function getScript(): string
    {
        return '<script type="text/javascript">
            jQuery(function(){
                jQuery("body").block(
                    {
                        message: "' . __('Thank you for your order. We are now redirecting you to BKT to make payment.', 'woocommerce-bkt') . '",
                        overlayCSS: {background: "#fff",opacity: 0.6},
                        css: {
                            padding:        20,
                            textAlign:      "center",
                            color:          "#555",
                            border:         "3px solid #aaa",
                            backgroundColor:"#fff",
                            cursor:         "wait"
                        }
                    }
                );
                jQuery( "#submit_bkt_payment_form" ).click();
            });
        </script>';
    }

    public static function get_order_prop($order, $prop)
    {
        if ($prop === 'order_total') {
            $getter = array($order, 'get_total');
        } else {
            $getter = array($order, 'get_' . $prop);
        }
        return is_callable($getter) ? $getter() : $order->{$prop};
    }

    /**
     * @param $order_id
     * @return array
     */
    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        return array(
            'result' 	 => 'success',
            'redirect'	 => $order->get_checkout_payment_url(true),
        );
    }

    public function logs($message): void
    {
        if ($this->enable_logging || $this->get_option('testmode') === 'yes' ) {
            if (empty($this->logger)) {
                $this->logger = wc_get_logger();
            }
            $this->logger->debug($message, array( 'source' => 'bkt-payment' ));
        }
    }

    public function check_bank_response()
    {
        $post = stripslashes_deep($_POST);
        $this->logs("\n" . '----------' . "\n" . 'BKT call received');
        $this->logs('BKT Data: ' . print_r($post, true));
        $redirectUrl   = home_url('/');

        $orderId       = absint($post['order_id']);
        $order          = wc_get_order($orderId);

        if (!$order || false === $post) {
            $this->logs(__('Bad access of page', 'woocommerce-bkt'));
            wp_redirect($redirectUrl);
            die;
        }

        if ($post['mdStatus'] !== '1') {
            $this->logs(__('3D User Authentication Failed', 'woocommerce-bkt'));
        }

        if ($post['ProcReturnCode'] == '00') {
            $this->logs(__('Payment completed', 'woocommerce-bkt'));

            // Add order meta
            update_post_meta($orderId, '_bkt_status', 'Approved');
            update_post_meta($orderId, '_bkt_transaction_auth_id', $post['AuthCode']);
            update_post_meta($orderId, '_bkt_transaction_id', $post['TransId']);
            update_post_meta($orderId, '_bkt_transaction_card_type', $post['EXTRA_CARDBRAND']);
            update_post_meta($orderId, '_bkt_card_mask', $post['MaskedPan']);
            update_post_meta($orderId, '_bkt_transaction_date', $post['EXTRA_TRXDATE']);
            
            update_post_meta($orderId, '_bkt_transaction_type', $post['TranType']);

			//Sometimes the confirmation mail can be send before the order confirmations from wp
            $order->set_new_order_email_sent(false);
			$order->payment_complete();
			//$order->add_order_note(__('Payment completed', 'woocommerce-bkt'), true);
        } else {
            $message = $this->get_bank_error_message($post['ProcReturnCode']);

            $this->logs($message);
            $order->add_order_note($message);

            if (!$this->is_application_error($post['ProcReturnCode'])) {
                $order->add_order_note($message, true);
            }

            $order->update_status('failed', $message);
        }
        $redirectUrl = $this->get_return_url($order);

        wp_redirect($redirectUrl);
        die;
    }

    public function is_application_error($error_code): bool
    {
        if (array_key_exists($error_code,  _4xd_WC_BKT_Errors::application_error_codes())) {
            return true;
        }
        return false;
    }

    public function get_bank_error_message($error_code)
    {
        $error_message = _4xd_WC_BKT_Errors::bkt_response_codes($error_code);
        if ($error_message && is_string($error_message)) {
            return $error_message;
        }

        $error_message =  _4xd_WC_BKT_Errors::application_error_codes($error_code);
        if ($error_message && is_string($error_message)) {
            return $error_message;
        }

        return false;
    }

    /**
     * @param $order_id
     * @return string|null
     */
    public function set_hash($order_id): ?string
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        $hash = implode('', [
            $this->client_id,
            $order->get_order_number(),
            $order->get_total(),
            $this->response_url,
            $this->response_url,
            $this->transaction_type,
            $this->installment_count,
            $this->random_number,
            $this->merchant_pass
        ]);

        return base64_encode(pack('H*', sha1($hash)));
    }
    
    /**
     * @param $order_id
     * @return string|null
     */
    public function generate_hash($order_id, $data): ?string
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }

        // $hash = implode('', [
        //     $this->client_id,
        //     $order->get_order_number(),
        //     $order->get_total(),
        //     $this->response_url,
        //     $this->response_url,
        //     $this->transaction_type,
        //     $this->installment_count,
        //     $random_number,
        //     $this->store_key
        // ]);

        // $data = [
        //     'clientid' => $this->client_id,
        //     'amount' => $order->get_total(),
        //     'okurl' => $this->response_url,
        //     'failUrl' => $this->response_url,
        //     'TranType' => $this->transaction_type,
        //     'Instalment' => $this->installment_count,
        //     'callbackUrl' => $this->response_url,
        //     'currency' => $this->currency,
        //     'rnd' => $this->random_number,
        //     'storetype' => $this->store_type,
        //     'hashAlgorithm' => 'ver3',
        //     'lang' => $this->lang,
        //     'BillToName' => $billToName,
        //     'BillToCompany' => $billToCompany,
        // ];
        
        //return $data;

        $postParams = array();
        foreach ($data as $key => $value){
            array_push($postParams, $key);
        }

        natcasesort($postParams);
			
        $hashval = "";					
        foreach ($postParams as $param){				
            $paramValue = $data[$param];
            $escapedParamValue = str_replace("|", "\\|", str_replace("\\", "\\\\", $paramValue));	
                
            $lowerParam = strtolower($param);
            if($lowerParam != "hash" && $lowerParam != "encoding" )	{
                $hashval = $hashval . $escapedParamValue . "|";
            }
        }

        $escapedStoreKey = str_replace("|", "\\|", str_replace("\\", "\\\\", $this->store_key));
        $hashval = $hashval . $escapedStoreKey;

        $calculatedHashValue = hash('sha512', $hashval);  
        return base64_encode(pack('H*',$calculatedHashValue));

        // $hash = implode('', [
        //     $this->client_id,
        //     $order->get_total(),
        //     $this->response_url,
        //     $this->response_url,
        //     $this->transaction_type,
        //     $this->installment_count,
        //     $this->response_url,
        //     $this->currency,
        //     $random_number,
        //     $order->get_order_number(),
        //     $this->store_key
        // ]);

        // return base64_encode(pack('H*', sha1($hash)));
        return base64_encode(pack('H*',sha1($hash)));
    }

    /**
     * @param $attachments
     * @param $type
     * @param $order
     * @return mixed
     */
    public function attach_invoice_pdf_to_email($attachments, $type, $order)
    {
        if (
            $order instanceof WC_Order
            && in_array($type, ['customer_processing_order', 'customer_invoice', 'new_order', 'customer_completed_order'])
            && $order->get_payment_method() === $this->id
        ) {
            $invoice = (new _4xd_WC_BKT_Invoice($order))->get_order_invoice();
            $attachments[] = $invoice['path'];
        }

        return $attachments;
    }
}
<?php

if (!defined('ABSPATH')) {
    exit;
}

use Dompdf\Dompdf;

class _4xd_WC_BKT_Invoice
{
    private $order;

    public function __construct(WC_Order $order)
    {
        $this->order = $order;
    }

    /**
     * @return array|mixed|null
     */
    public function get_order_invoice()
    {
        if(!get_post_meta( $this->order->get_order_number(), '_bkt_transaction_auth_id', true )){
		    return null;
	    }

        if ($this->is_saved() && $this->is_uploaded()) {
            return $this->get_invoice_from_order();
        }

        return $this->upload_and_save_to_the_order();
    }

    /**
     * @return mixed|null
     */
    public function get_invoice_from_order()
    {
        if ($bkt_invoice = $this->order->get_meta('bkt_invoice')) {
            try {
                $bkt_invoice_array = json_decode($bkt_invoice, true, 512, JSON_THROW_ON_ERROR);
                if ($bkt_invoice_array) {
                    return $bkt_invoice_array;
                }
            } catch (JsonException $e) {
                return null;
            }
        }
        return null;
    }

    /**
     * @return array
     */
    public function upload_and_save_to_the_order(): array
    {
        $invoice = $this->upload();
        try {
            $this->order->update_meta_data('bkt_invoice', json_encode($invoice, JSON_THROW_ON_ERROR));
        } catch (JsonException $e) {
        }
        $this->order->update_meta_data('bkt_invoice_url', $invoice['url']);
        $this->order->save();
        return $invoice;
    }

    /**
     * @return array
     */
    public function upload() : array
    {
        $this->add_fix_url_for_wp_stateless_filter();

        $upload_dir       = wp_get_upload_dir();
        $invoice_basedir  = $upload_dir['basedir'] . '/bkt_invoice';
        $invoice_baseurl  = $upload_dir['baseurl'] . '/bkt_invoice';
        $invoice_filename = $this->fileName();

        $invoice = [
            'url'  => trailingslashit($invoice_baseurl) . $invoice_filename,
            'path' => trailingslashit($invoice_basedir) . $invoice_filename,
            'name' => $invoice_filename,
        ];

        if (wp_mkdir_p($invoice_basedir) && !file_exists($invoice['path'])) {
            $file_handle = @fopen($invoice['path'], 'wb');
            if ($file_handle) {
                fwrite($file_handle, $this->pdf());
                fclose($file_handle);
            }
        }

        $this->remove_fix_url_for_wp_stateless_filter();

        return $invoice;
    }

    /**
     * @return string|null
     */
    public function pdf(): ?string
    {
        $dompdf = new Dompdf();
        $dompdf->loadHtml($this->html());
        $dompdf->setPaper('A4');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * @return string
     */
    public function html(): string
    {
        return wc_get_template_html(
            'order/bkt-invoice.php',
            [
                'order'               => $this->order,
                'order_id'            => $this->order->get_order_number(),
                'settings'            => (object) get_option('woocommerce_bkt_settings', false),
            ],
            '',
            WC_GATEWAY_BKT_PLUGIN_PATH . '/templates/'
        );
    }

    /**
     * @return bool
     */
    private function is_uploaded() : bool
    {
        $this->add_fix_url_for_wp_stateless_filter();
        $upload_dir       = wp_get_upload_dir();
        $this->remove_fix_url_for_wp_stateless_filter();
        $invoicePath = trailingslashit($upload_dir['basedir'] . '/bkt_invoice') . $this->fileName();

        return file_exists($invoicePath);
    }

    /**
     * @return string
     */
    public function fileName(): string
    {
        if (defined('NONCE_SALT')) {
            $invoice_salt = NONCE_SALT;
        } else {
            if($this->order->get_date_created()){
                $string = $this->order->get_date_created()->getTimestamp() . ":" . $this->order->get_billing_email();
            } else {
                $string = $this->order->get_id() . ":" . $this->order->get_billing_email();
            }
            $invoice_salt = md5($string);
        }

        return sprintf('invoice_%s.pdf', sha1($this->order->get_id() . $invoice_salt));
    }

    /**
     * @return bool
     */
    private function is_saved() : bool
    {
        if ($this->get_invoice_from_order()) {
            return true;
        }
        return false;
    }

    private function add_fix_url_for_wp_stateless_filter(): void
    {
        add_filter('wp_stateless_handle_root_dir', [$this, 'fix_url_for_wp_stateless_filter']);
    }

    private function remove_fix_url_for_wp_stateless_filter(): void
    {
        remove_filter('wp_stateless_handle_root_dir', [$this, 'fix_url_for_wp_stateless_filter']);
    }

    public function fix_url_for_wp_stateless_filter($root_dir) : string
    {
        if (!is_plugin_active('wp-stateless/wp-stateless-media.php')) {
            return $root_dir;
        }

        if (!function_exists('ud_get_stateless_media')) {
            return $root_dir;
        }

        $wildcard_year_month = '%date_year/date_month%';
        $use_year_month = (strpos(ud_get_stateless_media()->get('sm.root_dir'), $wildcard_year_month) !== false) ?: false;
        if ($use_year_month) {
            $root_dir = trim(str_replace(date('Y') . '/' . date('m'), '', $root_dir), '/');
        }

        return $root_dir;
    }
}
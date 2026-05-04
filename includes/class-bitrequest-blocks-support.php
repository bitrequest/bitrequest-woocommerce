<?php
/**
 * Block-checkout integration for Bitrequest.
 *
 * The classic [woocommerce_checkout] shortcode picks up any WC_Payment_Gateway
 * automatically. The React-based Cart/Checkout blocks introduced in WC Blocks
 * use a separate registry (PaymentMethodTypeRegistry) and require gateways to
 * additionally register a "payment method type" — a thin JS module that tells
 * the block what to render in the payment step, plus a PHP shim that decides
 * whether to expose it.
 *
 * For Bitrequest specifically the block-side UI is just label + description:
 * the customer clicks Place Order, lands on the order-pay page, and the real
 * coin-picker flow takes over from there. So this class stays lean.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
    return;
}

final class Bitrequest_Blocks_Support extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {

    /** Must match WC_Gateway_Bitrequest::$id and the gateway's option key. */
    protected $name = 'bitrequest';

    public function initialize() {
        $this->settings = get_option( 'woocommerce_bitrequest_settings', [] );
    }

    /** Whether to expose Bitrequest in the block checkout for this request. */
    public function is_active() {
        return ! empty( $this->settings['enabled'] ) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles() {
        $handle    = 'bitrequest-blocks-integration';
        $rel_path  = 'assets/js/bitrequest-blocks.js';
        $abs_path  = BITREQUEST_WC_PATH . $rel_path;
        $url       = BITREQUEST_WC_URL  . $rel_path;
        $version   = file_exists( $abs_path ) ? (string) filemtime( $abs_path ) : '1.0.0';

        wp_register_script(
            $handle,
            $url,
            [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities' ],
            $version,
            true
        );

        return [ $handle ];
    }

    /**
     * Data passed through to the JS via `wc.wcSettings.getSetting('bitrequest_data')`.
     * Title and description are the two settings the block-side renderer needs;
     * everything else (coin configs, secrets, xpubs) is loaded later on the
     * order-pay page where the real flow lives.
     */
    public function get_payment_method_data() {
        return [
            'title'       => $this->get_setting( 'title',       'Cryptocurrency' ),
            'description' => $this->get_setting( 'description', 'Pay with cryptocurrency via Bitrequest.' ),
            'icon'        => BITREQUEST_WC_URL . 'assets/img/bitrequest-icon.png',
            'supports'    => [ 'products' ],
        ];
    }
}

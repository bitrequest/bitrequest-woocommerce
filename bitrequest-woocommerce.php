<?php
/**
 * Plugin Name: Bitrequest for WooCommerce
 * Plugin URI:  https://github.com/bitrequest/bitrequest-woocommerce
 * Description: Accept cryptocurrency payments via Bitrequest. Non-custodial, multi-coin, HD wallet (xpub) support with frontend derivation.
 * Version:     0.1.0
 * Author:      Bitrequest
 * Author URI:  https://bitrequest.io
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: bitrequest-woocommerce
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 10.7.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BITREQUEST_WC_VERSION', '0.1.0' );
define( 'BITREQUEST_WC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BITREQUEST_WC_URL', plugin_dir_url( __FILE__ ) );

// ─── HPOS + Cart/Checkout blocks compatibility ────────────────────────────────
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
    }
} );

// ─── Bootstrap ────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'bitrequest_wc_init' );

function bitrequest_wc_init() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="error"><p><strong>Bitrequest for WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
        } );
        return;
    }
    require_once BITREQUEST_WC_PATH . 'includes/class-bitrequest-gateway.php';
    add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
        $gateways[] = 'WC_Gateway_Bitrequest';
        return $gateways;
    } );
}

// ─── Cart/Checkout blocks: register payment method type ───────────────────────
// Without this, the React-based Checkout block hides the gateway with
// "There are no payment methods available". Classic shortcode checkout is
// unaffected — both registrations coexist.
add_action( 'woocommerce_blocks_loaded', function () {
    if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }
    require_once BITREQUEST_WC_PATH . 'includes/class-bitrequest-blocks-support.php';
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ( $registry ) {
            $registry->register( new Bitrequest_Blocks_Support() );
        }
    );
} );

// ─── AJAX: verify payment + store all txdata ─────────────────────────────────
add_action( 'wp_ajax_bitrequest_verify_tx',        'bitrequest_handle_verify_tx' );
add_action( 'wp_ajax_nopriv_bitrequest_verify_tx', 'bitrequest_handle_verify_tx' );

// ─── AJAX: claim a per-coin checkout lock at click time ──────────────────────
// Frontend calls this before opening the Bitrequest iframe so single-address
// coins (Lightning excluded — uses unique invoices; ETH-family and Monero
// always lock; xpub-rotation coins skip via not_needed) don't get reserved
// just because the customer is browsing the dropdown.
add_action( 'wp_ajax_bitrequest_acquire_lock',        'bitrequest_handle_acquire_lock' );
add_action( 'wp_ajax_nopriv_bitrequest_acquire_lock', 'bitrequest_handle_acquire_lock' );

// ─── AJAX: release the lock when the customer closes the iframe ──────────────
// Fired from the wrapped window.closeframe in checkout.js, but only when the
// payment hasn't been submitted (verify_tx releases on success). Lets the
// next customer pick that coin immediately instead of waiting 15 min.
add_action( 'wp_ajax_bitrequest_release_lock',        'bitrequest_handle_release_lock' );
add_action( 'wp_ajax_nopriv_bitrequest_release_lock', 'bitrequest_handle_release_lock' );

// ─── AJAX: autosave the coin config table on every change (admin only) ───────
// Fires on input change / blur / row add / row delete in the admin settings
// page. Backed by the same sanitization the regular Save button uses, so the
// two paths can never disagree on shape.
add_action( 'wp_ajax_bitrequest_save_coin_configs', 'bitrequest_handle_save_coin_configs' );

// ─── AJAX: query the Lightning proxy for invoice status ──────────────────────
// Admin-only manual lookup, triggered by a button in the order meta box.
// Bitrequest's Lightning flow already routes through the merchant's own
// proxy (https://github.com/bitrequest/bitrequest.github.io/tree/master/proxy),
// so we POST `fn=ln-invoice-status` to the proxy and surface the response
// inline. The proxy URL is snapshotted on the order at verify_tx time, so
// this works on historical orders even if the merchant later swaps proxies.
add_action( 'wp_ajax_bitrequest_check_ln_status', 'bitrequest_handle_check_ln_status' );

// ─── Release checkout locks when an order is cancelled/failed ────────────────
add_action( 'woocommerce_order_status_cancelled', 'bitrequest_release_locks_on_cancel' );
add_action( 'woocommerce_order_status_failed',    'bitrequest_release_locks_on_cancel' );
add_action( 'woocommerce_order_status_trash',      'bitrequest_release_locks_on_cancel' );
add_action( 'wp_trash_post',                       'bitrequest_release_locks_on_trash' );

function bitrequest_release_locks_on_cancel( $order_id ) {
    $gw = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    if ( ! $gw ) return;
    $configs = $gw->get_coin_configs();
    foreach ( array_keys( WC_Gateway_Bitrequest::coin_defs() ) as $coin ) {
        $xpub = $configs[ $coin ]['xpub'] ?? '';
        if ( WC_Gateway_Bitrequest::needs_checkout_lock( $coin, $xpub ) ) {
            $gw->release_checkout_lock( $coin, $order_id );
        }
    }
}

// wp_trash_post fires for any post type — only act on WC orders
function bitrequest_release_locks_on_trash( $post_id ) {
    $order = wc_get_order( $post_id );
    if ( ! $order || $order->get_payment_method() !== 'bitrequest' ) return;
    bitrequest_release_locks_on_cancel( $post_id );
}

function bitrequest_handle_verify_tx() {
    check_ajax_referer( 'bitrequest_checkout', 'nonce' );

    $order_id        = absint( $_POST['order_id'] ?? 0 );
    $provided_secret = sanitize_text_field( $_POST['payment_secret'] ?? '' );
    $txhash          = sanitize_text_field( $_POST['txhash'] ?? '' );

    if ( ! $order_id || ! $txhash ) wp_send_json_error( [ 'message' => 'Missing parameters.' ] );

    $order = wc_get_order( $order_id );
    if ( ! $order ) wp_send_json_error( [ 'message' => 'Order not found.' ] );
    if ( $order->is_paid() ) wp_send_json_success( [ 'status' => 'already_paid' ] );

    // ── Authenticate with per-order secret ───────────────────────────────────
    $stored_secret = $order->get_meta( '_bitrequest_payment_secret' );
    if ( ! $stored_secret || ! hash_equals( $stored_secret, $provided_secret ) ) {
        wp_send_json_error( [ 'message' => 'Invalid payment secret.' ] );
    }

    $gw      = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    $configs = $gw ? $gw->get_coin_configs() : [];

    // ── Security: lock coin — use stored value, reject if client tries to
    //    downgrade to a non-verifiable coin ────────────────────────────────────
    $client_coin  = sanitize_text_field( $_POST['coin'] ?? '' );
    $stored_coin  = $order->get_meta( '_bitrequest_payment' ) ?: '';
    $coin         = $stored_coin ?: $client_coin;

    // ── Security: coin must be a known/configured row ───────────────────────
    // Defence in depth — an iframe trying to verify with an unrecognised coin
    // string has nothing legitimate to say. Checked against configs (not
    // enabled list) so we don't reject legitimate retries if the merchant
    // disabled the coin between order placement and payment.
    if ( ! $coin || ! isset( $configs[ $coin ] ) ) {
        $order->add_order_note( "Bitrequest: rejected verify_tx — unknown coin '{$client_coin}'." );
        wp_send_json_error( [ 'message' => 'Coin not configured.' ] );
    }

    // ── Security: txhash must match the format expected for this coin ───────
    // Catches malformed or spoofed values before the lock is released or any
    // order meta is written. Polling-coin synthesized fallback (<a>|<id>) is
    // accepted; see WC_Gateway_Bitrequest::valid_txhash().
    if ( ! WC_Gateway_Bitrequest::valid_txhash( $coin, $txhash ) ) {
        $order->add_order_note( "Bitrequest: rejected verify_tx — invalid txhash format for {$coin}: '{$txhash}'." );
        wp_send_json_error( [ 'message' => 'Invalid transaction hash format.' ] );
    }

    // ── Security: txhash must not already be linked to a different order ────
    // Blocks credit-the-same-payment-to-multiple-orders attempts. Skip for the
    // synthesized polling format since those carry a unique requestid by
    // construction and a string match across pseudo-hashes is meaningless.
    if ( strpos( $txhash, '|' ) === false ) {
        $existing = wc_get_orders( [
            'meta_key'   => '_bitrequest_txhash',
            'meta_value' => $txhash,
            'limit'      => 1,
            'exclude'    => [ $order_id ],
            'return'     => 'ids',
        ] );
        if ( ! empty( $existing ) ) {
            $other_id = (int) $existing[0];
            $order->add_order_note( "Bitrequest: rejected verify_tx — txhash {$txhash} already used by order #{$other_id}." );
            wp_send_json_error( [ 'message' => 'This transaction has already been used for another order.' ] );
        }
    }

    // ── Security: lock address — never let client override a stored address ──
    $client_address = sanitize_text_field( $_POST['address'] ?? '' );
    $stored_address = $order->get_meta( '_bitrequest_address' ) ?: '';
    $address        = $stored_address; // default to stored

    if ( ! $stored_address && $client_address ) {
        // First time — validate the address against gateway config before storing
        $coin_config       = $configs[ $coin ] ?? [];
        $configured_static = $coin_config['address'] ?? '';
        $configured_xpub   = $coin_config['xpub']    ?? '';

        if ( $configured_static && ! $configured_xpub && $coin !== 'monero' ) {
            // Static address mode: client address MUST match configured address.
            // Skipped for Monero — integrated addresses don't string-match the base.
            // Real security for Monero comes from viewkey-based on-chain verification.
            if ( $client_address !== $configured_static ) {
                $order->add_order_note( "Security alert: address mismatch on verify_tx. Expected: {$configured_static}, received: {$client_address}" );
                wp_send_json_error( [ 'message' => 'Address mismatch.' ] );
            }
        }
        // For xpub mode we trust the derived address (client-side derivation is deterministic)
        // but we lock it now so it can't be changed on retry
        $address = $client_address;
    }

    // ── Security: sanity-check ccval against WC order total ──────────────────
    $client_ccval   = sanitize_text_field( $_POST['ccval'] ?? '' );
    $stored_ccval   = $order->get_meta( '_bitrequest_crypto_amount' ) ?: '';
    $ccval          = $stored_ccval ?: $client_ccval;
    $order_total    = (float) $order->get_total();

    // Strict: a payment notification with no positive received amount on a
    // non-zero order is meaningless — reject. The legitimate cron-retry path
    // doesn't go through this endpoint, so we don't need the previous
    // "fall back to stored value" leniency here.
    if ( $order_total > 0 && ( $client_ccval === '' || (float) $client_ccval <= 0 ) ) {
        $order->add_order_note( "Bitrequest: rejected verify_tx — empty/zero received amount (ccval='{$client_ccval}')." );
        wp_send_json_error( [ 'message' => 'Invalid payment amount.' ] );
    }

    // ── Store all txdata fields ───────────────────────────────────────────────
    $fields = [
        '_bitrequest_payment'         => $coin,
        '_bitrequest_txhash'          => $txhash,
        '_bitrequest_address'         => $address,
        '_bitrequest_crypto_amount'   => $ccval,
        '_bitrequest_fiat_amount'     => sanitize_text_field( $_POST['fiat_amount'] ?? '' ),
        '_bitrequest_received_amount' => sanitize_text_field( $_POST['received_amount'] ?? '' ),
        '_bitrequest_ccsymbol'        => sanitize_text_field( $_POST['ccsymbol'] ?? '' ),
        '_bitrequest_currency_name'   => sanitize_text_field( $_POST['currency_name'] ?? '' ),
        // L2 chain name snapshot — "polygon pos" / "arbitrum one" /
        // "binance smart chain" / "base", or empty for L1/non-EVM. Drives
        // the explorer URL routing for historical orders so they always
        // point at the chain the customer actually paid on.
        '_bitrequest_eth_layer2'      => sanitize_text_field( $_POST['eth_layer2'] ?? '' ),
        '_bitrequest_cmc_id'          => sanitize_text_field( $_POST['cmc_id'] ?? '' ),
        '_bitrequest_status'          => sanitize_text_field( $_POST['status'] ?? '' ),
        '_bitrequest_tx_confirmations'=> sanitize_text_field( $_POST['confirmations'] ?? '' ),
        '_bitrequest_tx_time'         => sanitize_text_field( $_POST['tx_time'] ?? '' ),
        '_bitrequest_request_id'      => sanitize_text_field( $_POST['request_id'] ?? '' ),
        '_bitrequest_pending'         => sanitize_text_field( $_POST['pending'] ?? '' ),
        '_bitrequest_payment_id'      => sanitize_text_field( $_POST['payment_id'] ?? '' ),
    ];

    foreach ( $fields as $key => $value ) {
        if ( $value !== '' ) $order->update_meta_data( $key, $value );
    }

    // Lightning: snapshot the proxy + implementation that handled this payment.
    // We save these at payment time rather than reading them at lookup time so
    // a later admin change of the Lightning settings doesn't break status
    // checks on historical orders. The proxy field returned by the iframe is
    // just a hostname; we keep the merchant's full configured URL as the
    // canonical lookup target.
    if ( $coin === 'lightning' && ! empty( $configs['lightning'] ) ) {
        $ln_cfg = $configs['lightning'];
        if ( ! empty( $ln_cfg['lnurl_proxy'] ) ) {
            $order->update_meta_data( '_bitrequest_ln_proxy', $ln_cfg['lnurl_proxy'] );
        }
        if ( ! empty( $ln_cfg['imp'] ) ) {
            $order->update_meta_data( '_bitrequest_ln_imp', $ln_cfg['imp'] );
        }
        // bolt11 invoice — sent by the iframe at txdata.lightning.invoice.
        // Store it so the merchant can copy it from the order screen for
        // manual verification (paste into a node UI, decode externally, etc).
        $bolt11 = sanitize_text_field( $_POST['bolt11'] ?? '' );
        if ( $bolt11 !== '' ) {
            $order->update_meta_data( '_bitrequest_ln_bolt11', $bolt11 );
        }
    }

    // Advance per-xpub index + record used address (only once per order)
    // Skipped for ETH-family — we don't rotate those addresses (see
    // WC_Gateway_Bitrequest::is_eth_family() docblock).
    $paid_address = sanitize_text_field( $_POST['address'] ?? '' );
    $is_eth_family = WC_Gateway_Bitrequest::is_eth_family( $coin );
    if ( $coin && ! $is_eth_family && ! empty( $configs[ $coin ]['xpub'] ) && $order->get_meta( '_bitrequest_xpub_index' ) === '' ) {
        // Check if index was pre-reserved at checkout page load
        $reserved = $order->get_meta( '_bitrequest_reserved_index_' . $coin );
        if ( $reserved !== '' && $reserved !== false ) {
            // Already incremented at reservation time — just record which index was used
            $order->update_meta_data( '_bitrequest_xpub_index', (int) $reserved );
        } else {
            // Legacy path (no reservation) — increment now
            $xpub   = $configs[ $coin ]['xpub'];
            $prefix = substr( $xpub, 0, 16 );
            $indices = $gw->get_xpub_indices();
            $cur     = isset( $indices[ $coin ][ $prefix ] ) ? (int) $indices[ $coin ][ $prefix ] : (int) get_option( "bitrequest_xpub_index_{$coin}", 0 );
            $gw->update_xpub_index( $coin, $xpub, $cur + 1 );
            $order->update_meta_data( '_bitrequest_xpub_index', $cur );
        }
    }
    // Record used address (regardless of xpub — prevents reuse on any future order).
    // Skipped for ETH-family: a single shared address is reused by design.
    if ( $paid_address && $coin && ! $is_eth_family && strpos( $paid_address, 'lnurl' ) === false ) {
        $gw->add_used_address( $coin, $paid_address );
    }

    // Release checkout lock for single-address coins
    $coin_xpub = $configs[ $coin ]['xpub'] ?? '';
    if ( $coin && WC_Gateway_Bitrequest::needs_checkout_lock( $coin, $coin_xpub ) ) {
        $gw->release_checkout_lock( $coin, $order->get_id() );
    }

    $order->save();

    // ── Manual verification path ──────────────────────────────────────────────
    // Earlier versions of the plugin auto-verified payments via BlockCypher /
    // Etherscan / etc. That was dropped in favour of the simpler "merchant
    // checks the explorer link" flow — every payment goes to on-hold and the
    // merchant marks it complete after eyeballing the chain. Lightning has
    // its own check-status button in the order meta box that hits the proxy.
    $order->update_status( 'on-hold', "Bitrequest: TX {$txhash} recorded — verify manually via the explorer link in the order panel." );
    bitrequest_send_webhook( $order, $fields );
    wp_send_json_success( [ 'status' => 'manual_review' ] );
}

// ─── Acquire-lock AJAX handler ────────────────────────────────────────────────
function bitrequest_handle_acquire_lock() {
    check_ajax_referer( 'bitrequest_checkout', 'nonce' );

    $order_id        = absint( $_POST['order_id'] ?? 0 );
    $provided_secret = sanitize_text_field( $_POST['payment_secret'] ?? '' );
    $coin            = sanitize_text_field( $_POST['coin'] ?? '' );

    if ( ! $order_id || ! $coin ) wp_send_json_error( [ 'message' => 'Missing parameters.' ] );

    $order = wc_get_order( $order_id );
    if ( ! $order ) wp_send_json_error( [ 'message' => 'Order not found.' ] );
    if ( $order->is_paid() ) wp_send_json_success( [ 'status' => 'already_paid' ] );

    $stored_secret = $order->get_meta( '_bitrequest_payment_secret' );
    if ( ! $stored_secret || ! hash_equals( $stored_secret, $provided_secret ) ) {
        wp_send_json_error( [ 'message' => 'Invalid payment secret.' ] );
    }

    $gw = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    if ( ! $gw ) wp_send_json_error( [ 'message' => 'Gateway not available.' ] );

    // Reject coins that aren't enabled in the gateway settings — defence in depth.
    $enabled_coins = $gw->get_enabled_coins();
    if ( ! isset( $enabled_coins[ $coin ] ) ) {
        wp_send_json_error( [ 'message' => 'That cryptocurrency is not enabled.' ] );
    }

    $cfg  = $enabled_coins[ $coin ];
    $xpub = $cfg['xpub'] ?? '';

    // Coin doesn't need a lock (xpub-rotation, lightning) — proceed.
    if ( ! WC_Gateway_Bitrequest::needs_checkout_lock( $coin, $xpub ) ) {
        wp_send_json_success( [ 'status' => 'not_needed' ] );
    }

    // Customer may have switched coins mid-checkout. Release any other
    // single-address locks this order holds before claiming the new one.
    $configs = $gw->get_coin_configs();
    foreach ( array_keys( $configs ) as $other_coin ) {
        if ( $other_coin === $coin ) continue;
        $other_xpub = $configs[ $other_coin ]['xpub'] ?? '';
        if ( WC_Gateway_Bitrequest::needs_checkout_lock( $other_coin, $other_xpub ) ) {
            $gw->release_checkout_lock( $other_coin, $order_id );
        }
    }

    if ( $gw->try_lock_checkout( $coin, $order_id ) ) {
        wp_send_json_success( [ 'status' => 'acquired' ] );
    }

    wp_send_json_error( [ 'message' => 'That cryptocurrency is currently being paid by another customer. Please choose a different one.' ] );
}

// ─── Release-lock AJAX handler ────────────────────────────────────────────────
function bitrequest_handle_release_lock() {
    check_ajax_referer( 'bitrequest_checkout', 'nonce' );

    $order_id        = absint( $_POST['order_id'] ?? 0 );
    $provided_secret = sanitize_text_field( $_POST['payment_secret'] ?? '' );
    $coin            = sanitize_text_field( $_POST['coin'] ?? '' );

    if ( ! $order_id || ! $coin ) wp_send_json_error( [ 'message' => 'Missing parameters.' ] );

    $order = wc_get_order( $order_id );
    if ( ! $order ) wp_send_json_error( [ 'message' => 'Order not found.' ] );

    $stored_secret = $order->get_meta( '_bitrequest_payment_secret' );
    if ( ! $stored_secret || ! hash_equals( $stored_secret, $provided_secret ) ) {
        wp_send_json_error( [ 'message' => 'Invalid payment secret.' ] );
    }

    $gw = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    if ( ! $gw ) wp_send_json_error( [ 'message' => 'Gateway not available.' ] );

    // release_checkout_lock is idempotent and ownership-checked — safe to call
    // even if the lock has already been released or is held by another order.
    $gw->release_checkout_lock( $coin, $order_id );
    wp_send_json_success( [ 'status' => 'released' ] );
}

// ─── Coin-configs autosave handler ───────────────────────────────────────────
function bitrequest_handle_save_coin_configs() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    check_ajax_referer( 'bitrequest_admin', 'nonce' );

    $gw = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    if ( ! $gw ) wp_send_json_error( [ 'message' => 'Gateway not available.' ] );

    $raw = $_POST['br_coin'] ?? [];
    if ( ! is_array( $raw ) ) {
        wp_send_json_error( [ 'message' => 'Invalid payload.' ] );
    }

    update_option( 'bitrequest_coin_configs', $gw->sanitize_coin_configs_payload( $raw ) );

    // Top-level gateway toggles that ride along with the coin autosave (so
    // the merchant doesn't have to hit the WC Save button after toggling
    // them). Currently: show_qr. Each is read independently so absence of a
    // key just leaves the existing value untouched.
    if ( isset( $_POST['show_qr'] ) ) {
        $settings = get_option( 'woocommerce_bitrequest_settings', [] );
        if ( ! is_array( $settings ) ) $settings = [];
        $settings['show_qr'] = ( $_POST['show_qr'] === 'yes' ) ? 'yes' : 'no';
        update_option( 'woocommerce_bitrequest_settings', $settings );
    }

    wp_send_json_success( [ 'saved_at' => time() ] );
}

// ─── Lightning status check (merchant-initiated) ─────────────────────────────
function bitrequest_handle_check_ln_status() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
    }
    check_ajax_referer( 'bitrequest_admin', 'nonce' );

    $order_id = absint( $_POST['order_id'] ?? 0 );
    $order    = $order_id ? wc_get_order( $order_id ) : null;
    if ( ! $order ) wp_send_json_error( [ 'message' => 'Order not found.' ] );

    if ( $order->get_meta( '_bitrequest_payment' ) !== 'lightning' ) {
        wp_send_json_error( [ 'message' => 'Not a Lightning order.' ] );
    }

    $proxy = $order->get_meta( '_bitrequest_ln_proxy' );

    // Fallback for orders placed before this feature shipped — pick up the
    // current gateway settings. Will only work if the merchant hasn't swapped
    // their proxy since the order was paid.
    if ( ! $proxy ) {
        $gw = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
        if ( $gw ) {
            $cfg   = ( $gw->get_coin_configs() )['lightning'] ?? [];
            $proxy = $cfg['lnurl_proxy'] ?? '';
        }
    }
    if ( ! $proxy ) wp_send_json_error( [ 'message' => 'No Lightning proxy configured for this order.' ] );

    // Normalize the proxy URL the same way the PWA's proxyToLnurl() does:
    // if the merchant entered a bare host like "app.bitrequest.io/x", treat
    // it as https. The Lightning settings field in the gateway admin accepts
    // both forms, so we do too.
    $proxy = trim( $proxy );
    if ( ! preg_match( '#^https?://#i', $proxy ) ) {
        $proxy = 'https://' . $proxy;
    }

    // Lightning tracking pid — captured client-side in buildUrl from `lid`
    // when the checkout URL was built (the value the PWA derives
    // `lnd_payment_id` from in lightning_setup), then routed through
    // verify_tx as the regular `payment_id` field. The proxy keys its
    // tracking file on this exact value.
    $pid = $order->get_meta( '_bitrequest_payment_id' );
    if ( ! $pid ) wp_send_json_error( [ 'message' => 'No Lightning payment_id on this order.' ] );

    $endpoint = rtrim( $proxy, '/' ) . '/proxy/v1/ln/api/';
    if ( ! filter_var( $endpoint, FILTER_VALIDATE_URL ) ) {
        wp_send_json_error( [ 'message' => 'Invalid proxy URL: ' . esc_html( $proxy ) ] );
    }

    // Mirror the PWA's two-step flow from process_lightning_payment() in
    // assets_js_bitrequest_fetchblocks.js:
    //   1. ln-request-status — looks up the proxy's tracking file by pid,
    //      returns bolt11 + hash (and a status that can be stale because it
    //      depends on the proxy's tracking-file callback firing).
    //   2. ln-invoice-status — actually queries the Lightning implementation
    //      using the hash from step 1, returns the live authoritative status,
    //      amount_paid, conf, etc.
    // We always do both: step 1 gives us the hash we need, step 2 gives us
    // the truth. Spark invoices use `request_id` instead of `hash` in step 2.

    $rs_response = wp_remote_post( $endpoint, [
        'timeout' => 15,
        'body'    => [
            'fn' => 'ln-request-status',
            'id' => $pid,
        ],
    ] );

    if ( is_wp_error( $rs_response ) ) {
        wp_send_json_error( [ 'message' => 'Network error: ' . $rs_response->get_error_message() ] );
    }
    $rs_code = wp_remote_retrieve_response_code( $rs_response );
    $rs_body = wp_remote_retrieve_body( $rs_response );
    $rs_json = json_decode( $rs_body, true );

    if ( $rs_code < 200 || $rs_code >= 300 || ! is_array( $rs_json ) ) {
        wp_send_json_error( [
            'message' => 'Proxy returned HTTP ' . $rs_code . ' on ln-request-status.',
            'body'    => is_string( $rs_body ) ? mb_substr( $rs_body, 0, 500 ) : '',
        ] );
    }

    if ( ! empty( $rs_json['error'] ) ) {
        $err = is_string( $rs_json['error'] ) ? $rs_json['error']
            : ( $rs_json['error']['message'] ?? wp_json_encode( $rs_json['error'] ) );
        wp_send_json_error( [ 'message' => 'Proxy error (request-status): ' . $err ] );
    }

    // ── Step 2: live invoice status from the node ──────────────────────────
    $imp = $order->get_meta( '_bitrequest_ln_imp' );
    if ( ! $imp ) {
        $gw_inst = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
        if ( $gw_inst ) {
            $imp = ( $gw_inst->get_coin_configs() )['lightning']['imp'] ?? '';
        }
    }
    // Spark identifies invoices by request_id; everything else by hash.
    $invoice_lookup_hash = $rs_json['request_id'] ?? ( $rs_json['hash'] ?? '' );

    $is_data = null;
    if ( $imp && $invoice_lookup_hash ) {
        $is_response = wp_remote_post( $endpoint, [
            'timeout' => 15,
            'body'    => [
                'fn'   => 'ln-invoice-status',
                'imp'  => $imp,
                'hash' => $invoice_lookup_hash,
                'id'   => $pid,
            ],
        ] );
        if ( ! is_wp_error( $is_response ) ) {
            $is_code = wp_remote_retrieve_response_code( $is_response );
            $is_body = wp_remote_retrieve_body( $is_response );
            $is_json = json_decode( $is_body, true );
            if ( $is_code >= 200 && $is_code < 300 && is_array( $is_json ) && empty( $is_json['error'] ) ) {
                $is_data = $is_json;
            }
        }
        // If step 2 fails we don't bail — fall back to step 1 data so the
        // merchant still sees something.
    }

    // ── Side effects from the live invoice-status response ────────────────
    // When the proxy confirms the invoice as paid we:
    //   1. Sync the inline `_bitrequest_status` meta so the meta box's top
    //      Status row stops saying "pending".
    //   2. Auto-mark the WC order paid (payment_complete) when it's still in
    //      a pre-paid state. payment_complete is gated by the order's own
    //      status guard, so it's safe to call repeatedly, but we also gate
    //      manually so we don't spam the order-note log on every click.
    //   3. Backfill `_bitrequest_ln_bolt11` from the response when missing.
    //      Important for NWC orders — the iframe doesn't reliably populate
    //      txdata.lightning.invoice.bolt11 for NWC, but the proxy returns a
    //      real bolt11 in ln-invoice-status responses for every imp.
    $order_updated = false;
    if ( is_array( $is_data ) ) {
        // Backfill bolt11 if we don't have one stored (NWC, old orders, etc).
        $existing_bolt11 = $order->get_meta( '_bitrequest_ln_bolt11' );
        $resp_bolt11     = isset( $is_data['bolt11'] ) && is_string( $is_data['bolt11'] ) ? $is_data['bolt11'] : '';
        if ( ! $existing_bolt11 && $resp_bolt11 !== '' && strpos( $resp_bolt11, 'lnbc' ) === 0 ) {
            $order->update_meta_data( '_bitrequest_ln_bolt11', sanitize_text_field( $resp_bolt11 ) );
            $order->save();
        }

        $live_status = isset( $is_data['status'] ) ? strtolower( (string) $is_data['status'] ) : '';
        if ( $live_status === 'paid' ) {
            // Sync the inline status row in the meta box.
            if ( $order->get_meta( '_bitrequest_status' ) !== 'paid' ) {
                $order->update_meta_data( '_bitrequest_status', 'paid' );
                $order->save();
            }
            // Promote the order itself if it's still in a pre-paid state.
            if ( $order->has_status( [ 'pending', 'on-hold', 'failed' ] ) ) {
                $order->add_order_note( 'Bitrequest: Lightning invoice confirmed paid via proxy status check (ln-invoice-status). Order marked as paid.' );
                $order->payment_complete( $order->get_meta( '_bitrequest_txhash' ) ?: '' );
                $order_updated = true;
            }
        }
    }

    wp_send_json_success( [
        'endpoint'       => $endpoint,
        'pid'            => $pid,
        'imp'            => $imp,
        'data'           => $is_data ?: $rs_json,
        'source'         => $is_data ? 'ln-invoice-status' : 'ln-request-status',
        'request_status' => $rs_json,
        'order_updated'  => $order_updated,
    ] );
}

// ─── Webhook ─────────────────────────────────────────────────────────────────
function bitrequest_send_webhook( WC_Order $order, array $txdata ) {
    $gw = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    if ( ! $gw ) return;
    $url = trim( $gw->get_option( 'webhook_url', '' ) );
    if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) return;

    $payload = [
        'event'        => 'bitrequest_payment',
        'order_id'     => $order->get_id(),
        'order_number' => $order->get_order_number(),
        'order_status' => $order->get_status(),
        'order_total'  => $order->get_total(),
        'currency'     => $order->get_currency(),
        'txdata'       => array_combine(
            array_map( fn($k) => str_replace( '_bitrequest_', '', $k ), array_keys( $txdata ) ),
            array_values( $txdata )
        ),
    ];

    wp_remote_post( $url, [
        'body'        => wp_json_encode( $payload ),
        'headers'     => [ 'Content-Type' => 'application/json' ],
        'timeout'     => 10,
        'blocking'    => false, // fire and forget
    ] );
}

// ─── Cron cleanup ────────────────────────────────────────────────────────────
// Earlier versions ran an hourly cron that re-checked on-hold orders against
// BlockCypher / Etherscan. That was removed when on-chain auto-verification
// was dropped. Deactivation handler stays to clear the scheduled event from
// installs that were upgraded from a pre-cleanup version.
register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( 'bitrequest_verify_pending' );
} );

// ─── Admin meta box ───────────────────────────────────────────────────────────
add_action( 'add_meta_boxes', 'bitrequest_add_order_meta_box' );

// ─── Order edit page assets (Lightning status button) ────────────────────────
// Loaded narrowly: only on the WC order edit screen, never on the settings
// page (the bigger admin.js handles that). Localizes nonce + ajax_url so the
// status button can hit bitrequest_check_ln_status.
add_action( 'admin_enqueue_scripts', function ( $hook ) {
    // Cover both classic-post and HPOS edit screens.
    $is_order_screen = false;
    if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
        $is_order_screen = ( isset( $_GET['post'] ) && get_post_type( (int) $_GET['post'] ) === 'shop_order' )
                        || ( isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order' );
    } elseif ( strpos( $hook, 'wc-orders' ) !== false ) {
        $is_order_screen = true;
    }
    if ( ! $is_order_screen ) return;

    $rel = 'assets/js/bitrequest-order-admin.js';
    $abs = BITREQUEST_WC_PATH . $rel;
    wp_enqueue_script(
        'bitrequest-order-admin',
        BITREQUEST_WC_URL . $rel,
        [ 'jquery' ],
        file_exists( $abs ) ? (string) filemtime( $abs ) : BITREQUEST_WC_VERSION,
        true
    );
    // Per-order guidance — only attach when the screen actually shows a
    // Bitrequest order, otherwise BR_ORDER.bitrequest stays null and the
    // JS gate is a no-op for non-Bitrequest orders.
    $order_id = isset( $_GET['post'] ) ? (int) $_GET['post']
              : ( isset( $_GET['id'] ) ? (int) $_GET['id'] : 0 );
    $br_guidance = null;
    if ( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order && $order->get_payment_method() === 'bitrequest' ) {
            $coin    = $order->get_meta( '_bitrequest_payment' ) ?: '';
            $address = $order->get_meta( '_bitrequest_address' ) ?: '';
            $br_guidance = [
                'order_id'                  => $order_id,
                'coin'                      => $coin,
                'address'                   => $address,
                'recommended_confirmations' => $coin
                    ? WC_Gateway_Bitrequest::recommended_confirmations( $coin )
                    : 1,
                // verified is set by the merchant clicking OK in the gate
                // dialog. Persisted as a meta timestamp so subsequent page
                // loads also skip the dialog. Reset whenever the order
                // moves downstream of processing — see status-changed hook
                // below for the reset trigger list.
                'verified'                  => (bool) $order->get_meta( '_bitrequest_verified_at' ),
            ];
        }
    }

    wp_localize_script( 'bitrequest-order-admin', 'BR_ORDER', [
        'ajax_url'   => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'bitrequest_admin' ),
        'bitrequest' => $br_guidance,
    ] );
} );

// ─── Verified-state lifecycle ─────────────────────────────────────────────────
// The merchant's confirmation in the gate dialog is recorded as the
// `_bitrequest_verified_at` meta timestamp so we don't re-prompt on every
// upstream move. The reset hook below clears it whenever the order moves
// downstream so re-verification is required if the order returns to a
// fulfillment state later.

add_action( 'wp_ajax_bitrequest_mark_verified', 'bitrequest_handle_mark_verified' );

function bitrequest_handle_mark_verified() {
    check_ajax_referer( 'bitrequest_admin', 'nonce' );
    if ( ! current_user_can( 'edit_shop_orders' ) ) {
        wp_send_json_error( [ 'message' => 'Permission denied.' ], 403 );
    }
    $order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
    $order    = $order_id ? wc_get_order( $order_id ) : null;
    if ( ! $order || $order->get_payment_method() !== 'bitrequest' ) {
        wp_send_json_error( [ 'message' => 'Invalid order.' ], 400 );
    }
    $order->update_meta_data( '_bitrequest_verified_at', time() );
    $order->save();
    wp_send_json_success();
}

// Reset the verified flag whenever the order moves to a status that
// implies the fulfillment commitment is being undone or hasn't happened
// yet. Re-entering processing / completed from any of these will
// re-trigger the gate dialog.
add_action( 'woocommerce_order_status_changed', 'bitrequest_reset_verified_on_downstream', 10, 4 );

function bitrequest_reset_verified_on_downstream( $order_id, $old_status, $new_status, $order ) {
    if ( ! $order || $order->get_payment_method() !== 'bitrequest' ) return;
    $reset_statuses = [ 'pending', 'on-hold', 'failed', 'cancelled', 'refunded' ];
    if ( in_array( $new_status, $reset_statuses, true ) ) {
        $order->delete_meta_data( '_bitrequest_verified_at' );
        $order->save();
    }
}

function bitrequest_add_order_meta_box() {
    $screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id( 'shop-order' )
        : 'shop_order';
    add_meta_box( 'bitrequest_order_data', 'Bitrequest', 'bitrequest_render_order_meta_box', $screen, 'side', 'high' );
}

function bitrequest_render_order_meta_box( $post_or_order ) {
    $order = ( $post_or_order instanceof WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
    if ( ! $order || $order->get_payment_method() !== 'bitrequest' ) {
        echo '<p style="color:#999;font-size:12px">Not a Bitrequest order.</p>'; return;
    }

    $coin      = $order->get_meta( '_bitrequest_payment' ) ?: '';
    $symbol    = $order->get_meta( '_bitrequest_ccsymbol' ) ?: strtoupper( $coin );
    $txhash    = $order->get_meta( '_bitrequest_txhash' ) ?: '';
    $address   = $order->get_meta( '_bitrequest_address' ) ?: '';
    $ccval     = $order->get_meta( '_bitrequest_crypto_amount' ) ?: '';
    $fiat      = $order->get_meta( '_bitrequest_fiat_amount' ) ?: '';
    $status    = $order->get_meta( '_bitrequest_status' ) ?: '';
    $confs     = $order->get_meta( '_bitrequest_tx_confirmations' ) ?: '';
    $req_confs = WC_Gateway_Bitrequest::recommended_confirmations( $coin );
    $tx_time   = $order->get_meta( '_bitrequest_tx_time' ) ?: '';
    $currency  = $order->get_meta( '_bitrequest_currency_name' ) ?: '';
    $cmc_id    = $order->get_meta( '_bitrequest_cmc_id' ) ?: '';
    $req_id    = $order->get_meta( '_bitrequest_request_id' ) ?: '';
    $pid       = $order->get_meta( '_bitrequest_payment_id' ) ?: '';
    $ln_imp    = $order->get_meta( '_bitrequest_ln_imp' ) ?: '';
    $xi        = $order->get_meta( '_bitrequest_xpub_index' );
    $secret    = ! empty( $order->get_meta( '_bitrequest_payment_secret' ) );
    $eth_layer2    = $order->get_meta( '_bitrequest_eth_layer2' ) ?: '';
    $explorer_urls = $txhash ? WC_Gateway_Bitrequest::explorer_urls( $coin, $txhash, $eth_layer2 ) : [];

    $defs   = WC_Gateway_Bitrequest::coin_defs();
    $cmc    = $cmc_id ?: ( $defs[$coin][2] ?? 0 );
    $icon   = $cmc ? "<img src='https://s2.coinmarketcap.com/static/img/coins/64x64/{$cmc}.png' style='width:18px;height:18px;border-radius:50%;vertical-align:middle;margin-right:4px'>" : '';

    // Format timestamp
    $tx_time_fmt = '';
    if ( $tx_time ) {
        $ts = strlen( $tx_time ) > 10 ? (int) substr( $tx_time, 0, -3 ) : (int) $tx_time;
        $tx_time_fmt = date_i18n( 'd M Y H:i', $ts );
    }

    $s = '<table style="width:100%;font-size:12px;border-collapse:collapse">';
    $row = function( $label, $value, $mono = false, $cls = '' ) use ( &$s ) {
        if ( $value === '' || $value === null ) return;
        $cell = $mono ? "<code style='font-size:10px;word-break:break-all'>" . esc_html($value) . "</code>" : esc_html($value);
        $cls_attr = $cls ? ' class="' . esc_attr($cls) . '"' : '';
        $s .= "<tr style='border-bottom:1px solid #f0f0f0'>
            <td style='padding:4px 4px 4px 0;color:#666;white-space:nowrap'>" . esc_html($label) . "</td>
            <td{$cls_attr} style='padding:4px 0;text-align:right'>{$cell}</td></tr>";
    };

    if ( $coin )     $s .= "<tr style='border-bottom:1px solid #f0f0f0'><td style='padding:4px 4px 4px 0;color:#666'>Coin</td><td style='padding:4px 0;text-align:right'>{$icon}" . esc_html( strtoupper($coin) ) . "</td></tr>";
    // Tag the Status value cell so JS can live-update it after a Lightning
    // status check (no full page reload needed to flip pending→paid).
    $row( 'Status',       $status, false, 'br-meta-status' );
    // Inline confirmation guidance — read from coin_defs() per-coin
    // recommended_confirmations field. 0 = instant-final (Lightning, Nano,
    // Dash with InstantSend) shows a green safe-to-fulfill note; >0 shows
    // an amber wait-for-N reminder. The same value drives the JS confirm
    // dialog gating order-status changes to processing/completed.
    //
    // Hidden once the order has moved past on-hold — the merchant has made
    // their fulfillment commitment, the reminder has done its job. Showing
    // it on a Processing/Completed order is post-decision noise. The pre-
    // fulfillment statuses (pending / on-hold / failed) keep the guidance.
    $pre_fulfillment = in_array( $order->get_status(), [ 'pending', 'on-hold', 'failed' ], true );
    if ( $pre_fulfillment ) {
        $guidance_html = ( $req_confs === 0 )
            ? "<span style='color:#46b450'>✓ Instant — safe to fulfill</span>"
            : "<span style='color:#b06800'>⏳ Wait for {$req_confs} confirmation" . ( $req_confs === 1 ? '' : 's' ) . "</span>";
        $s .= "<tr style='border-bottom:1px solid #f0f0f0'><td style='padding:4px 4px 4px 0;color:#666'></td><td style='padding:4px 0;text-align:right;font-size:11px'>{$guidance_html}</td></tr>";
    }
    $row( 'Amount',       $ccval ? "{$ccval} " . strtoupper($symbol) : '' );
    $row( 'Fiat',         $fiat && $currency ? "{$fiat} {$currency}" : '' );
    $row( 'Confirms',     $confs !== '' ? "{$confs} / {$req_confs}" : '' );
    $row( 'Time',         $tx_time_fmt );
    if ( $xi !== '' && $xi !== false ) $row( 'xpub idx', (string)$xi );
    if ( $pid ) {
        $pid_label = ( $coin === 'monero' )    ? 'XMR pid'
                   : ( ( $coin === 'lightning' ) ? 'LN pid' : 'Payment ID' );
        $row( $pid_label, $pid, true );
    }
    if ( $ln_imp && $coin === 'lightning' ) $row( 'LN imp', $ln_imp );
    if ( $req_id ) $row( 'Request ID', $req_id );

    // Address (truncated, full on hover)
    if ( $address ) {
        $short = strlen($address) > 22 ? substr($address,0,10).'…'.substr($address,-6) : $address;
        $s .= "<tr style='border-bottom:1px solid #f0f0f0'><td style='padding:4px 4px 4px 0;color:#666'>Address</td>
            <td style='padding:4px 0;text-align:right'><span title='" . esc_attr($address) . "' style='cursor:default'>" . esc_html($short) . "</span></td></tr>";
    }
    $s .= '</table>';

    echo $s;

    // TX hash — full copyable field
    if ( $txhash ) {
        // For Lightning, strip the synthesized `lightning` prefix from the
        // displayed value — it's a marker we add for valid_txhash routing
        // and duplicate detection, not part of the actual payment hash. The
        // underlying meta keeps the prefix intact.
        $tx_display = ( $coin === 'lightning' && strpos( $txhash, 'lightning' ) === 0 )
                    ? substr( $txhash, 9 )
                    : $txhash;

        echo '<p style="margin:8px 0 2px;font-size:11px;color:#555">TX hash:</p>';
        echo '<input type="text" readonly value="' . esc_attr( $tx_display ) . '" onclick="this.select()" style="width:100%;font-size:10px;font-family:monospace">';
        // Lightning has no on-chain txid — coin_defs()['lightning'][3] is an
        // empty list so explorer_urls() returns [] and this loop is a no-op.
        // Each registered explorer renders as its own full-width button so
        // the merchant can cross-check a tx across multiple sources (e.g.
        // mempool.space + blockchair for Bitcoin).
        foreach ( $explorer_urls as $i => $exp ) {
            $margin = ( $i === 0 ) ? '6px' : '4px';
            echo '<p style="margin:' . $margin . ' 0 0"><a href="' . esc_url( $exp['url'] ) . '" target="_blank" rel="noopener" class="button button-small" title="' . esc_attr( $exp['url'] ) . '" style="width:100%;text-align:center">🔍 View on ' . esc_html( $exp['host'] ) . '</a></p>';
        }

        // Lightning: bolt11 invoice + status-lookup button.
        if ( $coin === 'lightning' ) {
            $bolt11 = $order->get_meta( '_bitrequest_ln_bolt11' );
            if ( $bolt11 ) {
                echo '<p style="margin:8px 0 2px;font-size:11px;color:#555">Bolt11 invoice:</p>';
                echo '<textarea readonly onclick="this.select()" rows="3" '
                   . 'style="width:100%;font-size:10px;font-family:monospace;resize:vertical;word-break:break-all">'
                   . esc_textarea( $bolt11 ) . '</textarea>';
            }

            // Only show the status button when we have enough info to actually
            // make the call — either a snapshot from verify_tx or a current
            // gateway config we can fall back on.
            $ln_proxy = $order->get_meta( '_bitrequest_ln_proxy' );
            if ( ! $ln_proxy ) {
                $gw_inst = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
                if ( $gw_inst ) {
                    $ln_proxy = ( $gw_inst->get_coin_configs() )['lightning']['lnurl_proxy'] ?? '';
                }
            }
            if ( $ln_proxy ) {
                echo '<p style="margin:6px 0 0">'
                   . '<a href="#" class="button button-small br-ln-status-btn" '
                   . 'data-order-id="' . esc_attr( $order->get_id() ) . '" '
                   . 'style="width:100%;text-align:center">⚡ Check Lightning status</a>'
                   . '</p>';
                echo '<div class="br-ln-status-result" style="display:none;margin-top:6px;padding:8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:3px;font-size:11px"></div>';
            }
        }
    }

    // Address — full copyable field
    if ( $address ) {
        echo '<p style="margin:8px 0 2px;font-size:11px;color:#555">Full address:</p>';
        echo '<input type="text" readonly value="' . esc_attr($address) . '" onclick="this.select()" style="width:100%;font-size:10px;font-family:monospace">';
    }

    echo '<p style="margin-top:8px;font-size:11px;color:' . ($secret ? '#008000' : '#cc0000') . '">'
       . ($secret ? '✓ Payment secret set' : '✗ Payment secret missing') . '</p>';
}

// ─── Admin: inject coin icons into payments OVERVIEW list (React-compatible) ──
add_action( 'admin_footer', 'bitrequest_inject_payment_page_icons' );

function bitrequest_inject_payment_page_icons() {
    // Only on the checkout overview tab — NOT on the individual gateway settings page
    if ( ( $_GET['page'] ?? '' ) !== 'wc-settings' ) return;
    if ( ( $_GET['tab'] ?? '' ) !== 'checkout' ) return;
    if ( ! empty( $_GET['section'] ) ) return; // settings page has ?section=bitrequest

    $defs  = WC_Gateway_Bitrequest::coin_defs();
    $coins = [ 'bitcoin', 'lightning', 'litecoin', 'dogecoin', 'dash', 'nano', 'ethereum', 'bitcoin-cash', 'monero', 'kaspa', 'nimiq' ];
    $imgs  = '';
    foreach ( $coins as $c ) {
        if ( ! isset( $defs[$c] ) ) continue;
        $imgs .= "<img src='https://s2.coinmarketcap.com/static/img/coins/64x64/{$defs[$c][2]}.png' "
               . "title='{$defs[$c][1]}' alt='{$defs[$c][1]}' "
               . "style='height:24px;width:24px;border-radius:50%;margin:0 2px;vertical-align:middle'>";
    }
    ?>
    <script>
    (function() {
        var icons = <?php echo wp_json_encode( $imgs ); ?>;

        function tryInject() {
            // Walk all text nodes — finds the title in both classic and React-rendered lists
            var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null, false);
            var node, found = null;
            while ((node = walker.nextNode())) {
                if (node.textContent.trim() === 'Bitrequest' && !node.parentElement.dataset.brDone) {
                    found = node.parentElement;
                    break;
                }
            }
            if (!found) return false;
            found.dataset.brDone = '1';

            // Find the nearest list-item or card container
            var card = found.closest('li') || found.closest('[class]');
            if (!card) card = found.parentElement;

            // Find the description paragraph inside the card
            var desc = card.querySelector('p');
            if (!desc) return false;

            // Append icons on a new line below the description
            var wrap = document.createElement('span');
            wrap.style.cssText = 'display:block;margin-top:5px;line-height:1.6';
            wrap.innerHTML = icons;
            desc.insertAdjacentElement('afterend', wrap);
            return true;
        }

        // Use MutationObserver for React async rendering
        var injected = false;
        var observer = new MutationObserver(function() {
            if (!injected && tryInject()) {
                injected = true;
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });

        // Also try immediately and stop observing after 10s
        if (tryInject()) { injected = true; observer.disconnect(); }
        setTimeout(function() { observer.disconnect(); }, 10000);
    })();
    </script>
    <?php
}

// ─── Orders list: coin icon + txid column ────────────────────────────────────

// HPOS (WC 7.8+)
add_filter( 'woocommerce_shop_order_list_table_columns', 'bitrequest_add_orders_column' );
add_action( 'woocommerce_shop_order_list_table_custom_column', 'bitrequest_render_orders_column', 10, 2 );

// Legacy post-based orders
add_filter( 'manage_edit-shop_order_columns', 'bitrequest_add_orders_column' );
add_action( 'manage_shop_order_posts_custom_column', 'bitrequest_render_orders_column_legacy', 10, 2 );

function bitrequest_add_orders_column( array $columns ): array {
    // Insert after 'order_status'
    $new = [];
    foreach ( $columns as $key => $label ) {
        $new[ $key ] = $label;
        if ( $key === 'order_status' || $key === 'order_number' ) {
            $new['bitrequest_tx'] = '<span title="Bitrequest payment">₿</span>';
        }
    }
    return $new;
}

function bitrequest_render_orders_column( string $column, WC_Order $order ): void {
    if ( $column !== 'bitrequest_tx' ) return;
    if ( $order->get_payment_method() !== 'bitrequest' ) {
        echo '&nbsp;'; // empty for non-Bitrequest orders
        return;
    }
    bitrequest_echo_order_column_content( $order );
}

function bitrequest_render_orders_column_legacy( string $column, int $post_id ): void {
    if ( $column !== 'bitrequest_tx' ) return;
    $order = wc_get_order( $post_id );
    if ( ! $order || $order->get_payment_method() !== 'bitrequest' ) {
        echo '&nbsp;';
        return;
    }
    bitrequest_echo_order_column_content( $order );
}

function bitrequest_echo_order_column_content( WC_Order $order ): void {
    $coin    = $order->get_meta( '_bitrequest_payment' ) ?: '';
    $txhash  = $order->get_meta( '_bitrequest_txhash' )  ?: '';
    $defs    = WC_Gateway_Bitrequest::coin_defs();
    $cmc_id  = $order->get_meta( '_bitrequest_cmc_id' ) ?: ( isset( $defs[$coin] ) ? $defs[$coin][2] : 0 );

    // Always show the Bitrequest icon for any Bitrequest order
    $br_icon = "<img src='" . esc_url( BITREQUEST_WC_URL . 'assets/img/bitrequest-icon.png' ) . "' "
             . "style='width:16px;height:16px;vertical-align:middle;border-radius:3px' title='Bitrequest'>";

    // If coin known, show coin icon instead
    if ( $coin && $cmc_id ) {
        $coin_icon = "<img src='https://s2.coinmarketcap.com/static/img/coins/64x64/{$cmc_id}.png' "
                   . "style='width:18px;height:18px;border-radius:50%;vertical-align:middle' "
                   . "alt='" . esc_attr( strtoupper( $coin ) ) . "' title='" . esc_attr( strtoupper( $coin ) ) . "'>";
    } elseif ( $coin ) {
        $coin_icon = "<span style='font-size:10px;color:#666;font-weight:600'>" . esc_html( strtoupper( $coin ) ) . "</span>";
    } else {
        $coin_icon = $br_icon; // pending — no coin selected yet
    }

    echo "<span style='white-space:nowrap;display:flex;align-items:center;gap:4px;justify-content:center'>{$coin_icon}";

    if ( $txhash ) {
        $l2        = $order->get_meta( '_bitrequest_eth_layer2' ) ?: '';
        $exp_list  = WC_Gateway_Bitrequest::explorer_urls( $coin, $txhash, $l2 );
        $explorer  = $exp_list ? $exp_list[0]['url'] : '';
        $short    = substr( $txhash, 0, 6 ) . '…' . substr( $txhash, -4 );
        if ( $explorer ) {
            // Title shows the full explorer URL so the merchant can verify
            // which chain they're being sent to (polygonscan vs etherscan vs
            // basescan) before clicking, without needing to open the link.
            echo "<a href='" . esc_url( $explorer ) . "' target='_blank' rel='noopener' "
               . "class='button button-small' "
               . "style='font-size:10px;font-family:monospace;padding:1px 4px;line-height:1.4;min-height:0;text-decoration:none' "
               . "title='" . esc_attr( $explorer ) . "'>" . esc_html( $short ) . " ↗</a>";
        } else {
            echo "<code style='font-size:10px' title='" . esc_attr( $txhash ) . "'>" . esc_html( $short ) . "</code>";
        }
    }

    echo '</span>';
}

// Column width
add_action( 'admin_head', function () {
    $screen = get_current_screen();
    if ( ! $screen ) return;
    if ( strpos( $screen->id, 'shop_order' ) === false && strpos( $screen->id, 'woocommerce_page_wc-orders' ) === false ) return;
    echo '<style>
        .column-bitrequest_tx { width: 80px; text-align: center; }
        .manage-column.column-bitrequest_tx { text-align: center; }
    </style>';
} );

// ─── Constrain gateway icon size on checkout ─────────────────────────────────
// The CSS enqueue only fires on order-pay pages; this filter works everywhere.
add_filter( 'woocommerce_gateway_icon', 'bitrequest_constrain_gateway_icon', 10, 2 );

function bitrequest_constrain_gateway_icon( string $icon, string $id ): string {
    if ( $id !== 'bitrequest' || ! $icon ) return $icon;
    // Add inline max-height so the icon renders as a small badge regardless of source dimensions
    return preg_replace( '/<img /', '<img style="max-height:24px;width:auto;vertical-align:middle;" ', $icon, 1 );
}

// ─── Inject crypto payment details into WC emails ─────────────────────────────
// Adds an "Amount paid" + "Transaction" block to the order-table area of
// every WC email (customer "Thank you for your order", admin "New order",
// status-change notifications, refund emails, etc). Only fires for
// Bitrequest orders with at least an amount or txhash to show.
//
// The hook fires for both plaintext and HTML emails — we branch on the
// $plain_text param so plaintext recipients get a readable version.

add_action( 'woocommerce_email_after_order_table', 'bitrequest_email_payment_details', 10, 4 );

function bitrequest_email_payment_details( $order, $sent_to_admin, $plain_text, $email ): void {
    if ( ! $order || $order->get_payment_method() !== 'bitrequest' ) return;

    // Same data sources as the thank-you page method, kept consistent so
    // emails and on-site display always agree. received_amount is preferred
    // over crypto_amount because it reflects what actually arrived on-chain;
    // ccsymbol comes from the per-order snapshot for ERC-20 token correctness.
    $amount = $order->get_meta( '_bitrequest_received_amount' )
           ?: $order->get_meta( '_bitrequest_crypto_amount' )
           ?: '';
    $symbol = strtoupper( $order->get_meta( '_bitrequest_ccsymbol' ) ?: '' );
    $txhash = $order->get_meta( '_bitrequest_txhash' ) ?: '';
    $coin   = $order->get_meta( '_bitrequest_payment' ) ?: '';
    $l2     = $order->get_meta( '_bitrequest_eth_layer2' ) ?: '';

    // Skip the block entirely if we don't have anything to display — keeps
    // free-checkout / pre-payment emails clean.
    if ( $amount === '' && $txhash === '' ) return;

    // Resolve the explorer URL (first preferred explorer for the coin / L2).
    $exp_list = $txhash ? WC_Gateway_Bitrequest::explorer_urls( $coin, $txhash, $l2 ) : [];
    $exp_url  = $exp_list ? $exp_list[0]['url'] : '';

    if ( $plain_text ) {
        echo "\n\n----------\n";
        if ( $amount !== '' ) {
            echo 'Amount paid: ' . $amount;
            if ( $symbol !== '' ) echo ' ' . $symbol;
            echo "\n";
        }
        if ( $txhash !== '' ) {
            echo 'Transaction: ' . $txhash . "\n";
            if ( $exp_url ) echo $exp_url . "\n";
        }
        echo "----------\n\n";
    } else {
        // Inline styles to survive WC's email wrapper without depending on
        // the active theme's CSS. WC's email templates strip class-based
        // styling from custom output.
        echo '<div style="margin:24px 0;padding:12px 16px;background:#f5f5f5;border-left:4px solid #46b450;font-size:14px;line-height:1.6">';
        if ( $amount !== '' ) {
            echo '<p style="margin:0 0 4px"><strong>Amount paid:</strong> ' . esc_html( $amount );
            if ( $symbol !== '' ) echo ' ' . esc_html( $symbol );
            echo '</p>';
        }
        if ( $txhash !== '' ) {
            echo '<p style="margin:0;word-break:break-all"><strong>Transaction:</strong> ';
            echo $exp_url
                ? '<a href="' . esc_url( $exp_url ) . '" style="color:#0073aa;text-decoration:underline">' . esc_html( $txhash ) . '</a>'
                : '<code style="font-family:monospace;font-size:12px">' . esc_html( $txhash ) . '</code>';
            echo '</p>';
        }
        echo '</div>';
    }
}



add_filter( 'woocommerce_order_get_payment_method_title', 'bitrequest_enhance_payment_title', 10, 2 );

function bitrequest_enhance_payment_title( string $title, WC_Order $order ): string {
    if ( $order->get_payment_method() !== 'bitrequest' ) return $title;
    $coin = $order->get_meta( '_bitrequest_payment' ) ?: '';
    if ( ! $coin ) return $title;

    // Resolve crypto display label + symbol. Note: _bitrequest_currency_name
    // stores the fiat unit-of-account label from the iframe (e.g. "United
    // States Dollar"), NOT the crypto name — don't use it here. For the
    // crypto display we use coin_display_info() with the current config,
    // which handles dynamic erc20-token-<slug> rows correctly. The per-order
    // _bitrequest_ccsymbol snapshot wins for the symbol so historical orders
    // keep their settled symbol even if the merchant later renames a token row.
    $gw        = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    $cfg       = ( $gw && method_exists( $gw, 'get_coin_configs' ) ) ? ( $gw->get_coin_configs()[ $coin ] ?? [] ) : [];
    [ $cfg_label, $cfg_symbol, ] = WC_Gateway_Bitrequest::coin_display_info( $coin, $cfg );

    $meta_sym   = $order->get_meta( '_bitrequest_ccsymbol' ) ?: '';
    $symbol     = $meta_sym ? strtoupper( $meta_sym ) : $cfg_symbol;
    return $title . ' — ' . $cfg_label . ' (' . $symbol . ')';
}

// ─── Show coin icon on thank-you / order-received page ───────────────────────

add_action( 'woocommerce_order_details_after_order_table', 'bitrequest_order_details_coin_badge', 5 );

function bitrequest_order_details_coin_badge( WC_Order $order ): void {
    if ( $order->get_payment_method() !== 'bitrequest' ) return;
    $coin   = $order->get_meta( '_bitrequest_payment' ) ?: '';
    if ( ! $coin ) return;

    $gw         = WC()->payment_gateways()->payment_gateways()['bitrequest'] ?? null;
    $cfg        = ( $gw && method_exists( $gw, 'get_coin_configs' ) ) ? ( $gw->get_coin_configs()[ $coin ] ?? [] ) : [];
    [ $cfg_label, $cfg_symbol, $cfg_cmc_id ] = WC_Gateway_Bitrequest::coin_display_info( $coin, $cfg );

    $meta_sym   = $order->get_meta( '_bitrequest_ccsymbol' )       ?: '';
    $meta_name  = $order->get_meta( '_bitrequest_currency_name' )  ?: '';
    $label      = $meta_name ?: $cfg_label;
    $symbol     = $meta_sym  ? strtoupper( $meta_sym ) : $cfg_symbol;
    $cmc_id     = $order->get_meta( '_bitrequest_cmc_id' ) ?: $cfg_cmc_id;
    $icon       = $cmc_id ? "<img src='https://s2.coinmarketcap.com/static/img/coins/64x64/{$cmc_id}.png' style='width:20px;height:20px;border-radius:50%;vertical-align:middle;margin-right:6px'>" : '';
    echo "<p style='margin:8px 0;font-size:14px'><strong>Paid with:</strong> {$icon}" . esc_html( "{$label} ({$symbol})" ) . "</p>";
}

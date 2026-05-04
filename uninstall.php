<?php
/**
 * Bitrequest WooCommerce — uninstaller.
 *
 * Runs when the merchant chooses "Delete" from the Plugins screen, or when
 * wp_uninstall_plugin() is called programmatically. Does NOT run on plugin
 * deactivation — that's intentional: deactivate should preserve config so the
 * merchant can flip the plugin off temporarily without losing their xpubs.
 *
 * What we delete:
 *   1. WP options       — coin configs, used-address ledger, xpub indices,
 *                         WC gateway settings.
 *   2. Checkout lock transients — short-lived per-coin locks; orphaned ones
 *                         would expire on their own but no reason to leave
 *                         them sitting in wp_options.
 *
 * What we deliberately leave alone:
 *   - The orders themselves AND their Bitrequest meta. A merchant
 *     uninstalling Bitrequest may still want their order history for
 *     accounting, tax records, or refund processing — and stripping the
 *     payment metadata while keeping the order shell would leave them with
 *     ghost orders that can't be reconciled (no coin, no txhash, no fiat
 *     amount). Either we keep the whole transaction record or delete it
 *     entirely; deleting the orders without consent would be hostile, so we
 *     keep them. If the merchant wants a full wipe they can bulk-delete
 *     orders manually before or after uninstalling the plugin.
 *   - User-installed Bitrequest assets (e.g. self-hosted PWA on their own
 *     server). Out of scope for this uninstaller.
 */

// Standard guard — this file should only ever execute via WordPress's
// uninstall machinery, never directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// ── 1. Plugin options ────────────────────────────────────────────────────────
// Mirrors every option_name the plugin ever calls update_option/add_option on.
// Keep this list in sync with new options if any get added later.
$options = [
    'bitrequest_coin_configs',          // coin table: xpubs, addresses, indexes, l2 selections
    'bitrequest_used_addresses',        // rotation history (per-coin address ledger)
    'bitrequest_xpub_indices',          // current rotation pointer per xpub
    'woocommerce_bitrequest_settings',  // WC gateway settings (URL, confirmations, webhook, show_qr, …)
];
foreach ( $options as $opt ) {
    delete_option( $opt );
    delete_site_option( $opt ); // multisite-safe
}

// ── 2. Checkout lock transients ──────────────────────────────────────────────
// Locks are stored as `bitrequest_checkout_<coin>` transients (15-minute TTL).
// WP stores transients as two rows each: `_transient_<key>` and
// `_transient_timeout_<key>`. Wipe both.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_bitrequest\\_checkout\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_bitrequest\\_checkout\\_%'"
);

// Multisite: also clear sitemeta-level transients if any plugin code ever
// promoted them. Defensive — current code uses get_transient/set_transient
// which is single-site, but cheap to cover.
if ( is_multisite() ) {
    $wpdb->query(
        "DELETE FROM {$wpdb->sitemeta}
         WHERE meta_key LIKE '\\_site\\_transient\\_bitrequest\\_checkout\\_%'
            OR meta_key LIKE '\\_site\\_transient\\_timeout\\_bitrequest\\_checkout\\_%'"
    );
}

// ── 3. Scheduled events ──────────────────────────────────────────────────────
// Defensive: the cron was removed when we deleted on-chain auto-verify, but
// installs that ran an older version of the plugin may still have the hook
// scheduled. Deactivation also clears this — uninstall covers the case where
// the merchant deletes the plugin without deactivating first (rare but
// possible via WP-CLI `wp plugin delete`).
wp_clear_scheduled_hook( 'bitrequest_verify_pending' );

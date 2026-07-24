<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WC_Gateway_Bitrequest extends WC_Payment_Gateway {

    // Coin: [label, symbol, cmc_id, [explorer_keys], supports_xpub, recommended_confirmations]
    // explorer_keys reference entries in explorer_registry() below; the first
    // key is the primary explorer (used wherever a single link is needed,
    // e.g. the orders-list TX hash column). Lightning has no on-chain txid so
    // its explorer list is empty. erc20-token defaults to etherscan; L2-aware
    // explorer routing for ERC-20 rows is a separate feature handled by the
    // PWA's chain-detection flow at request time.
    //
    // recommended_confirmations is per-coin guidance — the iframe always
    // reports payments at zero-conf, and this value gates the order-status
    // change to processing/completed behind a JS confirm dialog reminding
    // the merchant to verify on-chain. 0 = instant-final (Lightning, Nano,
    // Dash with InstantSend) — no prompt. Higher values reflect realistic
    // finality risk for typical merchant transactions.
    public static function coin_defs(): array {
        return [
            'bitcoin'      => [ 'Bitcoin',      'BTC',  1,     [ 'mempool.space', 'blockchair.com' ],                  true,  1  ],
            'lightning'    => [ 'Lightning',     'LN',   1,     [],                                                     false, 0  ],
            'litecoin'     => [ 'Litecoin',      'LTC',  2,     [ 'litecoinspace.org', 'blockchair.com' ],              true,  2  ],
            'dogecoin'     => [ 'Dogecoin',      'DOGE', 74,    [ 'blockchair.com' ],                                   true,  6  ],
            'dash'         => [ 'Dash',          'DASH', 131,   [ 'blockchair.com', 'dash.org', 'cryptoid.info' ],      true,  0  ],
            'bitcoin-cash' => [ 'Bitcoin Cash',  'BCH',  1831,  [ 'blockchair.com' ],                                   true,  1  ],
            'kaspa'        => [ 'Kaspa',         'KAS',  20396, [ 'explorer.kaspa.org', 'kas.fyi' ],                    true,  10 ],
            'monero'       => [ 'Monero',        'XMR',  328,   [ 'blockchair.com', 'monero.com' ],                     false, 1  ],
            'nano'         => [ 'Nano',          'XNO',  1567,  [ 'nanexplorer.com', 'blocklattice.io', 'spynano.org' ], false, 0  ],
            'nimiq'        => [ 'Nimiq',         'NIM',  2916,  [ 'nimiq.watch', 'nimiqscan.com' ],                     false, 10 ],
            'ethereum'     => [ 'Ethereum',      'ETH',  1027,  [ 'etherscan.io', 'blockchair.com' ],                   true,  1  ],
            'erc20-token'  => [ 'ERC-20 Token',  'TKN',  0,     [ 'etherscan.io', 'blockchair.com' ],                   true,  1  ],
        ];
    }

    /**
     * Recommended confirmation count for a given coin key (handles
     * erc20-token-<slug> dynamic keys). Returns 0 for instant-final
     * coins (Lightning, Nano, Dash-with-InstantSend).
     */
    public static function recommended_confirmations( string $coin ): int {
        $defs   = self::coin_defs();
        $lookup = self::is_erc20_token( $coin ) ? 'erc20-token' : $coin;
        return isset( $defs[ $lookup ][5] ) ? (int) $defs[ $lookup ][5] : 1;
    }

    /**
     * Block-explorer registry. Mirrors the PWA config's blockexplorers array
     * (see assets_js_bitrequest_config.js) so URLs stay consistent across the
     * PWA and the WC plugin. URL composition is:
     *
     *     <url> + <currency_path> + <tx_prefix> + <txhash>
     *
     * where `currency_path` depends on the explorer's `prefix` field:
     *   - "currency"        → use the coin's currency slug (e.g. "litecoin/")
     *   - "currencysymbol"  → use the lowercase symbol     (e.g. "ltc/")
     *   - null/empty        → no currency path             (e.g. mempool.space)
     *
     * @return array<string, array{url:string, prefix:?string, tx_prefix:string}>
     */
    public static function explorer_registry(): array {
        return [
            'blockchair.com'     => [ 'url' => 'https://www.blockchair.com/', 'prefix' => 'currency',       'tx_prefix' => 'transaction/' ],
            'mempool.space'      => [ 'url' => 'https://mempool.space/',      'prefix' => null,             'tx_prefix' => 'tx/'          ],
            'litecoinspace.org'  => [ 'url' => 'https://litecoinspace.org/',  'prefix' => null,             'tx_prefix' => 'tx/'          ],
            'nanexplorer.com'    => [ 'url' => 'https://nanexplorer.com/',    'prefix' => 'nano',           'tx_prefix' => 'block/'       ],
            'blocklattice.io'    => [ 'url' => 'https://blocklattice.io/',    'prefix' => null,             'tx_prefix' => 'block/'       ],
            'spynano.org'        => [ 'url' => 'https://spynano.org/',        'prefix' => null,             'tx_prefix' => 'hash/'        ],
            'etherscan.io'       => [ 'url' => 'https://etherscan.io/',       'prefix' => null,             'tx_prefix' => 'tx/'          ],
            'explorer.kaspa.org' => [ 'url' => 'https://explorer.kaspa.org/', 'prefix' => null,             'tx_prefix' => 'txs/'         ],
            'kas.fyi'            => [ 'url' => 'https://kas.fyi/',            'prefix' => null,             'tx_prefix' => 'transaction/' ],
            'nimiq.watch'        => [ 'url' => 'https://nimiq.watch/',        'prefix' => null,             'tx_prefix' => '#'            ],
            'nimiqscan.com'      => [ 'url' => 'https://nimiqscan.com/',      'prefix' => null,             'tx_prefix' => 'transaction/' ],
            'dash.org'           => [ 'url' => 'https://insight.dash.org/',   'prefix' => null,             'tx_prefix' => 'insight/tx/'  ],
            'cryptoid.info'      => [ 'url' => 'https://chainz.cryptoid.info/', 'prefix' => 'currency',     'tx_prefix' => 'tx.dws?'      ],
            'monero.com'         => [ 'url' => 'https://monero.com/',         'prefix' => null,             'tx_prefix' => 'tx/'          ],
            // L2 chain explorers — referenced by the L2 routing branch in
            // explorer_urls() rather than by coin_defs() entries. Each L2
            // chain maps to exactly one explorer (no multi-source list).
            'arbiscan.io'        => [ 'url' => 'https://arbiscan.io/',       'prefix' => null,             'tx_prefix' => 'tx/'          ],
            'polygonscan.com'    => [ 'url' => 'https://polygonscan.com/',   'prefix' => null,             'tx_prefix' => 'tx/'          ],
            'bscscan.com'        => [ 'url' => 'https://bscscan.com/',       'prefix' => null,             'tx_prefix' => 'tx/'          ],
            'basescan.org'       => [ 'url' => 'https://basescan.org/',      'prefix' => null,             'tx_prefix' => 'tx/'          ],
        ];
    }

    /**
     * Is this coin key an ERC-20 token entry?
     * Matches the bare 'erc20-token' (legacy) and dynamic 'erc20-token-<slug>' keys.
     */
    public static function is_erc20_token( string $coin ): bool {
        return $coin === 'erc20-token' || strncmp( $coin, 'erc20-token-', 12 ) === 0;
    }

    /** Extract the token slug from an 'erc20-token-<slug>' key. Empty string if bare key. */
    public static function erc20_token_slug( string $coin ): string {
        return strncmp( $coin, 'erc20-token-', 12 ) === 0 ? substr( $coin, 12 ) : '';
    }

    /**
     * Is this coin in the ETH family (ethereum + every ERC-20 token row)?
     * These share a single physical address (BIP32 m/44'/60'/0'/0/0 by default)
     * and we deliberately do NOT rotate addresses for them: most wallets only
     * index address 0 on seed import, so rotation would create funds the
     * merchant can't see in their wallet UI. Each ETH-family row gets its own
     * checkout lock at /order-pay/ time to prevent same-asset collisions.
     *
     * USDT/USDC used to live as static 'usdt-erc20' / 'usdc-erc20' rows here
     * but were folded into the dynamic 'erc20-token-tether' / 'erc20-token-
     * usd-coin' rows for a uniform UX. The migration in get_coin_configs()
     * rewrites legacy keys; this helper no longer needs to special-case them.
     */
    public static function is_eth_family( string $coin ): bool {
        return $coin === 'ethereum' || self::is_erc20_token( $coin );
    }

    /**
     * Validate a txhash for a given coin. Used by the verify_tx endpoint to
     * reject malformed values before they get stored or release the checkout
     * lock. We're permissive on the synthesized fallback format
     * `<receiver>|<requestid>` that the JS uses for polling-based coins
     * (Kaspa / Nano) when no real hash is available at notification time.
     *
     * Format reference:
     *   - Lightning:            no on-chain txid — iframe sends a synthetic
     *                           `lightning<payment_hash>` identifier; we just
     *                           require non-empty and let the merchant verify
     *                           via preimage on their node.
     *   - ETH-family:           0x + 64 hex chars
     *   - BTC/LTC/DOGE/DASH/BCH: 64 hex chars
     *   - XMR:                  64 hex chars
     *   - NANO / KAS / NIM:     64 hex chars
     */
    public static function valid_txhash( string $coin, string $txhash ): bool {
        if ( $txhash === '' ) return false;

        // Lightning has no on-chain txid — accept any non-empty identifier.
        if ( $coin === 'lightning' ) return true;

        // Synthesized fallback (polling): <something>|<digits>
        if ( strpos( $txhash, '|' ) !== false ) {
            return (bool) preg_match( '/^.+\|\d+$/', $txhash );
        }

        if ( self::is_eth_family( $coin ) ) {
            return (bool) preg_match( '/^0x[0-9a-fA-F]{64}$/', $txhash );
        }

        // Everything else we support is a 64-hex-char hash.
        return (bool) preg_match( '/^[0-9a-fA-F]{64}$/', $txhash );
    }

    /**
     * Build all explorer URLs for a given coin + txhash, in preference order.
     * Returns a list of [ host, url ] pairs ready for rendering as buttons.
     * Returns empty array for coins without on-chain explorers (lightning) or
     * when txhash is empty.
     *
     * If `$eth_layer2` identifies an L2 chain (e.g. "arbitrum one",
     * "polygon pos", "binance smart chain", "base") the L1 explorer list is
     * bypassed and a single L2-specific explorer is returned. This mirrors
     * the PWA's blockexplorer_url() function — L2 settlements only resolve
     * on the L2's own explorer; L1 etherscan/blockchair would 404 on the
     * same hash, so showing those buttons would just confuse the merchant.
     *
     * For L1 ERC-20 settlements (eth_layer2 empty) the ETH-family list
     * applies (etherscan + blockchair).
     *
     * @param string $coin        Coin key from coin_defs() (or erc20-token-<slug>)
     * @param string $txhash      Transaction hash to link to
     * @param string $eth_layer2  Optional L2 chain name snapshotted from
     *                            txdata.ethereum_layer2 at payment time.
     *                            Empty for L1/non-EVM settlements.
     * @return array<int, array{host:string, url:string}>
     */
    public static function explorer_urls( string $coin, string $txhash, string $eth_layer2 = '' ): array {
        if ( $txhash === '' ) return [];

        $registry = self::explorer_registry();

        // L2 short-circuit. Match the PWA's blockexplorer_url() lookup keys
        // exactly. eth_layer2 is captured at payment time as a per-order
        // snapshot so historical orders keep linking to the right chain even
        // if the merchant later changes their L2 selection on the coin row.
        $l2_map = [
            'arbitrum one'        => 'arbiscan.io',
            'polygon pos'         => 'polygonscan.com',
            'binance smart chain' => 'bscscan.com',
            'base'                => 'basescan.org',
        ];
        $l2_lc = strtolower( trim( $eth_layer2 ) );
        if ( isset( $l2_map[ $l2_lc ] ) ) {
            $key = $l2_map[ $l2_lc ];
            if ( isset( $registry[ $key ] ) ) {
                $r = $registry[ $key ];
                return [ [ 'host' => $key, 'url' => $r['url'] . $r['tx_prefix'] . $txhash ] ];
            }
        }

        $defs   = self::coin_defs();
        $lookup = self::is_erc20_token( $coin ) ? 'erc20-token' : $coin;
        if ( ! isset( $defs[ $lookup ] ) ) return [];

        $explorer_keys = $defs[ $lookup ][3];
        if ( ! is_array( $explorer_keys ) || empty( $explorer_keys ) ) return [];

        $info     = self::coin_display_info( $coin );
        $symbol   = strtolower( $info[1] );
        // The blockchair/cryptoid `prefix: "currency"` substitution uses the
        // coin's currency slug. For ERC-20 token rows, fall back to the parent
        // chain ("ethereum") since L1 ERC-20 settlements are still on Ethereum.
        $currency = self::is_erc20_token( $coin ) ? 'ethereum' : $coin;

        $out = [];
        foreach ( $explorer_keys as $key ) {
            if ( ! isset( $registry[ $key ] ) ) continue;
            $r = $registry[ $key ];

            // Compose the currency path segment based on the explorer's prefix mode.
            $currency_path = '';
            if ( $r['prefix'] === 'currency' ) {
                $currency_path = $currency . '/';
            } elseif ( $r['prefix'] === 'currencysymbol' ) {
                $currency_path = $symbol . '/';
            } elseif ( is_string( $r['prefix'] ) && $r['prefix'] !== '' ) {
                // Literal prefix segment (e.g. nanexplorer's "nano/")
                $currency_path = $r['prefix'] . '/';
            }

            $url = $r['url'] . $currency_path . $r['tx_prefix'] . $txhash;
            $out[] = [ 'host' => $key, 'url' => $url ];
        }
        return $out;
    }

    /**
     * Returns [label, symbol, cmc_id] for display purposes.
     * For erc20-token, overrides the static defaults with the admin-selected token details.
     * @param string $coin
     * @param array  $cfg  coin config (optional — pass to override erc20-token labeling)
     * @return array{0:string,1:string,2:int}
     */
    public static function coin_display_info( string $coin, array $cfg = [] ): array {
        $defs = self::coin_defs();
        $lookup = self::is_erc20_token( $coin ) ? 'erc20-token' : $coin;
        $def  = $defs[ $lookup ] ?? [ $coin, strtoupper( $coin ), 1, '', false ];
        $label  = $def[0];
        $symbol = $def[1];
        $cmc_id = (int) $def[2];

        if ( self::is_erc20_token( $coin ) ) {
            // Use the admin-selected token's symbol/cmcid for display
            $tok_symbol = $cfg['token_symbol'] ?? '';
            $tok_slug   = $cfg['token_slug']   ?? self::erc20_token_slug( $coin );
            $tok_cmcid  = (int) ( $cfg['token_cmcid'] ?? 0 );
            if ( $tok_symbol || $tok_slug ) {
                $label  = $tok_slug ? ucwords( str_replace( '-', ' ', $tok_slug ) ) : $label;
                $symbol = $tok_symbol ? strtoupper( $tok_symbol ) : $symbol;
            }
            if ( $tok_cmcid > 0 ) $cmc_id = $tok_cmcid;
        }
        return [ $label, $symbol, $cmc_id ];
    }

    /**
     * Render one dynamic ERC-20 token row in the coin table.
     * Called both by the initial PHP render and mirrored by admin.js when adding via modal.
     */
    public static function render_erc20_row( string $coin, array $cfg, array $l2_names ): string {
        [ $label, $symbol, $cmc_id ] = self::coin_display_info( $coin, $cfg );
        $enabled   = ! empty( $cfg['enabled'] ) ? 'checked' : '';
        $address   = esc_attr( $cfg['address'] ?? '' );
        $xpub      = esc_attr( $cfg['xpub']    ?? '' );
        $index_val = (int) ( $cfg['index'] ?? 0 );
        $field_val = $xpub ?: $address;
        $slug      = esc_attr( $cfg['token_slug']   ?? self::erc20_token_slug( $coin ) );
        $tok_sym   = esc_attr( $cfg['token_symbol'] ?? '' );
        $tok_cmc   = (int) ( $cfg['token_cmcid'] ?? 0 );
        $icon      = $cmc_id > 0 ? "https://s2.coinmarketcap.com/static/img/coins/64x64/{$cmc_id}.png" : '';
        $l2_chain  = isset( $cfg['l2_chain'] ) ? (int) $cfg['l2_chain'] : -1;

        $safe_label  = esc_html( $label );
        $safe_symbol = esc_html( $symbol );
        $icon_tag    = $icon ? "<img src='{$icon}' onerror=\"this.style.display='none'\">" : '';

        // L2 select: -1 is the "no L2 selected" sentinel. Ethereum mainnet is
        // always monitored regardless — no explicit L1 option needed in the UI.
        $l2_options = "<option value='-1'>— no L2 —</option>";
        foreach ( $l2_names as $idx => $name ) {
            if ( $idx < 0 ) continue;  // skip legacy L1 entry if caller passed it
            $sel = ( $idx === $l2_chain ) ? 'selected' : '';
            $l2_options .= "<option value='{$idx}' {$sel}>" . esc_html( $name ) . "</option>";
        }

        return "<tr class='br-erc20-row' data-coin='{$coin}'>
            <td>{$icon_tag}{$safe_label} <small style='color:#999'>{$safe_symbol}</small></td>
            <td style='text-align:center'>
                <input type='checkbox' name='br_coin[{$coin}][enabled]' value='1' {$enabled} id='br-enable-{$coin}' style='opacity:1'>
            </td>
            <td>
                <div class='br-addr-row'>
                    <a href='#' class='br-test-btn' data-coin='{$coin}'>Test</a>
                    <input type='text' name='br_coin[{$coin}][address_or_xpub]' value='{$field_val}' placeholder='Address / xpub' id='br-xpub-{$coin}'>
                    <select name='br_coin[{$coin}][l2_chain]' class='br-l2-select' style='flex:0 0 70px;width:70px;min-width:70px;max-width:70px;box-sizing:border-box;padding:6px 4px;font-size:12px;height:30px;line-height:1;background:#fff;border:1px solid #8c8f94;border-radius:4px;color:#2c3338'>{$l2_options}</select>
                    <input type='hidden' name='br_coin[{$coin}][index]'        value='{$index_val}' id='br-index-{$coin}'>
                    <input type='hidden' name='br_coin[{$coin}][token_slug]'   value='{$slug}'>
                    <input type='hidden' name='br_coin[{$coin}][token_symbol]' value='{$tok_sym}'>
                    <input type='hidden' name='br_coin[{$coin}][token_cmcid]'  value='{$tok_cmc}'>
                    <button type='button' class='br-delete-btn' data-coin='{$coin}' title='Remove {$safe_symbol}'>&times;</button>
                </div>
            </td>
        </tr>";
    }

    // ── Used address tracking ─────────────────────────────────────────────────

    public function get_used_addresses(): array {
        return get_option( 'bitrequest_used_addresses', [] );
    }

    public function add_used_address( string $coin, string $address ): void {
        if ( ! $coin || ! $address ) return;
        $used = $this->get_used_addresses();
        if ( ! isset( $used[ $coin ] ) ) $used[ $coin ] = [];
        if ( ! in_array( $address, $used[ $coin ], true ) ) {
            $used[ $coin ][] = $address;
            update_option( 'bitrequest_used_addresses', $used );
        }
    }

    public function get_xpub_indices(): array {
        return get_option( 'bitrequest_xpub_indices', [] );
    }

    public function update_xpub_index( string $coin, string $xpub, int $next_index ): void {
        if ( ! $coin || ! $xpub ) return;
        $indices           = $this->get_xpub_indices();
        $prefix            = substr( $xpub, 0, 16 ); // unique per xpub type (zpub vs xpub)
        if ( ! isset( $indices[ $coin ] ) ) $indices[ $coin ] = [];
        $indices[ $coin ][ $prefix ] = $next_index;
        update_option( 'bitrequest_xpub_indices', $indices );
    }
    // ─── Checkout address locking ────────────────────────────────────────────
    // Single-address coins (no xpub, no integrated addresses) need a lock to
    // prevent two concurrent checkouts from sharing the same receiving address.

    /**
     * Does this coin+config need a checkout lock?
     * Lock semantics:
     *   - Lightning: no — proxy/spark/NWC invoices are unique per request.
     *   - ETH-family: yes — single shared address, no rotation, must lock per row.
     *   - Monero: yes — although integrated addresses give each checkout a
     *     unique payment ID, payment IDs aren't always visible in the mempool,
     *     so a lock keeps the merchant's pending-payment view unambiguous
     *     until confirmation.
     *   - Everything else: yes when there's no xpub (single static address).
     */
    public static function needs_checkout_lock( string $coin, string $xpub = '' ): bool {
        if ( $coin === 'lightning' ) return false;
        if ( self::is_eth_family( $coin ) ) return true;
        return empty( $xpub );
    }

    /**
     * Try to claim a checkout lock for a single-address coin.
     * Returns true if this order now holds the lock.
     * Returns false if another active order holds it.
     */
    public function try_lock_checkout( string $coin, int $order_id ): bool {
        $key    = 'bitrequest_checkout_' . $coin;
        $holder = get_transient( $key );

        // No lock → claim it
        if ( $holder === false ) {
            set_transient( $key, $order_id, 15 * MINUTE_IN_SECONDS );
            return true;
        }

        // Same order (page reload) → renew
        if ( (int) $holder === $order_id ) {
            set_transient( $key, $order_id, 15 * MINUTE_IN_SECONDS );
            return true;
        }

        // Different order holds the lock — check if it's still relevant
        $other = wc_get_order( (int) $holder );
        if ( ! $other || ! $other->has_status( 'pending' ) ) {
            // Only pending orders have an active request panel — reclaim the lock
            set_transient( $key, $order_id, 15 * MINUTE_IN_SECONDS );
            return true;
        }

        // Another active order holds the lock
        return false;
    }

    /** Release a checkout lock (after payment or cancellation) */
    public function release_checkout_lock( string $coin, int $order_id = 0 ): void {
        $key    = 'bitrequest_checkout_' . $coin;
        $holder = get_transient( $key );
        // Only release if this order holds it (or unconditionally if order_id = 0)
        if ( $holder === false ) return;
        if ( $order_id && (int) $holder !== $order_id ) return;
        delete_transient( $key );
    }

    /**
     * Peek at a coin's checkout lock without claiming it.
     * Returns true only if another active (still-pending) order holds it.
     * Stale locks (holder paid / cancelled / missing) are reported as not held —
     * the acquire-time logic in try_lock_checkout will reclaim them then.
     * Used at page-load time so we filter the dropdown without committing the
     * customer to a coin they haven't picked yet.
     */
    public function is_checkout_lock_held( string $coin, int $exclude_order_id = 0 ): bool {
        $key    = 'bitrequest_checkout_' . $coin;
        $holder = get_transient( $key );
        if ( $holder === false ) return false;
        if ( $exclude_order_id && (int) $holder === $exclude_order_id ) return false;
        $other = wc_get_order( (int) $holder );
        if ( ! $other || ! $other->has_status( 'pending' ) ) return false;
        return true;
    }

    /**
     * Reserve a unique xpub index for a specific order.
     * Idempotent: reloading the checkout page returns the same index.
     */
    public function reserve_xpub_index( string $coin, string $xpub, \WC_Order $order ): int {
        $meta_key = '_bitrequest_reserved_index_' . $coin;
        $reserved = $order->get_meta( $meta_key );
        if ( $reserved !== '' && $reserved !== false ) return (int) $reserved;

        // Atomically increment the global per-xpub counter
        $prefix  = substr( $xpub, 0, 16 );
        $indices = $this->get_xpub_indices();
        $current = isset( $indices[ $coin ][ $prefix ] )
                 ? (int) $indices[ $coin ][ $prefix ]
                 : (int) get_option( "bitrequest_xpub_index_{$coin}", 0 );

        $reserved_index = $current;
        $this->update_xpub_index( $coin, $xpub, $current + 1 );
        $order->update_meta_data( $meta_key, $reserved_index );
        $order->save();
        return $reserved_index;
    }

    public static function method_icons_html(): string {
        $defs   = self::coin_defs();
        $coins  = [ 'bitcoin', 'lightning', 'litecoin', 'dogecoin', 'dash', 'nano', 'ethereum', 'bitcoin-cash', 'monero', 'kaspa', 'nimiq' ];
        $style  = 'height:22px;margin:0 3px 0 0;vertical-align:middle;border-radius:50%';
        $html   = '';
        foreach ( $coins as $c ) {
            if ( ! isset( $defs[ $c ] ) ) continue;
            $id    = $defs[ $c ][2];
            $label = $defs[ $c ][1];
            $html .= "<img src='https://s2.coinmarketcap.com/static/img/coins/64x64/{$id}.png' style='{$style}' alt='{$label}' title='{$label}'>";
        }
        return $html;
    }

    public function __construct() {
        $this->id                 = 'bitrequest';
        $this->has_fields         = false;
        $this->method_title       = 'Bitrequest';
        $this->method_description = 'Accept cryptocurrency payments — non-custodial, no KYC, multi-coin.';
        $this->icon               = BITREQUEST_WC_URL . 'assets/img/bitrequest-icon.png';
        $this->supports           = [ 'products' ];

        $this->init_form_fields();
        $this->init_settings();

        $this->title       = $this->get_option( 'title' );
        $this->description = $this->get_option( 'description' );
        $this->enabled     = $this->get_option( 'enabled' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
        add_action( 'woocommerce_thankyou_' . $this->id, [ $this, 'thankyou_page' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // ─── PWA origin ───────────────────────────────────────────────────────────

    /**
     * Origin of the Bitrequest PWA, with a trailing slash. Driven by the admin
     * "Bitrequest URL" setting so self-hosters and Firebase mirror users pull
     * every script and stylesheet from the same origin their iframe points at.
     *
     * The PWA helper auto-derives its trusted origin from its own script src,
     * so as long as the libs and the iframe load from the same host the
     * iframe-URL check and the postMessage origin check both pass without
     * any further configuration. Keep this in sync with the form default at
     * init_form_fields() — empty/whitespace falls back to the canonical host.
     */
    private function get_br_base(): string {
        $url = trim( (string) $this->get_option( 'bitrequest_url', 'https://bitrequest.github.io' ) );
        if ( $url === '' ) $url = 'https://bitrequest.github.io';
        return rtrim( $url, '/' ) . '/';
    }

    /** Same as get_br_base() but without the trailing slash, for JS consumers. */
    private function get_br_url(): string {
        return rtrim( $this->get_br_base(), '/' );
    }

    // ─── Settings ─────────────────────────────────────────────────────────────

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable Bitrequest payments',
                'default' => 'no',
            ],
            'title' => [
                'title'       => 'Title',
                'type'        => 'text',
                'default'     => 'Cryptocurrency',
                'desc_tip'    => true,
                'description' => 'Payment method title shown at checkout.',
            ],
            'description' => [
                'title'       => 'Description',
                'type'        => 'textarea',
                'default'     => 'Pay with cryptocurrency via Bitrequest.',
                'description' => 'Description shown at checkout.',
            ],
            'bitrequest_url' => [
                'title'       => 'Bitrequest URL',
                'type'        => 'text',
                'default'     => 'https://bitrequest.github.io',
                'desc_tip'    => true,
                'description' => 'Leave default unless self-hosted.',
            ],
            'webhook_url' => [
                'title'       => 'Webhook URL',
                'type'        => 'text',
                'default'     => '',
                'desc_tip'    => true,
                'description' => 'Optional. URL to receive a POST with full transaction data when a payment is detected. Receives JSON with order and txdata.',
            ],
            'show_qr' => [
                'title'       => 'Show QR',
                'type'        => 'checkbox',
                'label'       => 'Open the request panel with the QR code facing forward',
                'default'     => 'no',
                'desc_tip'    => true,
                'description' => 'Appends &showqr=true to the Bitrequest request URL — both for customer checkouts and the admin Test buttons. Useful when running a customer-facing display where the QR should be the first thing they see.',
            ],
            'coin_settings' => [
                'title' => 'Accepted cryptocurrencies',
                'type'  => 'html',
                'html'  => $this->render_coin_table(),
            ],
        ];
    }

    public function generate_html_html( $key, $data ): string {
        return '<tr><th scope="row" class="titledesc"><label>' . esc_html( $data['title'] ) . '</label></th>'
             . '<td class="forminp">' . $data['html'] . '</td></tr>';
    }

    private function render_coin_table(): string {
        $defs    = self::coin_defs();
        $configs = $this->get_coin_configs();

        $html = '<style>
            .br-coin-table{border-collapse:collapse;width:100%;font-size:13px;table-layout:fixed!important}
            .br-coin-table th{text-align:left;padding:6px 8px;background:#f9f9f9;border-bottom:2px solid #ddd}
            .br-coin-table td{padding:10px 8px;border-bottom:1px solid #eee;vertical-align:top}
            .br-coin-table td:first-child{padding-top:14px;white-space:nowrap}
            .br-coin-table input[type=checkbox]{margin:4px 0 0 0}
            .br-coin-table img{width:22px;height:22px;vertical-align:middle;margin-right:5px;border-radius:50%}
            .br-coin-table input[type=text]{width:100%;font-size:12px}
            /* Utility: indent to match input column (past Test button + gap) */
            .br-inset{margin-left:36px}
            .br-seed-box{padding:14px 16px;background:#f0f6ff;border:1px solid #b3d4f5;border-radius:6px;margin-bottom:16px}
            .br-seed-box textarea{width:100%!important;box-sizing:border-box;font-family:monospace;font-size:13px;margin:16px 0!important;padding:12px 16px!important;text-align:center}
            .br-derive-section{margin-top:16px;padding:12px;background:#fff8e1;border:1px solid #ffe082;border-radius:4px}
            .br-addr-row{display:flex;align-items:center;gap:8px;flex-wrap:nowrap}
            .br-addr-row input[type=text]{flex:1 1 auto!important;min-width:0;width:auto!important}
            .br-test-btn{flex-shrink:0;color:#2271b1;text-decoration:underline;cursor:pointer;font-size:12px}
            .br-test-btn.disabled{color:#999;cursor:not-allowed;text-decoration:none}
            .br-err{color:#cc0000;font-size:11px;flex-shrink:0;white-space:nowrap}

            /* Mobile (WordPress admin breaks at 782px) — each row becomes a full-width card.
               Must explicitly kill table-layout:fixed + colgroup col widths otherwise the
               fixed-layout constraints leak through and squeeze the cards. */
            @media (max-width:782px){
                .br-coin-table{display:block;width:100%;table-layout:auto!important;font-size:14px}
                .br-coin-table colgroup,.br-coin-table col{display:none!important}
                .br-coin-table thead{display:none}
                .br-coin-table tbody{display:block;width:100%}
                .br-coin-table tbody tr{
                    display:block;width:100%;box-sizing:border-box;
                    border:1px solid #ddd;border-radius:6px;
                    padding:10px 12px;margin-bottom:8px;background:#fff
                }
                .br-coin-table tbody td{display:block;width:100%;padding:4px 0;border:none}
                .br-coin-table tbody td:first-child{
                    display:inline-flex;align-items:center;padding:0;width:auto
                }
                .br-coin-table tbody td:nth-child(2){
                    display:inline-block;float:right;padding:0;text-align:right;width:auto
                }
                .br-coin-table tbody td:nth-child(2) input[type=checkbox]{margin:0}
                .br-coin-table tbody td:nth-child(3){
                    display:block;clear:both;padding-top:10px;width:100%
                }
                .br-coin-table tbody td:first-child img{width:20px;height:20px}
                .br-err{white-space:normal;flex-basis:100%;margin-top:2px}
                .br-addr-row input[type=text]{font-size:14px;padding:6px}
                .br-seed-box textarea{font-size:14px;text-align:left}
                /* Wider, easier-to-tap nav buttons in card mode */
                .br-coin-table .br-addr-prev,
                .br-coin-table .br-addr-next{padding:4px 16px!important;font-size:16px!important}
            }

            /* Description textarea: match width of other text inputs, keep vertical resize */
            #woocommerce_bitrequest_description{max-width:25em;resize:vertical}

            /* Dynamic ERC-20 row delete button */
            .br-delete-btn{flex-shrink:0;background:none;border:none;color:#cc0000;font-size:18px;font-weight:bold;cursor:pointer;padding:0 6px;line-height:1;opacity:0.6}
            .br-delete-btn:hover{opacity:1}

            /* Add-token modal */
            .br-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;display:none;align-items:center;justify-content:center}
            .br-modal-overlay.br-show{display:flex}
            .br-modal{background:#fff;border-radius:10px;padding:22px 24px;width:460px;max-width:92vw;box-shadow:0 12px 36px rgba(0,0,0,0.3)}
            .br-modal h3{margin:0 0 14px;font-size:15px;display:flex;align-items:center;gap:10px;color:#222}
            .br-modal-dollar{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#10b981;color:#fff;font-weight:bold;font-size:14px;font-family:monospace}
            .br-selectbox{position:relative;margin-bottom:18px}
            .br-selectbox input{width:100%;padding:10px 42px 10px 12px;border:1px solid #ccc;border-radius:4px;font-size:14px;box-sizing:border-box}
            .br-hamburger{position:absolute;right:10px;top:50%;transform:translateY(-50%);cursor:pointer;color:#10b981;font-size:18px;user-select:none;padding:4px 6px;line-height:1}
            .br-options{position:absolute;top:calc(100% + 2px);left:0;right:0;max-height:280px;overflow-y:auto;border:1px solid #ddd;border-radius:4px;background:#fff;display:none;z-index:1;box-shadow:0 6px 14px rgba(0,0,0,0.12)}
            .br-options.br-show{display:block}
            .br-option{padding:10px 14px;cursor:pointer;display:flex;align-items:center;gap:10px;border-bottom:1px solid #f0f0f0;font-size:14px;color:#444}
            .br-option:last-child{border-bottom:none}
            .br-option:hover,.br-option.br-highlight{background:#f5f5f5}
            .br-option img{width:22px;height:22px;border-radius:50%;flex-shrink:0}
            .br-option.br-empty{color:#888;cursor:default;font-style:italic}
            .br-option.br-empty:hover{background:transparent}
            .br-modal-btns{text-align:right}
            .br-modal-btns button{margin-left:14px;background:none;border:none;color:#10b981;font-weight:600;font-size:13px;cursor:pointer;padding:6px 10px;letter-spacing:0.5px}
            .br-modal-btns button:hover{color:#0e9f74}
            .br-modal-btns button:disabled{color:#bbb;cursor:not-allowed}
        </style>

        <div class="br-seed-box">
            <strong>🔑 Generate xpubs from seed phrase</strong>
            <p style="margin:4px 0 0;font-size:12px;color:#555">
                Enter your 12-word BIP39 seed phrase. All xpub fields below will be filled automatically.
                The seed phrase runs entirely in your browser and is <strong>never stored</strong> — only the xpubs are saved.
            </p>
            <textarea id="br-mnemonic" rows="2"
                placeholder="Enter your 12-word seed phrase"
                autocomplete="off" spellcheck="false"></textarea>
            <button type="button" id="br-derive-btn" class="button button-primary">Generate xpubs</button>
            <div id="br-derived-result" style="margin-top:8px;font-size:12px"></div>
        </div>';

        $html .= '<table class="br-coin-table" style="table-layout:fixed">
            <colgroup>
                <col style="width:140px">
                <col style="width:60px">
                <col>
            </colgroup>
            <thead><tr>
                <th>Coin</th>
                <th style="text-align:center">Enable</th>
                <th>address</th>
            </tr></thead><tbody>';

        foreach ( $defs as $coin => $def ) {
            // Skip the bare 'erc20-token' fallback — dynamic rows are rendered after this loop.
            if ( $coin === 'erc20-token' ) continue;

            [ $label, $symbol, $cmc_id, , $xpub_ok ] = $def;
            $cfg     = $configs[ $coin ] ?? [];
            $enabled = ! empty( $cfg['enabled'] ) ? 'checked' : '';
            $address = esc_attr( $cfg['address'] ?? '' );
            $xpub    = esc_attr( $cfg['xpub'] ?? '' );
            $icon    = "https://s2.coinmarketcap.com/static/img/coins/64x64/{$cmc_id}.png";

            $html .= "<tr>
                <td><img src='{$icon}' onerror=\"this.style.display='none'\">{$label} <small style='color:#999'>{$symbol}</small></td>
                <td style='text-align:center'>
                    <input type='checkbox' name='br_coin[{$coin}][enabled]' value='1' {$enabled} id='br-enable-{$coin}' style='opacity:1'>
                </td>
                <td>";

            if ( $coin === 'lightning' ) {
                // Lightning — proxy + imp + spark privkey; no address field, uses BTC xpub as fallback
                $spark_val   = esc_attr( $cfg['spark_privkey'] ?? '' );
                $lnurl_proxy = esc_attr( $cfg['lnurl_proxy']   ?? '' );
                $imp_val     = $cfg['imp'] ?? '';
                $imp_opts    = [ 'spark' => 'Spark', 'lnd' => 'LND', 'c-lightning' => 'Core Lightning', 'lnbits' => 'LNbits', 'nwc' => 'NWC' ];
                $empty_sel   = $imp_val === '' ? 'selected' : '';
                $imp_select  = "<option value='' {$empty_sel}>— select implementation —</option>";
                foreach ( $imp_opts as $v => $l ) {
                    $sel = selected( $imp_val, $v, false );
                    $imp_select .= "<option value='{$v}'{$sel}>{$l}</option>";
                }
                $html .= "
                <div class='br-addr-row'>
                    <a href='#' class='br-test-btn' data-coin='lightning'>Test</a>
                    <input type='text' name='br_coin[lightning][lnurl_proxy]' value='{$lnurl_proxy}'
                        placeholder='enter proxy url' id='br-lnurl-proxy'
                        style='font-family:monospace;font-size:12px;height:30px;padding:0 8px;box-sizing:border-box'>
                    <select name='br_coin[lightning][imp]' id='br-ln-imp' style='font-size:12px;flex-shrink:0;width:90px;height:30px;padding:0 6px;box-sizing:border-box'>{$imp_select}</select>
                </div>
                <div class='br-inset' style='margin-top:4px;font-size:11px;color:#888'>
                    Configure your <a href='https://github.com/bitrequest/bitrequest.github.io/tree/master/proxy' target='_blank' rel='noopener'>proxy server</a>. Uses Bitcoin xpub/address for on-chain fallback.
                </div>
                <div id='br-spark-privkey-row' class='br-inset' style='display:none;margin-top:5px'>
                    <small style='color:#666'>Spark identity privkey — copy this into your proxy server config:</small><br>
                    <input type='text' name='br_coin[lightning][spark_privkey]' value='{$spark_val}'
                        id='br-spark-privkey' readonly onclick='this.select()'
                        style='width:100%;font-family:monospace;font-size:11px;background:#f9f9f9;cursor:pointer'
                        title='Click to select all'>
                </div>
";
            } elseif ( $xpub_ok ) {
                // Hybrid address/xpub field — xpub overwrites address when generated.
                // No L2 select here: ethereum is mainnet-only because etherscan's
                // free API tier no longer covers native ETH transfers on L2 chains
                // (Arbitrum, Base), so the PWA can't monitor incoming L2 payments
                // for plain ETH. ERC-20 token rows keep the full L2 select via
                // render_erc20_row() — token transfers go through a different
                // API path that still works on L2.
                $index_val = (int) ( $cfg['index'] ?? 0 );
                $field_val = $xpub ?: $address;

                $html .= "<div class='br-addr-row'>
                    <a href='#' class='br-test-btn' data-coin='{$coin}'>Test</a>
                    <input type='text' name='br_coin[{$coin}][address_or_xpub]' value='{$field_val}' placeholder='Address / xpub' id='br-xpub-{$coin}'>
                    <input type='hidden' name='br_coin[{$coin}][index]' value='{$index_val}' id='br-index-{$coin}'>
                </div>";
            } elseif ( $coin === 'monero' ) {
                $viewkey = esc_attr( $cfg['viewkey'] ?? '' );
                $html .= "<div class='br-addr-row'>
                    <a href='#' class='br-test-btn' data-coin='{$coin}'>Test</a>
                    <input type='text' name='br_coin[{$coin}][address]' value='{$address}' placeholder='Address'>
                </div>
                <div class='br-addr-row' style='margin-top:6px'>
                    <span class='br-test-btn' style='visibility:hidden' aria-hidden='true'>Test</span>
                    <input type='text' name='br_coin[{$coin}][viewkey]' value='{$viewkey}' placeholder='Secret viewkey (64 hex chars, required)' id='br-viewkey-monero' style='font-family:monospace'>
                </div>
                <div class='br-inset' style='margin-top:3px;font-size:11px;color:#888'>Unique integrated addresses generated per order. Viewkey enables automatic on-chain verification.</div>";
            } else {
                // Nano, Nimiq — static address only
                $html .= "<div class='br-addr-row'>
                    <a href='#' class='br-test-btn' data-coin='{$coin}'>Test</a>
                    <input type='text' name='br_coin[{$coin}][address]' value='{$address}' placeholder='Address'>
                </div>";
            }
            $html .= "</td></tr>";
        }

        // ── Dynamic ERC-20 token rows ─────────────────────────────────────────
        // L1 Ethereum is always monitored — don't render it as an explicit option.
        $l2_names = [ 0 => 'Arbitrum', 1 => 'Polygon', 2 => 'BNB Smart Chain', 3 => 'Base' ];
        foreach ( $configs as $coin => $cfg ) {
            if ( ! self::is_erc20_token( $coin ) ) continue;
            if ( $coin === 'erc20-token' )          continue; // legacy key, shouldn't appear post-migration
            $html .= self::render_erc20_row( $coin, $cfg, $l2_names );
        }

        $html .= '</tbody></table>';

        // ── Add token button + picker modal ───────────────────────────────────
        $html .= '<p style="margin:12px 0 0">
            <button type="button" id="br-add-erc20-btn" class="button button-secondary">+ Add ERC-20 token</button>
        </p>';

        $html .= '<div id="br-erc20-modal" class="br-modal-overlay" role="dialog" aria-modal="true" aria-labelledby="br-erc20-modal-title">
            <div class="br-modal">
                <h3 id="br-erc20-modal-title"><span class="br-modal-dollar">$</span> Add erc20 token</h3>
                <div class="br-selectbox">
                    <input type="text" id="br-erc20-ac-input" placeholder="Pick erc20 token" autocomplete="off" autocapitalize="off" spellcheck="false">
                    <span class="br-hamburger" id="br-erc20-ac-toggle" role="button" aria-label="Show token list" tabindex="0">&#9776;</span>
                    <div class="br-options" id="br-erc20-ac-options"></div>
                </div>
                <div class="br-modal-btns">
                    <button type="button" id="br-erc20-cancel">CANCEL</button>
                    <button type="button" id="br-erc20-ok">OK</button>
                </div>
            </div>
        </div>';

        $html .= '<p style="margin:8px 0 0;font-size:12px;color:#555">
            Address derivation happens in the browser using the Bitrequest PWA libraries — seed phrases never leave your device.
        </p>';

        // Mnemonic → xpub helper


        return $html;
    }

public function process_admin_options() {
        parent::process_admin_options();
        $raw = $_POST['br_coin'] ?? [];
        if ( ! is_array( $raw ) ) $raw = [];
        update_option( 'bitrequest_coin_configs', $this->sanitize_coin_configs_payload( $raw ) );
    }

    /**
     * Build a saveable bitrequest_coin_configs array from a raw $_POST['br_coin'] payload.
     * Used by both process_admin_options() (full-form Save button) and the
     * AJAX autosave endpoint, so the two paths can never disagree on shape.
     */
    public function sanitize_coin_configs_payload( array $raw ): array {
        $defs    = self::coin_defs();
        $current = $this->get_coin_configs();
        $saved   = [];

        // Build the full list of coin keys to save: all static defs (except the bare erc20-token
        // fallback) plus any dynamic erc20-token-<slug> keys submitted via the modal.
        $coin_keys = [];
        foreach ( $defs as $coin => $_ ) {
            if ( $coin === 'erc20-token' ) continue;
            $coin_keys[ $coin ] = true;
        }
        foreach ( $raw as $coin => $_ ) {
            if ( self::is_erc20_token( $coin ) && $coin !== 'erc20-token' ) {
                // Only accept slugs matching [a-z0-9-] to keep keys clean
                $slug = self::erc20_token_slug( $coin );
                if ( $slug && preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
                    $coin_keys[ $coin ] = true;
                }
            }
        }

        foreach ( array_keys( $coin_keys ) as $coin ) {
            $xpub_ok = self::is_erc20_token( $coin ) || ! empty( $defs[ $coin ][4] );

            // Resolve address + xpub from unified field (if present) or legacy separate fields
            $address = '';
            $xpub    = '';
            if ( isset( $raw[ $coin ]['address_or_xpub'] ) ) {
                $val = trim( sanitize_text_field( $raw[ $coin ]['address_or_xpub'] ) );
                // xpub/zpub/ypub/Ltub/Mtub/dgub/kpub all match this pattern
                if ( $val && preg_match( '/^(xpub|ypub|zpub|Ltub|Mtub|dgub|drkp|kpub)/i', $val ) ) {
                    $xpub = $val;
                } else {
                    $address = $val;
                }
            } else {
                // Legacy: separate address and xpub fields (Monero, Nano, Nimiq, Lightning)
                $address = sanitize_text_field( $raw[ $coin ]['address'] ?? '' );
                $xpub    = sanitize_text_field( $raw[ $coin ]['xpub']    ?? '' );
            }

            $saved[ $coin ] = [
                'enabled'      => ! empty( $raw[ $coin ]['enabled'] ),
                'address'      => $address,
                'xpub'         => $xpub,
                'index'        => (int) ( $raw[ $coin ]['index'] ?? $current[ $coin ]['index'] ?? 0 ),
                'spark_privkey'=> sanitize_text_field( $raw[ $coin ]['spark_privkey'] ?? '' ),
                'lnurl_proxy'  => sanitize_text_field( $raw[ $coin ]['lnurl_proxy']    ?? '' ),
                'imp'          => sanitize_text_field( $raw[ $coin ]['imp']           ?? '' ),
                'viewkey'      => sanitize_text_field( $raw[ $coin ]['viewkey']       ?? '' ),
                'token_slug'   => sanitize_text_field( $raw[ $coin ]['token_slug']    ?? '' ),
                'token_symbol' => sanitize_text_field( $raw[ $coin ]['token_symbol']  ?? '' ),
                'token_cmcid'  => (int) ( $raw[ $coin ]['token_cmcid'] ?? 0 ),
                'l2_chain'     => isset( $raw[ $coin ]['l2_chain'] ) ? (int) $raw[ $coin ]['l2_chain'] : -1,
            ];
        }
        return $saved;
    }

    public function get_coin_configs(): array {
        // Distinguish "option doesn't exist yet" (fresh install — seed defaults)
        // from "option exists and is empty" (admin deliberately removed everything).
        $raw = get_option( 'bitrequest_coin_configs', null );

        if ( $raw === null ) {
            // Fresh install — seed USDT and USDC as ERC-20 token rows so they
            // appear in the table by default. Disabled until the merchant
            // fills in an address/xpub. Mirrors the data shape that the
            // picker would produce when adding either token manually.
            $seed = [
                'erc20-token-tether' => [
                    'enabled'      => 0,
                    'address'      => '',
                    'xpub'         => '',
                    'index'        => 0,
                    'token_slug'   => 'tether',
                    'token_symbol' => 'usdt',
                    'token_cmcid'  => 825,
                    'l2_chain'     => -1,
                ],
                'erc20-token-usd-coin' => [
                    'enabled'      => 0,
                    'address'      => '',
                    'xpub'         => '',
                    'index'        => 0,
                    'token_slug'   => 'usd-coin',
                    'token_symbol' => 'usdc',
                    'token_cmcid'  => 3408,
                    'l2_chain'     => -1,
                ],
            ];
            add_option( 'bitrequest_coin_configs', $seed );
            return $seed;
        }

        $saved = is_array( $raw ) ? $raw : [];
        $dirty = false;

        // One-time migration: legacy 'erc20-token' entry with token_slug → 'erc20-token-<slug>'
        if ( isset( $saved['erc20-token'] ) && ! empty( $saved['erc20-token']['token_slug'] ) ) {
            $old     = $saved['erc20-token'];
            $slug    = sanitize_title( $old['token_slug'] );
            $new_key = 'erc20-token-' . $slug;
            if ( $slug && ! isset( $saved[ $new_key ] ) ) {
                $saved[ $new_key ] = $old;
            }
            unset( $saved['erc20-token'] );
            $dirty = true;
        }

        // One-time migration: the static 'usdt-erc20' / 'usdc-erc20' rows used
        // to live in coin_defs() but were folded into the dynamic ERC-20 rows
        // for a uniform UX. Existing configs are remapped so prior addresses,
        // xpubs, indexes, l2_chain selections and enabled state survive intact.
        $static_to_dynamic = [
            'usdt-erc20' => [ 'erc20-token-tether',   'tether',   'usdt', 825  ],
            'usdc-erc20' => [ 'erc20-token-usd-coin', 'usd-coin', 'usdc', 3408 ],
        ];
        foreach ( $static_to_dynamic as $old_key => $meta ) {
            if ( ! isset( $saved[ $old_key ] ) ) continue;
            [ $new_key, $token_slug, $token_symbol, $token_cmcid ] = $meta;
            $old = $saved[ $old_key ];
            // Don't clobber a row the admin may have already added via the picker.
            if ( ! isset( $saved[ $new_key ] ) ) {
                $saved[ $new_key ] = array_merge( $old, [
                    'token_slug'   => $token_slug,
                    'token_symbol' => $token_symbol,
                    'token_cmcid'  => $token_cmcid,
                ] );
            }
            unset( $saved[ $old_key ] );
            $dirty = true;
        }

        if ( $dirty ) {
            update_option( 'bitrequest_coin_configs', $saved );
        }

        return $saved;
    }

    public function get_enabled_coins(): array {
        $all    = $this->get_coin_configs();
        $defs   = self::coin_defs();
        $result = [];
        foreach ( $all as $coin => $c ) {
            if ( empty( $c['enabled'] ) ) continue;
            // Skip coins no longer recognised — covers entries left over from
            // older versions that had a coin we've since removed. Dynamic
            // erc20-token-<slug> rows pass via the is_erc20_token check.
            if ( ! isset( $defs[ $coin ] ) && ! self::is_erc20_token( $coin ) ) continue;
            if ( $coin === 'lightning' ) {
                // Lightning: valid with proxy URL, spark privkey, or legacy nwc_string
                if ( ! empty( $c['lnurl_proxy'] ) || ! empty( $c['spark_privkey'] ) || ! empty( $c['nwc_string'] ) ) {
                    $result[ $coin ] = $c;
                }
            } elseif ( self::is_erc20_token( $coin ) ) {
                // Dynamic ERC-20 token: needs token_slug + address/xpub
                if ( ! empty( $c['token_slug'] ) && ( ! empty( $c['address'] ) || ! empty( $c['xpub'] ) ) ) {
                    $result[ $coin ] = $c;
                }
            } else {
                // All other coins: need at least a static address or xpub
                if ( ! empty( $c['address'] ) || ! empty( $c['xpub'] ) ) {
                    $result[ $coin ] = $c;
                }
            }
        }
        return $result;
    }

    // ─── Payment ──────────────────────────────────────────────────────────────

    public function process_payment( $order_id ) {
        $order   = wc_get_order( $order_id );
        $enabled = $this->get_enabled_coins();
        $defs    = self::coin_defs();

        // 16 random bytes -> 32 hex chars. random_bytes() is unconditionally a CSPRNG,
        // unlike wp_generate_password(), which is not guaranteed cryptographic on
        // every host. This secret is the per-order auth token for verify_tx.
        $order->update_meta_data( '_bitrequest_payment_secret', bin2hex( random_bytes( 16 ) ) );

        // Pre-store enabled coins as a hint for the orders list column
        // The actual coin is selected by the customer on the order-pay page
        if ( count( $enabled ) === 1 ) {
            // Single coin configured — we know exactly which one it will be
            $coin = array_key_first( $enabled );
            $order->update_meta_data( '_bitrequest_payment', $coin );
            $cmc  = $defs[$coin][2] ?? 0;
            if ( $cmc ) $order->update_meta_data( '_bitrequest_cmc_id', (string) $cmc );
        }

        $order->update_status( 'pending', 'Awaiting Bitrequest crypto payment.' );
        $order->save();
        wc_reduce_stock_levels( $order_id );
        WC()->cart->empty_cart();
        return [ 'result' => 'success', 'redirect' => $order->get_checkout_payment_url( true ) ];
    }

    // ─── Receipt page ─────────────────────────────────────────────────────────

    public function receipt_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $enabled = $this->get_enabled_coins();
        $defs    = self::coin_defs();

        // Filter out single-address coins that another active checkout is using.
        // Peek-only — the lock is claimed at click time via AJAX.
        $locked_names = [];
        foreach ( $enabled as $coin => $cfg ) {
            $xpub = $cfg['xpub'] ?? '';
            if ( self::needs_checkout_lock( $coin, $xpub ) ) {
                if ( $this->is_checkout_lock_held( $coin, $order_id ) ) {
                    [ $disp_label, , ] = self::coin_display_info( $coin, $cfg );
                    $locked_names[] = $disp_label;
                    unset( $enabled[ $coin ] );
                }
            }
        }

        if ( empty( $enabled ) ) {
            echo '<p>No cryptocurrencies available right now. Please try again in a few minutes.</p>';
            return;
        }
        $coins_json = wp_json_encode( array_keys( $enabled ) );
        ?>
        <div id="br-checkout-wrap">

            <p style="margin-bottom:.5em"><strong>Choose your cryptocurrency:</strong></p>

            <!-- Custom dropdown -->
            <div class="br-dropdown" id="br-dropdown" role="combobox" aria-expanded="false" aria-haspopup="listbox" tabindex="0">
                <div class="br-dropdown-trigger" id="br-dropdown-trigger">
                    <span class="br-dropdown-preview" id="br-dropdown-preview">
                        <span class="br-dropdown-placeholder">Select a cryptocurrency…</span>
                    </span>
                    <span class="br-dropdown-arrow" aria-hidden="true">▾</span>
                </div>
                <ul class="br-dropdown-menu" id="br-dropdown-menu" role="listbox">
                    <?php foreach ( $enabled as $coin => $cfg ) :
                        [ $label, $symbol, $cmc_id ] = self::coin_display_info( $coin, $cfg );
                        $icon = "https://s2.coinmarketcap.com/static/img/coins/64x64/{$cmc_id}.png";
                    ?>
                    <li class="br-dropdown-item" data-coin="<?php echo esc_attr( $coin ); ?>"
                        data-icon="<?php echo esc_url( $icon ); ?>"
                        data-label="<?php echo esc_attr( $label ); ?>"
                        data-symbol="<?php echo esc_attr( $symbol ); ?>"
                        role="option" tabindex="-1">
                        <img src="<?php echo esc_url( $icon ); ?>" alt="" aria-hidden="true">
                        <span class="br-item-label"><?php echo esc_html( $label ); ?></span>
                        <span class="br-item-symbol"><?php echo esc_html( $symbol ); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <?php if ( ! empty( $locked_names ) ) : ?>
            <p style="margin-top:.5em;font-size:.85em;color:#888">
                <?php echo esc_html( implode( ', ', $locked_names ) ); ?> checkout temporarily unavailable (another payment in progress).
            </p>
            <?php endif; ?>

            <div id="br-pay-area" style="display:none;margin-top:16px">
                <div id="br-waiting" style="display:none"><span class="br-spinner"></span> Verifying payment…</div>
                <div id="br-notice" style="display:none"></div>
                <a href="#" class="button alt" id="br-pay-btn">Pay now</a>
            </div>

            <p style="margin-top:1.5em;font-size:.9em">
                <a href="<?php echo esc_url( $order->get_cancel_order_url() ); ?>">Cancel and return to cart</a>
            </p>
        </div>
        <?php
    }

    public function thankyou_page( $order_id ) {
        $order  = wc_get_order( $order_id );
        if ( ! $order ) return;
        $txhash = $order->get_meta( '_bitrequest_txhash' );
        $coin   = $order->get_meta( '_bitrequest_payment' );

        // Crypto amount paid. received_amount reflects what actually
        // arrived on-chain (preferred); falls back to the quoted amount
        // if the iframe didn't report a settled amount (e.g. some
        // Lightning flows). Symbol comes from the per-order ccsymbol
        // snapshot rather than coin_defs() to handle ERC-20 tokens whose
        // display symbol depends on the row config, not the gateway-key
        // family default.
        $amount = $order->get_meta( '_bitrequest_received_amount' )
               ?: $order->get_meta( '_bitrequest_crypto_amount' )
               ?: '';
        $symbol = strtoupper( $order->get_meta( '_bitrequest_ccsymbol' ) ?: '' );
        if ( $amount !== '' ) {
            echo '<p><strong>Amount paid:</strong> ' . esc_html( $amount );
            if ( $symbol !== '' ) echo ' ' . esc_html( $symbol );
            echo '</p>';
        }

        if ( $txhash ) {
            $l2       = $order->get_meta( '_bitrequest_eth_layer2' ) ?: '';
            $exp_list = self::explorer_urls( $coin, $txhash, $l2 );
            $url      = $exp_list ? $exp_list[0]['url'] : '';
            echo '<p><strong>Transaction:</strong> ';
            echo $url
                ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" title="' . esc_attr( $url ) . '">' . esc_html( $txhash ) . '</a>'
                : '<code>' . esc_html( $txhash ) . '</code>';
            echo '</p>';
        }
    }

    // ─── Assets ───────────────────────────────────────────────────────────────

    public function enqueue_assets() {
        if ( ! is_checkout_pay_page() ) return;
        global $wp;
        $order_id = absint( $wp->query_vars['order-pay'] ?? 0 );
        if ( ! $order_id ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_payment_method() !== $this->id ) return;

        $br_base = $this->get_br_base();

        // Bitrequest PWA libs (in dependency order)
        wp_enqueue_script( 'br-sjcl',         $br_base . 'assets/js/lib/sjcl.js',                 [],          null, true );
        wp_enqueue_script( 'br-crypto-utils',  $br_base . 'assets/js/lib/crypto_utils.js',          ['br-sjcl'], null, true );
        wp_enqueue_script( 'br-bip39-utils',   $br_base . 'assets/js/lib/bip39_utils.js',           ['br-crypto-utils'], null, true );
        wp_enqueue_script( 'br-xmr-utils',     $br_base . 'assets/js/lib/xmr_utils.js',             ['br-crypto-utils'], null, true );
        wp_enqueue_script( 'br-assets',        $br_base . 'assets/js/bitrequest/assets.js',         [], null, true );
        wp_enqueue_script( 'br-checkout-lib',  $br_base . 'assets_js_lib_bitrequest_checkout.js',   [], null, true );
        wp_enqueue_style(  'br-checkout-css',  $br_base . 'assets_styles_lib_bitrequest.css',       [], null );

        // Plugin assets
        wp_enqueue_style(  'bitrequest-checkout', BITREQUEST_WC_URL . 'assets/css/bitrequest-checkout.css', ['br-checkout-css'], BITREQUEST_WC_VERSION );
        wp_enqueue_script( 'bitrequest-derive', BITREQUEST_WC_URL . 'assets/js/bitrequest-derive.js',
            [ 'br-bip39-utils', 'br-crypto-utils' ], BITREQUEST_WC_VERSION, true );
        wp_enqueue_script( 'bitrequest-checkout', BITREQUEST_WC_URL . 'assets/js/bitrequest-checkout.js',
            [ 'bitrequest-derive', 'br-xmr-utils', 'br-checkout-lib' ], BITREQUEST_WC_VERSION, true );

        // Coin configs (address + xpub + reserved index per coin)
        $enabled        = $this->get_enabled_coins();
        $defs           = self::coin_defs();
        $coin_data      = [];
        $locked_coins   = [];
        $used_addrs_all = $this->get_used_addresses();

        foreach ( $enabled as $coin => $cfg ) {
            $xpub = $cfg['xpub'] ?? '';

            // ── Single-address coins: skip if another active order is paying ──
            // We only PEEK here — the lock is claimed at click time via AJAX
            // (bitrequest_acquire_lock). That way browsing the dropdown doesn't
            // commit the customer to a coin they haven't picked yet.
            if ( self::needs_checkout_lock( $coin, $xpub ) ) {
                if ( $this->is_checkout_lock_held( $coin, $order_id ) ) {
                    [ $disp_label, , ] = self::coin_display_info( $coin, $cfg );
                    $locked_coins[] = $disp_label;
                    continue; // exclude from dropdown
                }
            }

            // ── xpub coins: reserve a unique index for this order ─────────
            // ETH-family is excluded — we always derive at index 0 (see
            // is_eth_family() docblock for rationale).
            $index = 0;
            if ( $xpub && ! self::is_eth_family( $coin ) ) {
                $index = $this->reserve_xpub_index( $coin, $xpub, $order );
            }

            [ $display_label, , ] = self::coin_display_info( $coin, $cfg );
            $coin_data[ $coin ] = [
                'label'         => $display_label,
                'address'       => $cfg['address'] ?? '',
                'xpub'          => $xpub,
                'index'         => $index,
                'used_addrs'    => self::is_eth_family( $coin ) ? [] : ( $used_addrs_all[ $coin ] ?? [] ),
                // Only the 10-char `nid` the checkout JS actually sends to the
                // iframe — never the full Spark identity private key. This array
                // is printed into the customer-facing page via wp_localize_script.
                'spark_nid'     => substr( (string) ( $cfg['spark_privkey'] ?? '' ), 0, 10 ),
                'lnurl_proxy'   => $cfg['lnurl_proxy']   ?? '',
                'imp'           => $cfg['imp']           ?? '',
                'viewkey'       => $cfg['viewkey']       ?? '',
                'token_slug'    => $cfg['token_slug']    ?? '',
                'token_symbol'  => $cfg['token_symbol']  ?? '',
                'token_cmcid'   => (int) ( $cfg['token_cmcid'] ?? 0 ),
                'l2_chain'      => isset( $cfg['l2_chain'] ) ? (int) $cfg['l2_chain'] : -1,
            ];
        }

        wp_localize_script( 'bitrequest-checkout', 'BR_PARAMS', [
            'ajax_url'       => admin_url( 'admin-ajax.php' ),
            'nonce'          => wp_create_nonce( 'bitrequest_checkout' ),
            'order_id'       => $order_id,
            'payment_secret' => $order->get_meta( '_bitrequest_payment_secret' ),
            'br_url'         => $this->get_br_url(),
            'redirect_url'   => $this->get_return_url( $order ),
            'uoa'            => strtolower( get_woocommerce_currency() ),
            'amount'         => $order->get_total(),
            'order_number'   => $order->get_order_number(),
            'store_name'     => get_bloginfo( 'name' ),
            'show_qr'        => $this->get_option( 'show_qr', 'no' ) === 'yes',
            'coins'          => $coin_data,
            'locked_coins'   => $locked_coins,
        ] );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'wc-settings' ) === false && strpos( $hook, 'woocommerce_page' ) === false ) return;

        $br_base = $this->get_br_base();
        wp_enqueue_script( 'br-sjcl',        $br_base . 'assets/js/lib/sjcl.js',       [], null, true );
        wp_enqueue_script( 'br-crypto-utils', $br_base . 'assets/js/lib/crypto_utils.js', ['br-sjcl'], null, true );
        wp_enqueue_script( 'br-bip39-utils',  $br_base . 'assets/js/lib/bip39_utils.js', ['br-crypto-utils'], null, true );
        wp_enqueue_script( 'br-xmr-utils',    $br_base . 'assets/js/lib/xmr_utils.js',   ['br-crypto-utils'], null, true );
        wp_enqueue_script( 'br-assets',       $br_base . 'assets/js/bitrequest/assets.js', [], null, true );
        // Checkout lib + CSS for Test button → request panel overlay
        wp_enqueue_script( 'br-checkout-lib', $br_base . 'assets_js_lib_bitrequest_checkout.js', [], null, true );
        wp_enqueue_style(  'br-checkout-css', $br_base . 'assets_styles_lib_bitrequest.css',     [], null );
        wp_enqueue_script( 'bitrequest-derive', BITREQUEST_WC_URL . 'assets/js/bitrequest-derive.js',
            [ 'br-bip39-utils', 'br-crypto-utils' ], BITREQUEST_WC_VERSION, true );
        wp_enqueue_script( 'bitrequest-admin', BITREQUEST_WC_URL . 'assets/js/bitrequest-admin.js',
            ['jquery', 'bitrequest-derive', 'br-xmr-utils', 'br-assets', 'br-checkout-lib'], BITREQUEST_WC_VERSION, true );
        wp_localize_script( 'bitrequest-admin', 'BR_ADMIN', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'bitrequest_admin' ),
            'br_url'     => $this->get_br_url(),
            'uoa'        => strtolower( get_woocommerce_currency() ),
            'store_name' => get_bloginfo( 'name' ),
            'show_qr'    => $this->get_option( 'show_qr', 'no' ) === 'yes',
        ] );
    }
}

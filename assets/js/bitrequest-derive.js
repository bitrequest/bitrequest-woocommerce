/**
 * Bitrequest — shared xpub derivation.
 *
 * Single source of truth for turning an xpub/zpub/ypub/Ltub/Mtub/dgub/kpub
 * into a receiving address at a given BIP32 index. Consumed by:
 *   - bitrequest-admin.js  (Test button + address-preview nav)
 *   - bitrequest-checkout.js (producing the customer-facing payment URL)
 *
 * Depends on Bip39Utils + CryptoUtils (bitrequest.github.io PWA libs).
 */
(function () {
    "use strict";

    // ─── Helpers ──────────────────────────────────────────────────────────────

    // Returns true if a string looks like an xpub/zpub/ypub/Ltub/Mtub/dgub/kpub.
    function isXpubLike(val) {
        return /^(xpub|ypub|zpub|Ltub|Mtub|dgub|drkp|kpub)/i.test((val || "").trim());
    }

    // Dynamic-key detector for ERC-20 token rows (bare 'erc20-token' + 'erc20-token-<slug>').
    function isErc20Token(coin) {
        return coin === "erc20-token" || (typeof coin === "string" && coin.indexOf("erc20-token-") === 0);
    }

    // Coins that render an L2 chain select in the admin UI. Every ERC-20
    // token row renders one; plain Ethereum is mainnet-only because
    // etherscan's free API no longer covers native ETH transfers on L2.
    function coinHasL2Select(coin) {
        return isErc20Token(coin);
    }

    // ETH-family: ethereum + every ERC-20 token row. These all live on the
    // same physical address (BIP32 path m/44'/60'/0'/0/0 by default) and most
    // wallets only index that first address on seed import — so we never
    // rotate, we always use index 0, and we lock per-row at checkout to
    // prevent same-asset collisions. The static 'usdt-erc20' / 'usdc-erc20'
    // keys were folded into the dynamic erc20-token-* rows; legacy configs
    // are remapped server-side in get_coin_configs().
    function isEthFamily(coin) {
        return coin === "ethereum" || isErc20Token(coin);
    }

    // ─── Derivation ───────────────────────────────────────────────────────────

    // Derive the receiving address at `index` from an xpub. Version-byte driven
    // when unambiguous (bech32, kaspa, dash…); falls back to coin-name switch.
    //
    // Every ERC-20 token row (erc20-token-<slug>) lives on an Ethereum address.
    function deriveAddrFromXpub(xpub, index, coin) {
        const parsed = Bip39Utils.key_cc_xpub(xpub);
        if (!parsed) throw new Error("Could not parse xpub");
        const derived = Bip39Utils.derive_x({ dpath: "M/0/" + index, key: parsed.key, cc: parsed.cc, vb: parsed.version });
        const pub = derived.key, ver = parsed.version;

        // Version-byte overrides (unambiguous formats)
        if (ver === "04b24746") return coin === "litecoin"
            ? CryptoUtils.pub_to_address_bech32("ltc", pub)
            : CryptoUtils.pub_to_address_bech32("bc", pub);
        if (ver === "019da462") return CryptoUtils.pub_to_address("30", pub);
        if (ver === "02facafd") return CryptoUtils.pub_to_address("1e", pub);
        if (ver === "02fe52cc" || ver === "02fe524c") return CryptoUtils.pub_to_address("4c", pub);
        if (ver === "038f332e") return CryptoUtils.pub_to_kaspa_address(pub);

        // Any ERC-20 token row → ETH address (same BIP32 path, same address)
        if (isErc20Token(coin)) {
            return CryptoUtils.to_checksum_address(
                CryptoUtils.pub_to_eth_address(CryptoUtils.expand_pub(pub))
            );
        }

        // Fallback: coin-name switch
        switch (coin) {
            case "ethereum":
                return CryptoUtils.to_checksum_address(
                    CryptoUtils.pub_to_eth_address(CryptoUtils.expand_pub(pub))
                );
            case "bitcoin-cash": {
                const leg = CryptoUtils.pub_to_address("00", pub);
                return CryptoUtils.pub_to_cashaddr ? CryptoUtils.pub_to_cashaddr(leg) : leg;
            }
            case "litecoin": return CryptoUtils.pub_to_address("30", pub);
            case "dogecoin": return CryptoUtils.pub_to_address("1e", pub);
            case "dash":     return CryptoUtils.pub_to_address("4c", pub);
            case "kaspa":    return CryptoUtils.pub_to_kaspa_address(pub);
            default:         return CryptoUtils.pub_to_address("00", pub);
        }
    }

    // Expose as a global namespace. Not using ES modules because the plugin
    // loads plain <script> tags alongside the PWA libs.
    window.BitrequestDerive = {
        deriveAddrFromXpub: deriveAddrFromXpub,
        isXpubLike:         isXpubLike,
        isErc20Token:       isErc20Token,
        coinHasL2Select:    coinHasL2Select,
        isEthFamily:        isEthFamily
    };
})();

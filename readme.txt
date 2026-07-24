=== Bitrequest for WooCommerce ===
Contributors: bitrequest
Tags: cryptocurrency, bitcoin, lightning, monero, payment gateway
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin, Lightning, Monero, Ethereum and more on WooCommerce. Non-custodial, no KYC, no fees — payments go straight to your own wallet.

== Description ==

Bitrequest is a non-custodial cryptocurrency payment gateway for WooCommerce. You configure your own wallet addresses (or an xpub for a fresh address per order), and payments arrive directly in your wallet. No middleman ever holds the money — not a processor, not Bitrequest.

Payment detection runs client-side through the open-source Bitrequest payment app. The plugin records the transaction against the order and links you straight to a block explorer so you can verify on-chain before fulfilling.

**Supported currencies**

Bitcoin, Lightning Network, Litecoin, Dogecoin, Dash, Bitcoin Cash, Kaspa, Monero, Nano, Nimiq, and Ethereum — plus any ERC-20 token (USDT, USDC, and others) on Ethereum, Arbitrum, Polygon, BNB Smart Chain, and Base.

**Key features**

* Non-custodial — funds go directly to your wallet, never through a third party.
* No fees charged by the plugin; you pay only standard network fees.
* No account, no sign-up, no KYC.
* HD wallet (xpub) support — a fresh receiving address for every order.
* Per-order payment verification with block-explorer links and confirmation guidance.
* Optional webhook (JSON POST) when a payment is detected.
* Compatible with WooCommerce HPOS and the Block Checkout, as well as classic checkout.

**External service**

This plugin loads the Bitrequest payment app and its libraries from a Bitrequest-hosted origin (by default `https://bitrequest.github.io`), and opens that app in a checkout window so customers can pay. Payment detection and cryptographic address derivation happen there, in the customer's browser. No order data, customer data, or analytics are sent to Bitrequest — the app only reads the public blockchain to detect incoming payments.

You can point the plugin at your own self-hosted copy of the Bitrequest app via the "Bitrequest URL" setting. The app is open source: https://github.com/bitrequest/bitrequest.github.io

**Note on Monero**

Monero payment detection requires your wallet's *secret view key*, which the plugin passes to the payment app so it can scan the blockchain for your incoming payments. A view key can only *read* incoming transactions — it can never spend or move funds. However, it is included in the checkout page your customers load, so treat it as public: anyone who reaches your checkout can, in principle, see incoming payments to that Monero address. Use a dedicated Monero account for your shop if that matters to you. All other supported currencies detect payments from public blockchain data only and require no key of any kind.

For Lightning, the plugin talks to a payment proxy that you configure and control (your own node, or a service you choose). Ethereum and ERC-20 payment detection relies on public blockchain indexers (such as Etherscan/Alchemy), consistent with how the wider Ethereum ecosystem operates. Funds remain non-custodial throughout.

Source code: https://github.com/bitrequest/bitrequest-woocommerce

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`, or install it through the WordPress Plugins screen.
2. Activate the plugin through the "Plugins" screen.
3. Go to WooCommerce → Settings → Payments → Bitrequest → Manage.
4. For each coin you want to accept, enter a wallet address or an xpub, then tick "Enable".
5. Save changes. Use the "Test" link next to a coin to preview the customer checkout and confirm the address is yours.

Lightning requires a connection to a Lightning node or proxy (LND, Core Lightning, LNbits, Spark, or Nostr Wallet Connect). It can be left disabled if you only want on-chain payments.

== Frequently Asked Questions ==

= Does the plugin charge fees? =

No. Payments go directly from the customer's wallet to yours. You pay only standard blockchain network fees.

= Is it custodial? =

No. You configure your own wallet addresses or xpub. Bitrequest never holds, routes, or has access to your funds.

= What data is sent to Bitrequest? =

None about your orders or customers. The payment app runs in the customer's browser and reads the public blockchain to detect payments. There is no analytics or telemetry. You can also self-host the app.

= Can I accept stablecoins? =

Yes. Add USDT, USDC, or other ERC-20 tokens in the settings, on Ethereum mainnet or a Layer 2 (Arbitrum, Polygon, BSC, Base).

= Does Monero need my private key? =

It needs your secret *view* key, which can only read incoming transactions — it can never spend funds. Because it is included in the checkout page, treat it as public and consider using a dedicated Monero account for your shop. No other supported currency requires a key.

= How are refunds handled? =

Manually, from your wallet to an address the customer provides. Because funds are non-custodial, only you can move them.

= Do I need to wait for confirmations before shipping? =

The plugin shows a recommended confirmation count per coin on each order and links you to a block explorer to verify before you fulfill.

== Changelog ==

= 0.1.0 =
* Initial public release.

== Upgrade Notice ==

= 0.1.0 =
Initial public release.

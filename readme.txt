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

Source code: https://github.com/bitrequest/bitrequest-woocommerce

== External services ==

This plugin relies on the following external services. No customer data is sent to any of them.

**1. Bitrequest payment app (bitrequest.github.io)**

What it is: the open-source Bitrequest payment application, which performs cryptographic address derivation and detects incoming payments by reading public blockchain data. It provides the checkout the customer interacts with.

What is sent and when: when a customer reaches the payment step, the plugin loads the app's JavaScript and CSS from this origin and opens the app in a checkout window. The request URL carries the order number, the store name, the amount, the chosen cryptocurrency and the receiving address derived for that order. No customer name, email, address or other personal data is transmitted. Loading the assets exposes the visitor's IP address and user agent to the host, as with any externally hosted resource.

Why it is required: the app and its libraries must be served from the same origin the checkout window points at, because the plugin verifies the window's origin before accepting a payment result from it. Splitting them would break that check.

Self-hosting: the "Bitrequest URL" setting accepts any host serving an unmodified build, so you can point the plugin at your own copy and remove this dependency entirely.

Provider: Bitrequest. Terms and conditions: https://github.com/bitrequest/bitrequest.github.io/wiki/Terms-and-conditions - Privacy / disclaimer: https://www.bitrequest.io/privacy/

**2. CoinMarketCap image CDN (s2.coinmarketcap.com)**

What it is: a public CDN serving cryptocurrency logo images.

What is sent and when: coin and token icons are loaded in the WordPress admin only - on the gateway settings screen, the orders list, the order edit screen and the payments overview. Loading an image exposes the logged-in administrator's IP address and user agent to CoinMarketCap. No order, store or customer data is sent, and no request is made from any customer-facing page.

Why it is required: the ERC-20 token picker covers roughly a thousand tokens, so their icons cannot practically be bundled with the plugin. Customer-facing pages use only icons bundled inside the plugin, so a shopper's browser never contacts this service.

Provider: CoinMarketCap Mgmt Limited. Terms of use: https://coinmarketcap.com/terms/ - Privacy policy: https://coinmarketcap.com/privacy/

**3. Lightning proxy (optional, merchant-configured)**

Only used if you enable Lightning. The plugin sends invoice status lookups to the proxy URL you enter in the settings - a host you choose and control, such as your own node or a service you have an account with. Requests contain the Lightning payment identifier for the order and nothing else. No proxy is contacted if Lightning is left disabled, and the plugin has no default proxy.

**4. Webhook URL (optional, merchant-configured)**

If you set a Webhook URL, the plugin POSTs order and transaction data to that address when a payment is detected. This is your own endpoint; leave the field empty and no request is made.

**Note on block explorers**

Explorer links shown in the admin and in order emails are ordinary hyperlinks. Nothing is sent to a block explorer unless someone clicks one.

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

= Does the plugin send anything to third parties? =

Customer-facing pages contact only the Bitrequest payment app, which is required to display the checkout and detect payment; it can be self-hosted to remove even that. Coin icons in the WordPress admin are loaded from CoinMarketCap. See the "External services" section for full details.

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

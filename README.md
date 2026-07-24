# Bitrequest for WooCommerce

Accept Bitcoin, Lightning, Monero, Ethereum and more on your WooCommerce store. No middlemen, no monthly fees, no KYC.

> Payments go straight to your wallet. Nobody else holds your money — not us, not a payment processor, not anyone.

---

## What you get

- **11 cryptocurrencies + Lightning Network** out of the box: Bitcoin, Lightning, Litecoin, Dogecoin, Dash, Bitcoin Cash, Kaspa, Monero, Nano, Nimiq, and Ethereum. Plus any ERC-20 token (USDT, USDC, etc.) on Ethereum, Arbitrum, Polygon, BNB Smart Chain, and Base.
- **Non-custodial.** You configure your own wallet addresses. Payments arrive directly. Nobody else can freeze, seize, or skim your funds.
- **No fees from us.** You pay normal blockchain network fees and that's it. We don't take a cut.
- **No accounts, no KYC.** No sign-up, no identity verification, no terms-of-service that can change next week.
- **Instant payment notifications.** When a customer pays, you see it in your WooCommerce orders within seconds.
- **Customer-friendly checkout.** Customers scan a QR code or click to open their wallet. Pay, done.

---

## Quick start

### 1. Install the plugin

Either:
- Download the latest release from [code / Download ZIP](https://github.com/bitrequest/bitrequest-woocommerce) and upload the zip via **WordPress → Plugins → Add New → Upload Plugin**, or
- Install directly from your WordPress admin.

Activate the plugin.

### 2. Set up your first coin

Go to **WooCommerce → Settings → Payments → Bitrequest → Manage**.

You'll see a list of supported cryptocurrencies. Pick one to start with — Bitcoin is a good default.

For each coin you want to accept, you have two options:

**Option A — Use an xpub (recommended).** An xpub is a special "watch-only" key from your wallet that lets the plugin generate a fresh receiving address for every order, without ever knowing your private keys. Most modern wallets (Sparrow, Electrum, BlueWallet, Trezor, Ledger) can export an xpub. Paste it into the field and the plugin handles the rest.

**Option B — Use a single address.** Simpler but less private — every customer sees the same address. Fine for low-volume shops.

Tick the "Enable" checkbox for that coin. Click **Save changes**.

### 3. Test it

Click the **Test** link next to any enabled coin. A checkout window opens showing what your customers will see. You don't have to actually pay — just verify the address is yours.

### 4. (Optional) Set up Lightning

Lightning lets you accept Bitcoin payments instantly with near-zero fees. Setting it up is more involved than the other coins because Lightning needs a connection to a Lightning node.

If you already run a Lightning node (LND, Core Lightning, LNbits, Spark, or use a Nostr Wallet Connect-compatible wallet like Alby), enter your node's connection details in the Lightning row and pick the matching implementation from the dropdown.

If you don't have a Lightning node yet, the easiest starting points are:
- [Alby Hub](https://albyhub.com/) — self-hosted, simple, free.
- [LNbits](https://lnbits.com/) — public instance available, or self-host.
- [Voltage](https://voltage.cloud/) — paid managed hosting.

You can leave Lightning disabled and only accept on-chain payments if you prefer.

### 5. You're done

Place an order on your shop and pay it yourself with your favorite wallet to confirm everything works end-to-end.

---

## How customers pay

When a customer reaches checkout and selects "Cryptocurrency" as their payment method:

1. They click **Place order**.
2. A clean checkout window opens showing a QR code and the receiving address.
3. They scan with their wallet (or click "Open in wallet" on mobile) and pay.
4. The window detects the payment and confirms it.
5. They land on your normal "Order received" page.

The whole flow happens in seconds. Customers don't need an account with us, don't need to sign up anywhere, don't get tracked across stores.

---

## Verifying payments before shipping

Cryptocurrency payments work differently from credit cards. There's no chargeback system — once a payment is on the blockchain and confirmed, it's yours. But a customer's wallet might broadcast a transaction that the blockchain hasn't yet locked in (called "confirmations"), and in rare cases such a transaction can fail to be included.

For this reason, the plugin recommends waiting a small number of confirmations before shipping physical goods. The recommendations are built in:

| Cryptocurrency | Wait for |
|---|---|
| Lightning, Nano, Dash | Instant — safe to ship right away |
| Bitcoin, Bitcoin Cash, Ethereum, Monero, ERC-20 tokens | 1 confirmation (~10 minutes for Bitcoin) |
| Litecoin | 2 confirmations (~5 minutes) |
| Dogecoin | 6 confirmations (~6 minutes) |
| Kaspa, Nimiq | 10 confirmations (~10 seconds for Kaspa) |

When you open an order in your WordPress admin, you'll see a reminder telling you exactly how many confirmations to wait for. You can click straight from the order to a blockchain explorer to verify the payment.

When you change the order status to "Processing" or "Completed," the plugin will ask you once to confirm you've checked the blockchain. After that, it leaves you alone for that order.

For digital goods (downloads, software, digital art) where you can revoke access if needed, you can ship immediately — the verification reminders are still helpful but lower-stakes.

---

## What's where in the admin

After installing, here's what you'll find:

**WooCommerce → Settings → Payments → Bitrequest → Manage**
The main settings. Configure your wallet addresses and pick which coins to accept.

**WooCommerce → Orders**
Cryptocurrency orders show a small coin icon and a clickable transaction hash. Click the hash to open the blockchain explorer and verify the payment.

**Order edit page (sidebar)**
The "Bitrequest" box shows the full payment details: which coin was used, the amount paid, the receiving address, the transaction hash, and links to one or more blockchain explorers for verification.

**Customer emails**
Your customers receive their normal WooCommerce order confirmation email, with the cryptocurrency amount paid and a link to the blockchain transaction added automatically.

---

## Frequently asked questions

**Do you charge any fees?**
No. The plugin is free. You pay only the standard blockchain network fees (which the customer typically pays). We don't take a percentage, and no money flows through us — it goes from your customer's wallet directly to yours.

**What if a customer underpays or overpays?**
The order is marked as received with the actual amount paid. You can decide how to handle it (refund the difference, ship anyway, contact the customer). The plugin records exact amounts so you have the full picture.

**Can I refund a customer?**
Yes, but you'll have to send the refund manually from your wallet to a refund address the customer provides. Crypto refunds are the merchant's responsibility — there's no automatic refund button because nobody but you can move funds from your wallet.

**What happens if the customer's payment confirms after the order timed out?**
The payment still arrives in your wallet. Bitrequest catches late payments and logs them on the order so you can manually mark the order as paid. Late payments are rare — most go through within seconds.

**Can I accept stablecoins like USDT or USDC?**
Yes. Add them as ERC-20 tokens in the settings. You can choose whether to receive them on Ethereum mainnet or on a Layer 2 network (Arbitrum, Polygon, BSC, or Base) for lower fees.

**Do I need a separate wallet for each cryptocurrency?**
You need a wallet that supports each coin you accept. Many merchants use Sparrow or Electrum for Bitcoin, the official Monero wallet for XMR, MetaMask or Rabby for Ethereum/ERC-20, and so on. The plugin doesn't manage wallets — it just sends payments to whatever wallet you tell it.

**Is my customer's identity tracked?**
No. We don't see your customers, your orders, or your transactions. The plugin runs entirely on your WordPress site. No analytics, no tracking, no telemetry.

**What if Bitrequest goes away?**
Your wallet is yours. The xpubs and addresses you configured stay valid. If we disappear tomorrow, your existing orders and historical payment data stay intact. You can switch to any other crypto payment plugin at any time and your funds stay where they are — in your wallet.

---

## Honest about tradeoffs

A note worth being upfront about: cryptocurrencies vary in how "decentralized" the supporting infrastructure actually is.

- **Bitcoin, Litecoin, Dogecoin, Bitcoin Cash, Dash, Kaspa, Nano, Monero, Nimiq** — fully open ecosystems. You can verify payments using any of several independent blockchain explorers, and the technically inclined can run their own node. The plugin treats these as first-class citizens.

- **Lightning Network** — works through a payment proxy that the merchant configures. You can run your own Lightning node and proxy, or use someone else's service. Either way, your funds are non-custodial.

- **Ethereum and ERC-20 tokens** — relies on a small number of indexer services (Etherscan, Alchemy) to detect payments. This is unfortunately how the entire Ethereum ecosystem works, not a Bitrequest limitation. We use these services to make payment detection work reliably, and your funds remain non-custodial throughout — but the *detection layer* depends on third parties. For maximum sovereignty, the Bitcoin/Lightning/Monero/Nano paths are cleaner.

We support Ethereum because merchants want to accept it, and because cryptocurrencies are tools — different ones serve different purposes. We're upfront about which paths give you the strongest guarantees.

---

## For developers

Bitrequest is open-source and built around a payment PWA at [bitrequest.io](https://bitrequest.io). The WooCommerce plugin is one integration shell; there's also a [reference implementation for static sites](https://github.com/bitrequest/webshop-integration) and a documented webhook API for custom integrations.

**Repository:** [github.com/bitrequest/bitrequest-woocommerce](https://github.com/bitrequest/bitrequest-woocommerce)

**Self-hosting the PWA.** The Bitrequest URL field in the plugin settings can point at any host serving an unmodified Bitrequest PWA build — github.io is the default, but you can host your own copy on Firebase, IPFS, Arweave, or any web server. The plugin's libraries automatically load from whichever origin you configure.

**Webhook endpoint.** Configure the Webhook URL field in the gateway settings to receive a JSON POST when payments are detected. Useful for triggering external order processing, accounting integrations, or fulfillment scripts. See the [webhook integration guide](https://github.com/bitrequest/webshop-integration) for the payload format.

**Lightning proxy.** The plugin supports LND, Core Lightning, LNbits, Spark, and Nostr Wallet Connect via a single PHP proxy spec. The proxy server is included in the [main Bitrequest repository](https://github.com/bitrequest/bitrequest.github.io).

**Compatible with HPOS** (WooCommerce High-Performance Order Storage) and the new Block Checkout, in addition to the classic checkout.

---

## Support

- **Bug reports and feature requests:** [GitHub Issues](https://github.com/bitrequest/bitrequest-woocommerce/issues)
- **General questions:** [Bitrequest documentation](https://bitrequest.io)
- **Twitter/X:** [@bitrequest](https://twitter.com/bitrequest)

---

## License

GPL v2 or later — same license as WordPress and WooCommerce.

Bitrequest has been built and maintained as a non-profit open-source project since 2019. If you find it useful and want to support continued development, the best things you can do are: tell other merchants, leave an honest review, or contribute code.

Made with care by [Bitrequest](https://bitrequest.io) for everyone who wants the option to accept cryptocurrency without giving up control of their money.

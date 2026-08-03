# Security Policy

Bitrequest for WooCommerce is a non-custodial cryptocurrency payment gateway.
The plugin never holds funds or private spending keys. Merchants configure their
own wallet addresses or an extended public key (xpub); the plugin uses these to
derive receiving addresses and to detect incoming payments by reading public
blockchain data. Payment funds go directly from the customer to the merchant's
own wallet.

We take security seriously and welcome reports from the community.

## Reporting a vulnerability

Please report suspected vulnerabilities **privately**. Do not open a public
GitHub issue, pull request, WordPress.org support-forum thread, or social post
for a security matter, as that exposes users before a fix is available.

- **Email:** security@bitrequest.io
- Please include: a description of the issue, the affected area (checkout,
  payment verification, admin settings, order handling, or a specific
  coin/backend), steps to reproduce or a proof of concept, and the plugin
  version, WordPress version, and WooCommerce version you tested against.

Please give us a reasonable opportunity to investigate and release a fix before
any public disclosure. We will not pursue legal action against researchers who
act in good faith, avoid privacy violations and service disruption, and do not
access, modify, or exfiltrate data that is not their own.

## What to expect

- **Acknowledgement** of your report as soon as we are able. Bitrequest is
  maintained by a single developer, so response times vary; please allow time
  before following up.
- An assessment of the report, and where valid, a fix released through the
  WordPress.org plugin directory and a coordinated disclosure timeline agreed
  with you.
- Credit for the report if you would like it, once a fix is released.

## Scope

In scope:

- The plugin code in this repository: the payment gateway, checkout flow,
  payment-verification endpoint, admin settings, and order handling.

Examples of relevant issues: cross-site scripting or injection in admin or
checkout output, missing or bypassable capability and nonce checks, flaws in the
per-order payment-verification logic (for example, crediting an order without a
valid matching transaction, or reusing one transaction across orders),
information disclosure, and request forgery.

Out of scope:

- The Bitrequest payment application loaded at checkout, and its libraries.
  Report those through the main Bitrequest project's security policy.
- Third-party services the plugin talks to (block explorers, indexers, the
  CoinMarketCap image CDN used in the admin, and any Lightning proxy or webhook
  URL the merchant configures). Report those to the service concerned.
- Vulnerabilities that require an already-compromised WordPress site, server, or
  administrator account. See "Threat model" below.
- Issues in outdated versions, modified copies, or forks. Always test the latest
  release.

## Threat model and merchant responsibility

Because the plugin is non-custodial and server-light, its security depends partly
on the merchant's own environment:

- **No funds or spend keys are stored.** The plugin cannot lose customer funds,
  because it never holds them and never has access to a private spending key.
  The worst-case impact of most plugin-side issues is misreporting of a payment's
  status on an order, not loss of funds.
- **Extended public keys (xpubs).** If a merchant configures an xpub, it is
  stored in the WordPress database. An xpub cannot spend funds, but anyone who
  can read it can derive every receiving address the shop will generate and view
  the associated on-chain history. This is a privacy consideration: the security
  of a stored xpub is bounded by the security of the WordPress site it lives in.
  Merchants who prefer not to store an xpub can configure fixed addresses
  instead.
- **Verify on-chain before fulfilling.** The plugin surfaces a recommended
  confirmation count and a block-explorer link for each order. Payment detection
  is a convenience; for higher-value orders, confirm the transaction on-chain
  before shipping.
- **Site integrity.** The plugin cannot protect data on a WordPress installation
  that is already compromised. Keep WordPress, WooCommerce, other plugins, and
  the server up to date, and restrict administrative access.
- **Customer privacy.** Customer-facing pages are served without contacting
  third-party services for tracking. Coin icons shown to customers are bundled
  with the plugin; the CoinMarketCap image CDN is used only in the WordPress
  admin. External services are documented in the plugin's readme.

## Supported versions

Security fixes are released for the current version published on the WordPress.org
plugin directory. There is no long-term support for older versions or forks.
Always run the latest release.

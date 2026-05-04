/**
 * Bitrequest WooCommerce Checkout
 * Frontend HD wallet derivation using Bitrequest's own libraries.
 */
(function () {
    "use strict";

    const p = window.BR_PARAMS;
    if (!p) return;

    // Shared helpers (from bitrequest-derive.js)
    const isErc20Token    = window.BitrequestDerive.isErc20Token;
    const coinHasL2Select = window.BitrequestDerive.coinHasL2Select;

    const dropdown = document.getElementById("br-dropdown"),
          trigger  = document.getElementById("br-dropdown-trigger"),
          menu     = document.getElementById("br-dropdown-menu"),
          preview  = document.getElementById("br-dropdown-preview"),
          payArea  = document.getElementById("br-pay-area"),
          payBtn   = document.getElementById("br-pay-btn"),
          waitEl   = document.getElementById("br-waiting"),
          noticeEl = document.getElementById("br-notice");

    let selected          = null,
        submitted         = false,
        resolvedAddress   = null,
        resolvedPaymentId = null,
        lightningPid      = null,   // captured from d_obj.pid in buildUrl when coin === "lightning"
        lockAcquired      = false,
        lockRequestInFlight = false;

    // ─── Dropdown ─────────────────────────────────────────────────────────────

    if (trigger) {
        trigger.addEventListener("click", (e) => { e.stopPropagation(); toggleMenu(); });
        trigger.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggleMenu(); }
            if (e.key === "Escape") closeMenu();
        });
    }
    document.addEventListener("click", () => closeMenu());
    if (menu) menu.addEventListener("click", (e) => e.stopPropagation());

    const items = menu ? menu.querySelectorAll(".br-dropdown-item") : [];
    items.forEach((item) => {
        item.addEventListener("click", () => {
            selectCoin(item.dataset.coin, item.dataset.icon, item.dataset.label, item.dataset.symbol);
            closeMenu();
        });
        item.addEventListener("keydown", (e) => {
            if (e.key === "Enter" || e.key === " ") {
                e.preventDefault();
                selectCoin(item.dataset.coin, item.dataset.icon, item.dataset.label, item.dataset.symbol);
                closeMenu();
            }
        });
    });

    if (items.length === 1) {
        const s = items[0];
        // Defer to next tick — manual click works fine, but auto-select firing
        // synchronously at IIFE-exit can race with PWA lib initialization in
        // some environments. setTimeout(0) yields to the event loop first.
        setTimeout(function () {
            selectCoin(s.dataset.coin, s.dataset.icon, s.dataset.label, s.dataset.symbol);
        }, 0);
    }

    // ─── Pay-button interceptor ───────────────────────────────────────────────
    // Single-address coins (lightning/monero excluded — they generate per-order
    // invoices/integrated addresses) need a server-side lock so two customers
    // don't get directed to the same receiving address with the same amount.
    // We acquire the lock at click time rather than at page load — that way
    // the customer only commits to a coin once they've actually picked it.
    //
    // Flow: click → preventDefault → AJAX bitrequest_acquire_lock → on success
    // set lockAcquired=true and re-fire the click. On the second pass the
    // PWA's own document-level click handler (matches .br_checkout) opens the
    // payment iframe.

    if (payBtn) payBtn.addEventListener("click", handlePayClick);

    function handlePayClick(e) {
        if (lockAcquired) return;        // second pass — let PWA's handler take over
        e.preventDefault();
        e.stopPropagation();
        if (!resolvedAddress) return;    // address not resolved yet
        if (lockRequestInFlight) return; // request already in flight

        if (!coinNeedsLock(selected)) {
            // Rotation coins / lightning — nothing to reserve, proceed.
            lockAcquired = true;
            payBtn.click();
            return;
        }

        lockRequestInFlight = true;
        const originalText = payBtn.textContent;
        payBtn.textContent = "Reserving…";
        payBtn.style.opacity = "0.6";
        payBtn.style.pointerEvents = "none";

        ajax({
            action:         "bitrequest_acquire_lock",
            nonce:          p.nonce,
            order_id:       p.order_id,
            payment_secret: p.payment_secret,
            coin:           selected,
        }, function (resp) {
            lockRequestInFlight = false;
            payBtn.textContent = originalText;
            payBtn.style.opacity = "";
            payBtn.style.pointerEvents = "";
            if (!resp.success) {
                showNotice((resp.data && resp.data.message) || "Could not reserve that cryptocurrency.", "error");
                return;
            }
            lockAcquired = true;
            payBtn.click();
        }, function () {
            lockRequestInFlight = false;
            payBtn.textContent = originalText;
            payBtn.style.opacity = "";
            payBtn.style.pointerEvents = "";
            showNotice("Network error — could not reserve coin.", "error");
        });
    }

    // Mirror of WC_Gateway_Bitrequest::needs_checkout_lock() — kept in sync
    // because the server makes the authoritative decision at acquire time;
    // this just avoids unnecessary AJAX for rotation coins.
    function coinNeedsLock(coin) {
        if (!coin) return false;
        if (coin === "lightning") return false;
        const D = window.BitrequestDerive;
        if (D && typeof D.isEthFamily === "function" && D.isEthFamily(coin)) return true;
        const cfg = (p.coins || {})[coin] || {};
        return !cfg.xpub;
    }

    // ─── Release lock on iframe close ─────────────────────────────────────────
    // Wrap the PWA's closeframe so we release the per-coin checkout lock the
    // moment the customer dismisses the payment iframe without paying. The
    // success path (verify_tx) already releases the lock server-side, so we
    // only act when nothing has been submitted yet.
    if (typeof window.closeframe === "function") {
        const origCloseframe = window.closeframe;
        window.closeframe = function () {
            try {
                if (!submitted && lockAcquired && coinNeedsLock(selected)) {
                    lockAcquired = false; // optimistic — server-side release follows
                    ajax({
                        action:         "bitrequest_release_lock",
                        nonce:          p.nonce,
                        order_id:       p.order_id,
                        payment_secret: p.payment_secret,
                        coin:           selected,
                    }, function () {}, function () {});
                }
            } catch (err) {
                console.warn("[Bitrequest WC] release-lock on close failed:", err);
            }
            return origCloseframe.apply(this, arguments);
        };
    }

    function toggleMenu() {
        const open = dropdown.classList.toggle("br-open");
        dropdown.setAttribute("aria-expanded", open ? "true" : "false");
    }
    function closeMenu() {
        dropdown.classList.remove("br-open");
        dropdown.setAttribute("aria-expanded", "false");
    }

    // ─── Coin selection ───────────────────────────────────────────────────────

    function selectCoin(coin, icon, label, symbol) {
        selected = coin;
        resolvedAddress = null;
        resolvedPaymentId = null;
        lockAcquired = false;
        lockRequestInFlight = false;
        // Clear any stale notice (e.g. "currently being paid by another customer"
        // from a previous coin) — the new selection is a fresh attempt.
        if (noticeEl) noticeEl.style.display = "none";
        items.forEach((i) => i.classList.toggle("br-selected", i.dataset.coin === coin));
        preview.innerHTML = "<img src='" + icon + "' alt=''> <strong>" + label + "</strong> <span>" + symbol + "</span>";
        payBtn.classList.remove("br_checkout");
        payBtn.setAttribute("href", "#");
        payBtn.textContent = "Resolving address…";
        payBtn.style.opacity = "0.6";
        payBtn.style.pointerEvents = "none";
        if (payArea) payArea.style.display = "block";

        resolveAddress(coin).then((result) => {
            if (!result || !result.address || result.error) {
                const detail = (result && result.error) ? " — " + result.error : "";
                showNotice("Could not resolve an address for " + label + "." + detail, "error");
                resetPayBtn();
                return;
            }
            resolvedAddress   = result.address;
            resolvedPaymentId = result.payment_id || "";
            payBtn.setAttribute("href", buildUrl(coin, resolvedAddress, resolvedPaymentId));
            payBtn.classList.add("br_checkout");
            payBtn.textContent = "Pay with " + symbol;
            payBtn.style.opacity = "";
            payBtn.style.pointerEvents = "";
        }).catch((err) => {
            console.error("Address resolution error:", err);
            showNotice("Address resolution failed.", "error");
            resetPayBtn();
        });
    }

    function resetPayBtn() {
        // Hide the button entirely — leaving it visible with href="#" and no
        // .br_checkout class produces a button that looks active but does nothing.
        if (payArea) payArea.style.display = "none";
    }

    // ─── Address resolution ───────────────────────────────────────────────────

    function resolveAddress(coin) {
        const cfg = p.coins[coin];
        if (!cfg) return Promise.resolve(null);

        // Lightning: derive BTC on-chain fallback from Bitcoin xpub; "lnurl" if nothing configured
        if (coin === "lightning") {
            const btc = p.coins["bitcoin"];
            if (btc && btc.xpub && typeof Bip39Utils !== "undefined") {
                try {
                    const addr = findUnusedAddress(btc.xpub, btc.index, "bitcoin", btc.used_addrs || []);
                    if (addr) return Promise.resolve({ address: addr });
                } catch (e) { console.warn("BTC fallback for LN failed:", e); }
            }
            if (btc && btc.address) return Promise.resolve({ address: btc.address });
            return Promise.resolve({ address: "lnurl" });
        }

        if (coin === "monero" && cfg.address) return Promise.resolve(makeXmrIntegrated(cfg.address));

        if (cfg.xpub) {
            if (typeof Bip39Utils === "undefined" || typeof CryptoUtils === "undefined") {
                const msg = "PWA libs not loaded (Bip39Utils=" + (typeof Bip39Utils) +
                            ", CryptoUtils=" + (typeof CryptoUtils) + ")";
                console.error("[Bitrequest WC]", msg);
                return Promise.resolve({ address: "", error: msg });
            }
            try {
                const addr = findUnusedAddress(cfg.xpub, cfg.index, coin, cfg.used_addrs || []);
                if (addr) return Promise.resolve({ address: addr });
                return Promise.resolve({
                    address: "",
                    error: "no unused address in scan window (start idx " + (cfg.index || 0) + ", scanned 100)"
                });
            } catch (e) {
                console.error("[Bitrequest WC] xpub derivation threw:", e);
                return Promise.resolve({ address: "", error: e.message || String(e) });
            }
        }

        if (cfg.address) return Promise.resolve({ address: cfg.address });
        return Promise.resolve({ address: "", error: "no xpub or static address configured" });
    }

    // Derive from xpub starting at startIndex, skipping any used addresses
    function findUnusedAddress(xpub, startIndex, coin, usedAddrs) {
        const idx = startIndex || 0;
        const MAX_SCAN = 100;
        for (let i = 0; i < MAX_SCAN; i++) {
            const addr = deriveFromXpub(xpub, idx + i, coin);
            if (!addr) break;
            if (!usedAddrs || usedAddrs.indexOf(addr) === -1) {
                console.log("[Bitrequest] Using address at index " + (idx + i) + " for " + coin + (i > 0 ? " (skipped " + i + " used)" : ""));
                return addr;
            }
        }
        return null;
    }

    // Shared with admin.js — see assets/js/bitrequest-derive.js
    const deriveFromXpub = window.BitrequestDerive.deriveAddrFromXpub;

    function makeXmrIntegrated(mainAddress) {
        try {
            const hex = XmrUtils.base58_decode(mainAddress);
            const pid = XmrUtils.xmr_pid();
            const payload  = "13" + hex.slice(2, 66) + hex.slice(66, 130) + pid;
            const checksum = XmrUtils.fasthash(payload).slice(0, 8);
            const full     = payload + checksum;
            const bytes = [];
            for (let i = 0; i < full.length; i += 2) bytes.push(parseInt(full.slice(i, i + 2), 16));
            return { address: XmrUtils.base58_encode(bytes), payment_id: pid };
        } catch (e) { return { address: mainAddress, payment_id: "" }; }
    }

    // ─── URL → LNURL conversion (uses CryptoUtils primitives from the lib) ────

    function proxyToLnurl(input) {
        if (!input) return "";
        const val = input.trim();
        if (/^lnurl1/i.test(val)) return val.toLowerCase();     // already encoded

        const url   = /^https?:\/\//i.test(val) ? val : "https://" + val;
        const bytes = new TextEncoder().encode(url);            // UTF-8 bytes
        const data  = CryptoUtils.convert_bits(Array.from(bytes), 8, 5, true);
        return CryptoUtils.bech32_encode("lnurl", data);
    }

    // ─── URL builder ──────────────────────────────────────────────────────────

    // Map a Bitrequest gateway coin key to its CoinGecko/PWA payment slug.
    // Static USDT/USDC keys went away; dynamic ERC-20 rows carry their slug
    // in the cfg.token_slug field. Only Lightning still needs a remap — the
    // PWA expects "bitcoin" for LN payments.
    const COIN_PAYMENT_MAP = { "lightning": "bitcoin" };

    function buildUrl(coin, address, payment_id) {
        const cfg = p.coins[coin] || {};
        let d_obj;

        if (coin === "lightning") {
            const imp       = cfg.imp || "spark";
            const proxy     = proxyToLnurl(cfg.lnurl_proxy || "");
            const spark_key = cfg.spark_privkey || "";
            const lid       = randomHex(5);   // unique 10-hex per payment — proxy tracking key
            const pid       = randomHex(8);   // random 16-hex pid per order

            // Capture the lid for the merchant-side status check. The Bitrequest
            // PWA derives `lnd_payment_id` from our `lid` directly when present
            // (see lightning_setup() in assets_js_bitrequest_payments.js, ~L908)
            // and that value becomes the proxy's tracking-file key — used in
            // ln-create-invoice's `id` field, lnd_put's `status` field, and
            // ln-request-status lookups. So this is THE id we need on the order.
            lightningPid = lid;

            // Key order must match Bitrequest spec: ts, n, t, c, imp, lid, proxy[, nid], pid
            d_obj = {
                ts:    Date.now(),
                n:     p.store_name,
                t:     "Order" + (p.order_number || p.order_id),
                c:     0,
                imp:   imp,
                lid:   lid,
                proxy: proxy,
                pid:   pid
            };
            if (imp === "spark" && spark_key) d_obj.nid = spark_key.slice(0, 10);
        } else {
            lightningPid = null;
            d_obj = {
                t:   "Order #" + (p.order_number || p.order_id),
                n:   p.store_name,
                c:   0,                              // always zero-conf at the iframe level
                pid: payment_id || randomHex(8)
            };
            // Monero: include viewkey for automatic on-chain verification
            if (coin === "monero" && cfg.viewkey) d_obj.vk = cfg.viewkey;
            // ERC-20 token row: include l2 chain selector if admin picked one
            if (coinHasL2Select(coin) && typeof cfg.l2_chain === "number" && cfg.l2_chain >= 0) {
                d_obj.l2 = [cfg.l2_chain];
            }
        }

        // ERC-20 token row: payment slug is the admin-selected token (e.g. "chainlink")
        let pay_coin;
        if (isErc20Token(coin)) {
            pay_coin = cfg.token_slug || "";
        } else {
            pay_coin = COIN_PAYMENT_MAP[coin] || coin;
        }
        return p.br_url + "/?payment=" + encodeURIComponent(pay_coin)
            + "&uoa="     + encodeURIComponent(p.uoa)
            + "&amount="  + encodeURIComponent(p.amount)
            + "&address=" + encodeURIComponent(address)
            + "&d="       + btoa(JSON.stringify(d_obj)).replace(/=+$/, "")
            + "&exact=true"
            + (p.show_qr ? "&showqr=true" : "");
    }

    function randomHex(bytes) {
        const arr = new Uint8Array(bytes);
        window.crypto.getRandomValues(arr);
        return Array.from(arr).map((b) => b.toString(16).padStart(2, "0")).join("");
    }

    // ─── Payment callback ─────────────────────────────────────────────────────
    // crossframe() calls result_callback(message.data) where message = {id:"result", data:{txdata:{...}, data:{...}}}

    window.result_callback = function (post_data) {
        console.log("[Bitrequest WC] result_callback fired", JSON.stringify(post_data));
        if (submitted) return;

        const txdata  = post_data && post_data.txdata ? post_data.txdata : null;
        const reqdata = post_data && post_data.data   ? post_data.data   : {};

        if (!txdata) {
            console.warn("[Bitrequest WC] txdata missing from post_data");
            return;
        }

        // txhash may be empty for polling-based coins (Kaspa, Nano); accept if status=paid
        let txhash = txdata.txhash || txdata.hash || "";
        const isPaid = (txdata.status === "paid" || txdata.status === "confirmed");
        if (!txhash && !isPaid) {
            console.warn("[Bitrequest WC] no txhash and status is not paid, skipping", txdata.status);
            return;
        }
        if (!txhash) txhash = (txdata.receiver || "") + "|" + (txdata.requestid || Date.now());

        submitted = true;
        console.log("[Bitrequest WC] submitting payment", txhash, txdata.payment);
        if (waitEl) waitEl.style.display = "flex";

        // Detect whether this was an actual Lightning settlement or fell back
        // to the configured on-chain Bitcoin address. The Bitrequest iframe
        // prefixes Lightning txhashes with "lightning" and leaves on-chain
        // ones bare, so the prefix is the source of truth regardless of what
        // the customer originally picked from the dropdown — wallets without
        // LNURL support will pay to the BTC fallback even though Lightning
        // was selected.
        const settledOnChain = (selected === "lightning") && txhash.indexOf("lightning") !== 0;
        const actualCoin = settledOnChain
            ? "bitcoin"
            : (selected || txdata.payment || "");

        // Lightning's payment_id is our generated `lid` (the proxy tracking
        // key) — only relevant when the payment actually settled on Lightning.
        // Monero's payment_id is the integrated-address pid from
        // makeXmrIntegrated(). All other coins fall back to whatever the
        // iframe sends in reqdata.pid, which is mostly informational.
        const payment_id_for_order = (actualCoin === "lightning")
            ? (lightningPid || "")
            : (resolvedPaymentId || reqdata.pid || "");

        // bolt11 invoice — only when this was an actual Lightning settlement.
        // Source is txdata.lightning.invoice, but the iframe assigns the full
        // ln-invoice-status response object there (see fetchblocks.js
        // process_lightning_payment around L169: `lightning.invoice =
        // invoice_response`), so the actual bolt11 string lives at
        // txdata.lightning.invoice.bolt11. NWC's flow can populate that field
        // with the boolean `true` as a permissions sentinel (see lightning.js
        // around L1004) — guard against that and any other non-bolt11 garbage
        // by requiring the canonical "lnbc" prefix. If the proxy doesn't ship
        // a real bolt11 for this implementation we just don't store one.
        let bolt11 = "";
        if (actualCoin === "lightning" && txdata.lightning && txdata.lightning.invoice) {
            const inv = txdata.lightning.invoice;
            const candidate = (typeof inv === "string") ? inv : inv.bolt11;
            if (typeof candidate === "string" && candidate.toLowerCase().indexOf("lnbc") === 0) {
                bolt11 = candidate;
            }
        }

        ajax({
            action:          "bitrequest_verify_tx",
            nonce:           p.nonce,
            order_id:        p.order_id,
            payment_secret:  p.payment_secret,
            coin:            actualCoin,
            address:         resolvedAddress || txdata.receiver || "",
            payment_id:      payment_id_for_order,
            bolt11:          bolt11,
            txhash:          txhash,
            ccval:           txdata.receivedcc || txdata.receivedamount || txdata.amount || "",
            status:          txdata.status || "",
            confirmations:   txdata.confirmations || "",
            tx_time:         txdata.transactiontime || "",
            currency_name:   txdata.currencyname || "",
            // L2 chain name from the iframe (e.g. "polygon pos", "arbitrum
            // one", "binance smart chain", "base"). PWA sends this as
            // `txdata.ethereum_layer2` for ERC-20 settlements that landed on
            // an L2; empty string for L1 ETH and non-EVM coins. Snapshotted
            // per-order so historical explorer links always point at the
            // correct chain even if the merchant later changes their L2
            // selection on the coin row.
            eth_layer2:      txdata.ethereum_layer2 || "",
            // Amount fields — the PWA sends these in three flavors:
            //   txdata.amount         — original request amount (unit varies
            //                           with how the request was created)
            //   txdata.receivedamount — settled in display-unit (FIAT for
            //                           fiat-priced orders, crypto for crypto-
            //                           priced orders; not always crypto!)
            //   txdata.receivedcc     — settled on-chain in crypto, ALWAYS
            //                           crypto regardless of pricing unit
            //   txdata.fiatvalue      — settled fiat-equivalent at tx time
            // We need crypto-amount-paid (received_amount → for "Amount paid:
            // X.XXXX XNO" rendering) and the fiat-equivalent separately, so
            // we map deliberately rather than reusing the ambiguous fields.
            received_amount: txdata.receivedcc || "",   // crypto amount paid
            fiat_amount:     txdata.fiatvalue || "",    // fiat equivalent at tx time
            ccsymbol:        txdata.ccsymbol || "",
            cmc_id:          txdata.cmcid || "",
            request_id:      txdata.requestid || "",
            pending:         txdata.pending || "",
        }, (resp) => {
            if (waitEl) waitEl.style.display = "none";
            if (!resp.success) {
                showNotice((resp.data && resp.data.message) || "Verification failed.", "error");
                submitted = false;
                return;
            }
            const status = resp.data && resp.data.status;
            if (status === "confirmed" || status === "already_paid") {
                closeOverlay();
                showNotice("✓ Payment confirmed! Redirecting in 2 seconds…", "info");
                setTimeout(() => { window.location.href = p.redirect_url; }, 2000);
            } else if (status === "pending") {
                closeOverlay();
                showNotice("Payment detected — awaiting confirmations. Redirecting…", "info");
                setTimeout(() => { window.location.href = p.redirect_url; }, 3000);
            } else {
                closeOverlay();
                showNotice("Payment recorded, pending manual verification. Redirecting…", "info");
                setTimeout(() => { window.location.href = p.redirect_url; }, 3000);
            }
        }, () => {
            if (waitEl) waitEl.style.display = "none";
            showNotice("Server error.", "error");
            submitted = false;
        });
    };

    function closeOverlay() {
        if (typeof closeframe === "function") { try { closeframe(); } catch (e) {} }
    }

    function ajax(data, onSuccess, onError) {
        const xhr = new XMLHttpRequest();
        xhr.open("POST", p.ajax_url, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onload = () => { try { onSuccess(JSON.parse(xhr.responseText)); } catch (e) { if (onError) onError(); } };
        xhr.onerror = onError || (() => {});
        xhr.send(Object.keys(data).map((k) => encodeURIComponent(k) + "=" + encodeURIComponent(data[k])).join("&"));
    }

    function showNotice(msg, type) {
        if (!noticeEl) return;
        noticeEl.className = "br-notice br-notice--" + (type || "info");
        noticeEl.textContent = msg;
        noticeEl.style.display = "block";
    }

})();

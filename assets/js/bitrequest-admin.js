/**
 * Bitrequest WooCommerce Admin
 * - Seed phrase → auto-fill all xpubs + Spark key
 * - Hybrid address/xpub fields with paginated address preview
 * - Paste/submit address regex validation
 */
(function ($) {
    "use strict";

    // ─── Constants ─────────────────────────────────────────────────────────────

    // Kaspa isn't in Bip39Utils.bip32_configs, so we keep a local config for it
    const KASPA_CONFIG = {
        root_path: "m/44'/111111'/0'/0/",
        prefix: { pub: 0, pubx: 59716398, privx: 59715316 },
        pk_vbytes: { wif: 128 }
    };

    // Account-level derivation paths per coin (used for seed → xpub generation).
    // Dynamic erc20-token-<slug> keys resolve via isErc20Token() → ethereum path.
    const ACCOUNT_PATHS = {
        "bitcoin":      "m/84'/0'/0'",
        "litecoin":     "m/84'/2'/0'",
        "dogecoin":     "m/44'/3'/0'",
        "dash":         "m/44'/5'/0'",
        "ethereum":     "m/44'/60'/0'",
        "bitcoin-cash": "m/44'/145'/0'",
        "kaspa":        "m/44'/111111'/0'"
    };

    // Full derivation paths for display in the address browser
    const COIN_DERIV_PATHS = {
        "bitcoin":      "m/84'/0'/0'/0/",
        "litecoin":     "m/84'/2'/0'/0/",
        "dogecoin":     "m/44'/3'/0'/0/",
        "dash":         "m/44'/5'/0'/0/",
        "ethereum":     "m/44'/60'/0'/0/",
        "bitcoin-cash": "m/44'/145'/0'/0/",
        "kaspa":        "m/44'/111111'/0'/0/"
    };

    // Map xpub version bytes → [purpose, coin_type] for dynamic path detection
    const XPUB_VERSION_MAP = {
        '0488b21e': ["44'", "0'"],      // xpub — Bitcoin legacy
        '049d7cb2': ["49'", "0'"],      // ypub — Bitcoin P2SH-segwit
        '04b24746': ["84'", null],      // zpub — native segwit (BTC or LTC, coin from context)
        '019da462': ["44'", "2'"],      // Ltub — Litecoin legacy
        '01b26ef6': ["49'", "2'"],      // Mtub — Litecoin P2SH-segwit
        '04b2430c': ["84'", "2'"],      // zpub — Litecoin native segwit (some wallets)
        '02facafd': ["44'", "3'"],      // dgub — Dogecoin
        '02fe52cc': ["44'", "5'"],      // drkp — Dash
        '02fe524c': ["44'", "5'"],      // drks — Dash alternate
        '0295b43f': ["44'", "145'"],    // xpub — Bitcoin Cash
        '038f332e': ["44'", "111111'"], // kpub — Kaspa
        '0488ade4': ["44'", "60'"]      // xprv — Ethereum
    };

    const COIN_TYPE_MAP = {
        'bitcoin': "0'", 'litecoin': "2'", 'dogecoin': "3'", 'dash': "5'",
        'ethereum': "60'", 'bitcoin-cash': "145'", 'kaspa': "111111'"
    };

    // Address regex patterns (from Bitrequest PWA config)
    const ADDRESS_REGEX = {
        'bitcoin':      /^([13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[ac-hj-np-zAC-HJ-NP-Z02-9]{11,71})$/,
        'litecoin':     /^([LM][a-km-zA-HJ-NP-Z1-9]{26,33}|ltc1[a-zA-HJ-NP-Z0-9]{26,39})$/,
        'dogecoin':     /^D[5-9A-HJ-NP-U][1-9A-HJ-NP-Za-km-z]{32}$/,
        'dash':         /^X[1-9A-HJ-NP-Za-km-z]{33}/,
        'bitcoin-cash': /(q|p)[a-z0-9]{41}/,
        'ethereum':     /^0x[a-fA-F0-9]{40}$/,
        'nano':         /^(xrb|nano)_([a-z1-9]{60})$/,
        'monero':       /^[48](?:[0-9AB]|[1-9A-HJ-NP-Za-km-z]{12}(?:[1-9A-HJ-NP-Za-km-z]{30})?)[1-9A-HJ-NP-Za-km-z]{93}$/,
        'kaspa':        /^(kaspa):([a-z0-9]{50})/,
        'nimiq':        /^NQ[0-9]{2}[0-9A-HJ-NP-VXY]{32}$/
    };

    // Monero secret viewkey — 64 hex chars (uses check_vk pattern from xmr_utils.js)
    const VIEWKEY_REGEX = /^[a-fA-F0-9]{64}$/;

    // Cryptographically verify viewkey matches a Monero address.
    // Derives public viewkey from private viewkey (scalar * G on Ed25519)
    // and compares against the public viewkey embedded in the address.
    // Mirrors the PWA's verify_viewkey function exactly — uses hex-in API.
    // Returns true/false, or null if libs aren't loaded (caller treats null as failure).
    function verifyViewkeyMatchesAddress(address, viewkey) {
        if (typeof XmrUtils === "undefined" ||
            typeof XmrUtils.base58_decode !== "function" ||
            typeof XmrUtils.ed25519_point_multiply !== "function") {
            console.warn("[bitrequest] XmrUtils not fully loaded — viewkey crypto check skipped");
            return null;
        }
        try {
            const decoded = XmrUtils.base58_decode(address);
            if (!decoded || decoded.length < 130) return false;
            // Address layout (hex): [net_byte(2)][spend_pub(64)][view_pub(64)][...]
            const pub_vk_from_addr = decoded.slice(66, 130).toLowerCase();
            // ed25519_point_multiply takes hex directly, returns a point with .toHex()
            const point            = XmrUtils.ed25519_point_multiply(viewkey);
            const computed_pub_vk  = point.toHex().toLowerCase();
            return computed_pub_vk === pub_vk_from_addr;
        } catch (e) {
            console.warn("[bitrequest] Viewkey verification threw:", e);
            return false;
        }
    }

    // Mutable: current page index per coin (0-based, 5 per page; -1 = hidden)
    const addrPageOf = {};

    // ─── Helpers ───────────────────────────────────────────────────────────────

    // Returns true if string looks like an xpub/zpub/kpub etc
    const isXpubLike      = window.BitrequestDerive.isXpubLike;
    const isErc20Token    = window.BitrequestDerive.isErc20Token;
    const isEthFamily     = window.BitrequestDerive.isEthFamily;
    const coinHasL2Select = window.BitrequestDerive.coinHasL2Select;

    // Coins that accept xpub/zpub/kpub in the address field.
    // Plain-address coins (monero, nano, nimiq, lightning) reject xpub input.
    const XPUB_COINS = new Set([
        "bitcoin", "litecoin", "dogecoin", "dash", "ethereum",
        "bitcoin-cash", "kaspa"
    ]);
    const coinSupportsXpub = (coin) => XPUB_COINS.has(coin) || isErc20Token(coin);

    // Stable key for a field — works for hybrid xpub fields (id) and plain address fields (name)
    function fieldKey(input) {
        const id = input.attr("id");
        if (id && id.indexOf("br-xpub-") === 0) return id.replace("br-xpub-", "");
        if (id && id.indexOf("br-viewkey-") === 0) return "viewkey-" + id.replace("br-viewkey-", "");
        const name = input.attr("name") || "";
        const m = name.match(/^br_coin\[([^\]]+)\]\[address\]$/);
        return m ? m[1] : "_unknown";
    }

    function validateAddressForCoin(coin, val) {
        if (!val) return true;                               // empty allowed
        if (isXpubLike(val)) return coinSupportsXpub(coin);  // reject xpub on plain-address coins
        const rx = ADDRESS_REGEX[coin] || (isErc20Token(coin) ? ADDRESS_REGEX["ethereum"] : null);
        return rx ? rx.test(val.trim()) : true;
    }

    function showValidationError(input, msg) {
        const err_id = "br-err-" + fieldKey(input);
        $("#" + err_id).remove();
        // Insert error inside the flex .br-addr-row so it appears inline next to the input
        input.after('<span id="' + err_id + '" class="br-err">' + msg + '</span>');
        input.css("border", "1px solid #cc0000");
    }

    function clearValidationError(input) {
        $("#br-err-" + fieldKey(input)).remove();
        input.css("border", "");
    }

    function validateFieldByCoin(input, coin) {
        const val = input.val().trim();
        if (!val) {
            clearValidationError(input);
            return true;
        }
        // Specific error when xpub pasted into a coin that doesn't support it
        if (isXpubLike(val) && !coinSupportsXpub(coin)) {
            showValidationError(input, coin + " does not support xpubs — enter a plain address");
            return false;
        }
        if (validateAddressForCoin(coin, val)) {
            clearValidationError(input);
            return true;
        }
        showValidationError(input, "Invalid " + coin + " address format");
        return false;
    }

    // Derives the display path by reading xpub version bytes dynamically
    function getDerivPath(xpub, coin) {
        const fallbackCoin = isErc20Token(coin) ? "ethereum" : coin;
        if (typeof Bip39Utils === "undefined") return COIN_DERIV_PATHS[fallbackCoin] || "m/44'/0'/0'/0/";
        try {
            const parsed = Bip39Utils.key_cc_xpub(xpub);
            const entry  = XPUB_VERSION_MAP[parsed.version];
            if (entry) {
                const purpose  = entry[0];
                const coinType = entry[1] || COIN_TYPE_MAP[fallbackCoin] || "0'";
                return "m/" + purpose + "/" + coinType + "/0'/0/";
            }
        } catch (e) {}
        return COIN_DERIV_PATHS[fallbackCoin] || "m/44'/0'/0'/0/";
    }

    // Derive the receiving address at index from an xpub (shared with checkout.js)
    const deriveAddrFromXpub = window.BitrequestDerive.deriveAddrFromXpub;

    // Render one page of 5 addresses for a given xpub
    function renderAddrPage(coin, xpub) {
        if (typeof Bip39Utils === "undefined") return;
        const page    = addrPageOf[coin] || 0;
        const start   = page * 5;
        const path    = getDerivPath(xpub, coin);
        const addrDiv = $("#br-addrs-" + coin);
        const prevBtn = $("#br-prev-" + coin);

        try {
            let rows = '<div style="color:#888;font-size:10px;margin-bottom:5px;padding-bottom:4px;' +
                       'border-bottom:1px solid #e8e8e8">(' + path + start + " – " + path + (start + 4) + ')</div>';
            for (let i = start; i < start + 5; i++) {
                const addr = deriveAddrFromXpub(xpub, i, coin);
                rows += '<div style="padding:3px 0;border-bottom:1px solid #f0f0f0;display:flex;gap:8px">' +
                          '<span style="color:#555;white-space:nowrap">' + path + i + "</span>" +
                          '<span style="color:#222">' + addr + "</span>" +
                        "</div>";
            }
            addrDiv.html(rows).show();
            // Prev button: enabled at any page (page 0 click = hide; page 1+ = navigate back)
            prevBtn.css({ opacity: "1", "pointer-events": "auto" });
        } catch (e) {
            addrDiv.html("<span style='color:#cc0000'>Error: " + e.message + "</span>").show();
        }
    }

    // Shared: render addresses for an xpub input
    function renderXpubField(input) {
        const coin = input.attr("id").replace("br-xpub-", "");
        const val  = input.val().trim();
        if (!val) return;
        if (!isXpubLike(val)) {
            $("#br-prev-" + coin + ", #br-next-" + coin).hide();
            $("#br-addrs-" + coin).hide();
            return;
        }
        // ETH-family: convert xpub to its index-0 address inline. We don't
        // rotate ETH/ERC-20 addresses (most wallets only index address 0 on
        // seed import), so the field always stores the actual receiving
        // address. The admin can manually override with a different address.
        if (isEthFamily(coin)) {
            try {
                const addr = deriveAddrFromXpub(val, 0, coin);
                if (addr) input.val(addr).trigger("change");
            } catch (e) {
                console.warn("[Bitrequest] ETH-family derivation failed for " + coin + ":", e);
            }
            return;
        }
        $("#br-prev-" + coin + ", #br-next-" + coin).show();
        addrPageOf[coin] = 0;
        renderAddrPage(coin, val);
    }

    // ─── Lightning checkbox: alert if proxy or imp missing ────────────────────

    $(document).on("change", "#br-enable-lightning", function () {
        if (!$(this).is(":checked")) return;
        const proxy = $("#br-lnurl-proxy").val().trim();
        const imp   = $("#br-ln-imp").val();
        if (!proxy) {
            alert("Please enter your proxy URL in the Proxy field.");
            $(this).prop("checked", false);
            return;
        }
        if (!imp) {
            alert("Proxy is set, but no implementation is selected.\nPlease select your Lightning backend (Spark, LND, etc.).");
            $(this).prop("checked", false);
        }
    });

    // ─── Seed phrase → generate all xpubs + Spark key ─────────────────────────

    $(document).on("click", "#br-derive-btn", function () {
        if (typeof Bip39Utils === "undefined") {
            $("#br-derived-result").html("<span style='color:orange'>Libraries still loading, try again in a moment.</span>");
            return;
        }

        const mnemonic = $("#br-mnemonic").val().trim().replace(/\s+/g, " ");
        const result   = $("#br-derived-result");

        const words = mnemonic.split(" ");
        if (words.length !== 12) {
            result.html("<span style='color:#cc0000'>✗ Exactly 12 words required (" + words.length + " entered).</span>");
            return;
        }
        if (!Bip39Utils.validate_mnemonic(mnemonic)) {
            result.html("<span style='color:#cc0000'>✗ Invalid seed phrase — check for typos or wrong words.</span>");
            return;
        }

        if (!confirm("I have safely written down my 12-word seed phrase and understand that losing it means losing access to all funds.")) {
            return;
        }

        result.html("<span style='color:#666'>Generating xpubs… please wait.</span>");
        const btn = $(this).prop("disabled", true);

        setTimeout(() => {
            try {
                const seed = Bip39Utils.mnemonic_to_seed(mnemonic);
                const root = Bip39Utils.get_rootkey(seed);
                const mk   = root.slice(0, 64);
                const cc   = root.slice(64);

                // Clear seed immediately
                $("#br-mnemonic").val("");

                const filled = [], skipped = [];

                Object.keys(ACCOUNT_PATHS).forEach((coin) => {
                    const input = $("#br-xpub-" + coin);
                    if (!input.length) return;
                    try {
                        const derived = Bip39Utils.derive_x({ dpath: ACCOUNT_PATHS[coin], key: mk, cc: cc });
                        const config = (coin === "kaspa") ? KASPA_CONFIG : Bip39Utils.get_bip32_config(coin);
                        const ext    = Bip39Utils.ext_keys(derived, config || {});
                        if (ext.xpub) {
                            // ETH-family: store the index-0 address directly so
                            // the field shows the actual receiving address.
                            // Other coins: store the xpub for rotation.
                            if (isEthFamily(coin)) {
                                try {
                                    const addr = deriveAddrFromXpub(ext.xpub, 0, coin);
                                    input.val(addr || ext.xpub);
                                } catch (e) {
                                    input.val(ext.xpub);
                                }
                            } else {
                                input.val(ext.xpub);
                                $("#br-prev-" + coin + ", #br-next-" + coin).show();
                                addrPageOf[coin] = -1;
                            }
                            filled.push(coin.toUpperCase());

                            // Mirror Ethereum into all dynamic erc20-token-* rows (same ETH path).
                            // ETH-family stores the index-0 address, not the xpub.
                            if (coin === "ethereum") {
                                let ethAddr = ext.xpub;
                                try {
                                    const a = deriveAddrFromXpub(ext.xpub, 0, "ethereum");
                                    if (a) ethAddr = a;
                                } catch (e) {}
                                $('[id^="br-xpub-erc20-token-"]').each(function () {
                                    const tokInput = $(this);
                                    const tokCoin  = tokInput.attr("id").replace("br-xpub-", "");
                                    tokInput.val(ethAddr);
                                    addrPageOf[tokCoin] = -1;
                                    filled.push(tokCoin.toUpperCase());
                                });
                            }
                        }
                    } catch (e) { skipped.push(coin); }
                });

                // Spark key
                let spark_ok = false;
                try {
                    const spark_keys = Bip39Utils.derive_spark_keys(seed, 1);
                    $("#br-spark-privkey").val(spark_keys.identity.privkey);
                    $("#br-spark-privkey-row").show(); // reveal for copy — field is readonly
                    // do NOT auto-select imp — user picks their own backend
                    spark_ok = true;
                } catch (e) { console.warn("Spark derivation failed:", e); }

                let html = "<span style='color:green'>✓ Filled " + filled.length + " xpubs"
                    + (spark_ok ? " + Spark key" : "") + ":</span> "
                    + "<span style='color:#555'>" + filled.join(", ") + "</span>";
                if (skipped.length) html += "<br><span style='color:orange'>Skipped: " + skipped.join(", ") + "</span>";
                html += "<br><small style='color:#888'>Seed phrase cleared. Settings autosaved.</small>";
                result.html(html);

                // .val() doesn't fire change — kick the autosave manually.
                if (typeof scheduleAutosave === "function") scheduleAutosave();
            } catch (e) {
                result.html("<span style='color:#cc0000'>✗ Error: " + (e.message || e) + "</span>");
            }
            btn.prop("disabled", false);
        }, 60);
    });

    // ─── ERC-20 token picker modal (replaces the old single-select dropdown) ──

    // State
    let brTokenCache      = null;  // deduped/sorted token list from contracts("br_all")
    let brSelectedToken   = null;  // { slug, symbol, cmcid } — picked inside the modal
    let brOptionsFiltered = [];    // currently rendered option list (post-filter)

    // Feature-detect native lazy-loading support (skip icons on Safari <15.4, old browsers)
    const supportsLazyLoad = ("loading" in HTMLImageElement.prototype);

    // Default L2 label map — L1 Ethereum is always monitored, so no L1 option.
    // -1 ('no L2 selected') is the default and renders as the select placeholder.
    const L2_NAMES = { "0": "Arbitrum", "1": "Polygon", "2": "BNB Smart Chain", "3": "Base" };

    // Lazy-load token registry on first open
    function loadTokenRegistry() {
        if (brTokenCache) return brTokenCache;
        if (typeof contracts !== "function") return null;
        let tokens;
        try { tokens = contracts("br_all"); }
        catch (e) { console.warn("[bitrequest] contracts() threw:", e); return null; }
        if (!Array.isArray(tokens) || !tokens.length) return null;

        // Dedupe by slug (name). Order preserved = source order ≈ market cap per PWA convention.
        const seen = new Set();
        brTokenCache = [];
        for (const t of tokens) {
            if (!t || !t.name || seen.has(t.name)) continue;
            seen.add(t.name);
            brTokenCache.push({ slug: t.name, symbol: (t.symbol || "").toLowerCase(), cmcid: t.cmcid || 0 });
        }
        return brTokenCache;
    }

    // Return the token registry minus any tokens already configured (by slug).
    // No reserved slugs anymore: USDT and USDC used to live as static
    // top-level rows but were folded into the dynamic ERC-20 picker for a
    // uniform UX. They appear at the top of the registry (market-cap order)
    // so they're naturally the first two options.
    const RESERVED_TOKEN_SLUGS = new Set();

    function availableTokens() {
        const reg = loadTokenRegistry() || [];
        const taken = new Set();
        $('input[type="hidden"][name$="[token_slug]"]').each(function () {
            const v = ($(this).val() || "").trim();
            if (v) taken.add(v);
        });
        return reg.filter(t => !taken.has(t.slug) && !RESERVED_TOKEN_SLUGS.has(t.slug));
    }

    // Render filtered options list inside the modal
    function renderTokenOptions(filter) {
        const q   = (filter || "").trim().toLowerCase();
        const all = availableTokens();
        brOptionsFiltered = q
            ? all.filter(t => t.symbol.indexOf(q) === 0 || t.slug.indexOf(q) !== -1)
            : all;

        const box = $("#br-erc20-ac-options");
        if (!brOptionsFiltered.length) {
            box.html('<div class="br-option br-empty">— no matching tokens —</div>');
            return;
        }
        const frag = [];
        for (const t of brOptionsFiltered) {
            const icon = (supportsLazyLoad && t.cmcid)
                ? '<img src="https://s2.coinmarketcap.com/static/img/coins/64x64/' + t.cmcid + '.png" loading="lazy" alt="" onerror="this.style.display=\'none\'">'
                : '';
            frag.push(
                '<div class="br-option" data-slug="' + t.slug + '" data-symbol="' + t.symbol + '" data-cmcid="' + t.cmcid + '">'
                + icon
                + '<span>' + t.symbol + ' | ' + t.slug + '</span>'
                + '</div>'
            );
        }
        box.html(frag.join(""));
    }

    function openTokenModal() {
        brSelectedToken = null;
        $("#br-erc20-ac-input").val("");
        $("#br-erc20-ok").prop("disabled", true);
        renderTokenOptions("");
        $("#br-erc20-ac-options").removeClass("br-show");
        $("#br-erc20-modal").addClass("br-show");
        setTimeout(() => $("#br-erc20-ac-input").trigger("focus"), 30);
    }

    function closeTokenModal() {
        $("#br-erc20-modal").removeClass("br-show");
        $("#br-erc20-ac-options").removeClass("br-show");
    }

    // Build the HTML for a new ERC-20 row (mirrors PHP Gateway::render_erc20_row exactly)
    function buildErc20RowHtml(coin, token) {
        const label     = token.slug.replace(/-/g, " ").replace(/\b\w/g, c => c.toUpperCase());
        const symbolUp  = token.symbol.toUpperCase();
        const iconUrl   = token.cmcid ? "https://s2.coinmarketcap.com/static/img/coins/64x64/" + token.cmcid + ".png" : "";
        const iconTag   = iconUrl ? "<img src='" + iconUrl + "' onerror=\"this.style.display='none'\">" : "";

        // New rows default to -1 (no L2). Select shows "— no L2 —" as the picked option.
        let l2Options = "<option value='-1' selected>— no L2 —</option>";
        Object.keys(L2_NAMES).forEach((idx) => {
            l2Options += "<option value='" + idx + "'>" + L2_NAMES[idx] + "</option>";
        });

        return "<tr class='br-erc20-row' data-coin='" + coin + "'>"
            + "<td>" + iconTag + label + " <small style='color:#999'>" + symbolUp + "</small></td>"
            + "<td style='text-align:center'>"
                + "<input type='checkbox' name='br_coin[" + coin + "][enabled]' value='1' checked id='br-enable-" + coin + "' style='opacity:1'>"
            + "</td>"
            + "<td>"
                + "<div class='br-addr-row'>"
                    + "<a href='#' class='br-test-btn' data-coin='" + coin + "'>Test</a>"
                    + "<input type='text' name='br_coin[" + coin + "][address_or_xpub]' value='' placeholder='Address / xpub' id='br-xpub-" + coin + "'>"
                    + "<select name='br_coin[" + coin + "][l2_chain]' class='br-l2-select' style='flex:0 0 70px;width:70px;min-width:70px;max-width:70px;box-sizing:border-box;padding:6px 4px;font-size:12px;height:30px;line-height:1;background:#fff;border:1px solid #8c8f94;border-radius:4px;color:#2c3338'>" + l2Options + "</select>"
                    + "<input type='hidden' name='br_coin[" + coin + "][index]'        value='0' id='br-index-" + coin + "'>"
                    + "<input type='hidden' name='br_coin[" + coin + "][token_slug]'   value='" + token.slug + "'>"
                    + "<input type='hidden' name='br_coin[" + coin + "][token_symbol]' value='" + token.symbol + "'>"
                    + "<input type='hidden' name='br_coin[" + coin + "][token_cmcid]'  value='" + token.cmcid + "'>"
                    + "<button type='button' class='br-delete-btn' data-coin='" + coin + "' title='Remove " + symbolUp + "'>&times;</button>"
                + "</div>"
            + "</td>"
            + "</tr>";
    }

    // Append a new row and pre-fill its address/xpub with the current Ethereum value
    function addErc20Row(token) {
        const coin = "erc20-token-" + token.slug;
        // Guard: duplicate protection (should already be filtered by availableTokens)
        if ($("#br-enable-" + coin).length) return;

        const rowHtml = buildErc20RowHtml(coin, token);
        $(".br-coin-table tbody").append(rowHtml);

        // Pre-fill from the existing Ethereum row. If that holds an xpub
        // (legacy), derive its index-0 address — ETH-family rows always store
        // a static address. The admin can override per token afterwards.
        const ethVal = $("#br-xpub-ethereum").val().trim();
        if (ethVal) {
            let prefill = ethVal;
            if (isXpubLike(ethVal) && typeof Bip39Utils !== "undefined") {
                try {
                    const addr = deriveAddrFromXpub(ethVal, 0, coin);
                    if (addr) prefill = addr;
                } catch (e) {
                    console.warn("[Bitrequest] ERC-20 prefill derivation failed:", e);
                }
            }
            $("#br-xpub-" + coin).val(prefill);
        }

        // ERC-20 rows are ETH-family — no nav buttons, no preview block.
        addrPageOf[coin] = -1;
    }

    // Open modal on "+ Add token" click
    $(document).on("click", "#br-add-erc20-btn", function (e) {
        e.preventDefault();
        openTokenModal();
    });

    // Cancel / OK / backdrop
    $(document).on("click", "#br-erc20-cancel", closeTokenModal);
    $(document).on("click", "#br-erc20-modal", function (e) {
        if (e.target.id === "br-erc20-modal") closeTokenModal();  // backdrop click only
    });
    $(document).on("keydown", function (e) {
        if (e.key === "Escape" && $("#br-erc20-modal").hasClass("br-show")) closeTokenModal();
    });
    $(document).on("click", "#br-erc20-ok", function () {
        if (!brSelectedToken) return;
        addErc20Row(brSelectedToken);
        closeTokenModal();
    });

    // Hamburger toggle: show/hide the full options list
    $(document).on("click", "#br-erc20-ac-toggle", function (e) {
        e.stopPropagation();
        const box = $("#br-erc20-ac-options");
        if (box.hasClass("br-show")) {
            box.removeClass("br-show");
        } else {
            renderTokenOptions($("#br-erc20-ac-input").val());
            box.addClass("br-show");
        }
    });

    // Prevent Enter in the modal input from submitting the WP settings form.
    // Enter picks the first filtered option instead.
    $(document).on("keydown", "#br-erc20-ac-input", function (e) {
        if (e.key === "Enter") {
            e.preventDefault();
            if (brOptionsFiltered.length > 0) {
                $("#br-erc20-ac-options .br-option:not(.br-empty)").first().trigger("click");
            }
        }
    });

    // Autocomplete: typing filters, invalidates any previous selection, opens the list
    $(document).on("input", "#br-erc20-ac-input", function () {
        brSelectedToken = null;
        $("#br-erc20-ok").prop("disabled", true);
        renderTokenOptions($(this).val());
        $("#br-erc20-ac-options").addClass("br-show");
    });

    // Focusing the input opens the dropdown too
    $(document).on("focus", "#br-erc20-ac-input", function () {
        renderTokenOptions($(this).val());
        $("#br-erc20-ac-options").addClass("br-show");
    });

    // Pick a token from the list
    $(document).on("click", ".br-option:not(.br-empty)", function (e) {
        e.stopPropagation();
        const $opt = $(this);
        brSelectedToken = {
            slug:   $opt.data("slug"),
            symbol: ($opt.data("symbol") || "").toString(),
            cmcid:  parseInt($opt.data("cmcid"), 10) || 0
        };
        $("#br-erc20-ac-input").val(brSelectedToken.symbol + " | " + brSelectedToken.slug);
        $("#br-erc20-ac-options").removeClass("br-show");
        $("#br-erc20-ok").prop("disabled", false);
    });

    // Clicks outside the selectbox close the dropdown
    $(document).on("click", ".br-modal", function (e) {
        if (!$(e.target).closest(".br-selectbox").length) {
            $("#br-erc20-ac-options").removeClass("br-show");
        }
    });

    // Delete button on a dynamic erc20-token-* row
    $(document).on("click", ".br-delete-btn", function (e) {
        e.preventDefault();
        const coin = $(this).data("coin");
        const symbol = $(this).attr("title").replace(/^Remove\s+/, "") || "this token";
        if (!confirm("Remove " + symbol + "?")) return;
        // Remove the row and its trailing address-preview div
        const $row = $(this).closest("tr");
        $("#br-addrs-" + coin).remove();
        $row.remove();
        delete addrPageOf[coin];
    });

    // ─── xpub nav buttons: inject on page load ────────────────────────────────
    // PHP already wraps each input in .br-addr-row — we just append nav buttons
    // inside that flex row and add the address-preview block after it.

    $(() => {
        $('[id^="br-xpub-"]').each(function () {
            const input = $(this);
            const coin  = input.attr("id").replace("br-xpub-", "");
            const row   = input.closest(".br-addr-row");
            if (!row.length || $("#br-next-" + coin).length) return; // already injected

            // ETH-family: no nav buttons, no preview block. If the saved value
            // is a legacy xpub, convert it to the index-0 address now so the
            // field shows what will actually receive funds.
            if (isEthFamily(coin)) {
                const val = input.val().trim();
                if (val && isXpubLike(val) && typeof Bip39Utils !== "undefined") {
                    try {
                        const addr = deriveAddrFromXpub(val, 0, coin);
                        if (addr) input.val(addr);
                    } catch (e) {
                        console.warn("[Bitrequest] ETH-family conversion on load failed for " + coin + ":", e);
                    }
                }
                return;
            }

            row.append(
                '<span class="br-nav-group" style="display:inline-flex;gap:4px;flex-shrink:0">' +
                    '<button type="button" id="br-prev-' + coin + '" ' +
                      'class="button button-small br-addr-prev" data-coin="' + coin + '" ' +
                      'style="display:none;opacity:0.35;pointer-events:none;' +
                      'padding:0 7px;font-size:15px;line-height:1">&#8249;</button>' +
                    '<button type="button" id="br-next-' + coin + '" ' +
                      'class="button button-small br-addr-next" data-coin="' + coin + '" ' +
                      'style="display:none;padding:0 7px;font-size:15px;line-height:1">&#8250;</button>' +
                '</span>'
            );

            // Address preview block goes after the row
            row.after(
                '<div id="br-addrs-' + coin + '" style="display:none;margin:6px 0 0 36px;' +
                  'font-family:monospace;font-size:11px;border:1px solid #e0e0e0;' +
                  'border-radius:4px;background:#fafafa;padding:6px 8px;' +
                  'overflow:hidden;box-sizing:border-box;max-width:100%"></div>'
            );

            addrPageOf[coin] = -1; // -1 = not yet shown; 0+ = page index

            if (isXpubLike(input.val())) {
                $("#br-prev-" + coin + ", #br-next-" + coin).show();
            }
        });
    });

    // ─── xpub field handlers ──────────────────────────────────────────────────

    // Show/hide nav buttons on input/change (immediate feedback)
    $(document).on("input change", '[id^="br-xpub-"]', function () {
        const coin = $(this).attr("id").replace("br-xpub-", "");
        if (isEthFamily(coin)) return; // no nav buttons exist for ETH-family rows
        const val  = $(this).val().trim();
        if (!val || !isXpubLike(val)) {
            $("#br-prev-" + coin + ", #br-next-" + coin).hide();
            $("#br-addrs-" + coin).hide();
        } else {
            $("#br-prev-" + coin + ", #br-next-" + coin).show();
        }
    });

    // Render on blur
    $(document).on("blur", '[id^="br-xpub-"]', function () {
        renderXpubField($(this));
    });

    // Render on paste (value available after 50ms)
    $(document).on("paste", '[id^="br-xpub-"]', function () {
        const self = $(this);
        setTimeout(() => {
            const coin = self.attr("id").replace("br-xpub-", "");
            if (validateFieldByCoin(self, coin)) renderXpubField(self);
        }, 50);
    });

    // Hybrid field inline validation
    $(document).on("input", '[id^="br-xpub-"]', function () {
        const coin = $(this).attr("id").replace("br-xpub-", "");
        validateFieldByCoin($(this), coin);
    });

    // Plain address fields (Monero, Nano, Nimiq)
    $(document).on("input paste", 'input[name^="br_coin["][name$="[address]"]', function () {
        const input = $(this);
        const coin  = fieldKey(input);
        setTimeout(() => validateFieldByCoin(input, coin), 50);
    });

    // ─── Navigation buttons ───────────────────────────────────────────────────

    $(document).on("click", ".br-addr-next", function () {
        const coin = $(this).data("coin");
        const xpub = $("#br-xpub-" + coin).val().trim();
        if (!xpub) return;
        const page = (addrPageOf[coin] !== undefined) ? addrPageOf[coin] : -1;
        addrPageOf[coin] = page < 0 ? 0 : page + 1; // -1→0 (first click), 0→1, 1→2, etc.
        renderAddrPage(coin, xpub);
    });

    $(document).on("click", ".br-addr-prev", function () {
        const coin = $(this).data("coin");
        const xpub = $("#br-xpub-" + coin).val().trim();
        const page = addrPageOf[coin] || 0;
        if (page <= 0) {
            // Already at page 0 (or hidden): hide the block and disable prev
            $("#br-addrs-" + coin).hide();
            $("#br-prev-" + coin).css({ opacity: "0.35", "pointer-events": "none" });
            addrPageOf[coin] = -1;
            return;
        }
        addrPageOf[coin] = page - 1;
        renderAddrPage(coin, xpub);
    });

    // ─── Monero viewkey validation ────────────────────────────────────────────

    function validateViewkey(input) {
        const val = input.val().trim();
        if (!val) {
            clearValidationError(input);
            return { valid: true, empty: true };
        }
        if (!VIEWKEY_REGEX.test(val)) {
            showValidationError(input, "Invalid viewkey — must be 64 hex chars");
            return { valid: false, empty: false, reason: "format" };
        }
        // Crypto check: derive pub viewkey from secret and compare to address
        const xmrAddr = $('input[name="br_coin[monero][address]"]').val().trim();
        if (xmrAddr && ADDRESS_REGEX.monero.test(xmrAddr)) {
            const match = verifyViewkeyMatchesAddress(xmrAddr, val);
            if (match === false) {
                showValidationError(input, "Viewkey does not match this Monero address");
                return { valid: false, empty: false, reason: "mismatch" };
            }
            if (match === null) {
                showValidationError(input, "Crypto library not loaded — please refresh the page");
                return { valid: false, empty: false, reason: "libs" };
            }
        }
        clearValidationError(input);
        return { valid: true, empty: false };
    }

    // Input event: silent inline validation as user types
    $(document).on("input", "#br-viewkey-monero", function () {
        const input = $(this);
        setTimeout(() => validateViewkey(input), 50);
    });

    // Paste event: validate AND alert on failure (explicit action → explicit feedback)
    $(document).on("paste", "#br-viewkey-monero", function () {
        const input = $(this);
        setTimeout(() => {
            const r = validateViewkey(input);
            if (!r.valid) {
                const msg = r.reason === "mismatch"
                    ? "This viewkey does not match the Monero address.\n\nMake sure you're pasting the SECRET VIEW KEY (not the spend key or mnemonic)."
                    : r.reason === "format"
                        ? "Invalid viewkey — must be exactly 64 hex characters."
                        : "Crypto library not loaded — please refresh the page and try again.";
                alert(msg);
            }
        }, 50);
    });

    // Re-validate viewkey when the Monero address changes (stale pass becomes stale)
    $(document).on("input paste", 'input[name="br_coin[monero][address]"]', function () {
        const vk = $("#br-viewkey-monero");
        if (vk.val().trim()) setTimeout(() => validateViewkey(vk), 50);
    });

    // ─── Block form submit on any invalid address ─────────────────────────────

    $(document).on("submit", "form", function (e) {
        const invalid = [];

        // Hybrid fields (br-xpub-{coin})
        $('[id^="br-xpub-"]').each(function () {
            const input = $(this);
            const coin  = input.attr("id").replace("br-xpub-", "");
            const val   = input.val().trim();
            if (val && !validateAddressForCoin(coin, val)) {
                invalid.push(coin);
                showValidationError(input, "Invalid " + coin + " address format");
            }
        });

        // Plain address fields (Monero, Nano, Nimiq)
        $('input[name^="br_coin["][name$="[address]"]').each(function () {
            const input = $(this);
            const coin  = fieldKey(input);
            const val   = input.val().trim();
            if (val && !validateAddressForCoin(coin, val)) {
                invalid.push(coin);
                const msg = (isXpubLike(val) && !coinSupportsXpub(coin))
                    ? coin + " does not support xpubs — enter a plain address"
                    : "Invalid " + coin + " address format";
                showValidationError(input, msg);
            }
        });

        // Monero viewkey — required when Monero address is set
        const xmrAddr    = $('input[name="br_coin[monero][address]"]').val().trim();
        const viewkeyInp = $("#br-viewkey-monero");
        if (xmrAddr) {
            const result = validateViewkey(viewkeyInp);
            if (result.empty) {
                showValidationError(viewkeyInp, "Viewkey required when Monero address is set");
                invalid.push("monero viewkey");
            } else if (!result.valid) {
                invalid.push("monero viewkey");
            }
        }

        if (invalid.length) {
            e.preventDefault();
            alert("Cannot save: invalid or missing values for " + invalid.join(", ") + ".\n\nPlease correct the highlighted fields.");
            $("html, body").animate(
                { scrollTop: $('[style*="1px solid #cc0000"]').first().offset().top - 100 },
                300
            );
            return false;
        }
    });

    // ─── Test button: open PWA request panel with current config values ──────

    const BR = window.BR_ADMIN || {};

    function randomHex(bytes) {
        const arr = new Uint8Array(bytes);
        window.crypto.getRandomValues(arr);
        return Array.from(arr).map((b) => b.toString(16).padStart(2, "0")).join("");
    }

    // URL → LNURL via CryptoUtils primitives (matches checkout.js)
    function proxyToLnurl(input) {
        if (!input) return "";
        const val = input.trim();
        if (/^lnurl1/i.test(val)) return val.toLowerCase();
        const url   = /^https?:\/\//i.test(val) ? val : "https://" + val;
        const bytes = new TextEncoder().encode(url);
        const data  = CryptoUtils.convert_bits(Array.from(bytes), 8, 5, true);
        return CryptoUtils.bech32_encode("lnurl", data);
    }

    // Build a test Monero integrated address from the base address
    function makeTestXmrIntegrated(mainAddress) {
        const hex = XmrUtils.base58_decode(mainAddress);
        const pid = XmrUtils.xmr_pid();
        const payload  = "13" + hex.slice(2, 66) + hex.slice(66, 130) + pid;
        const checksum = XmrUtils.fasthash(payload).slice(0, 8);
        const full     = payload + checksum;
        const bytes = [];
        for (let i = 0; i < full.length; i += 2) bytes.push(parseInt(full.slice(i, i + 2), 16));
        return { address: XmrUtils.base58_encode(bytes), payment_id: pid };
    }

    // Resolve the receive address for a coin given the current input value
    function resolveTestAddress(coin, val) {
        // xpub → derive at index 0
        if (isXpubLike(val)) return { address: deriveAddrFromXpub(val, 0, coin) };
        // Monero → integrated address
        if (coin === "monero") return makeTestXmrIntegrated(val);
        // Plain address
        return { address: val };
    }

    // Map a Bitrequest gateway coin key to its CoinGecko/PWA payment slug.
    // Static USDT/USDC keys went away; dynamic ERC-20 rows carry their slug
    // in the row's hidden token_slug input (handled above). Only Lightning
    // still needs a remap — the PWA expects "bitcoin" for LN payments.
    const COIN_PAYMENT_MAP = { "lightning": "bitcoin" };

    function buildTestUrl(coin, address, payment_id) {
        const br_url     = BR.br_url     || "https://app.bitrequest.io";
        const uoa        = BR.uoa        || "eur";
        const store_name = BR.store_name || "Test Store";
        let d_obj;

        if (coin === "lightning") {
            const proxyVal = $("#br-lnurl-proxy").val().trim();
            const imp      = $("#br-ln-imp").val();
            const spark    = $("#br-spark-privkey").val().trim();
            const proxy    = proxyToLnurl(proxyVal);
            d_obj = {
                ts:    Date.now(),
                n:     store_name,
                t:     "Test payment",
                c:     0,
                imp:   imp,
                lid:   randomHex(5),
                proxy: proxy,
                pid:   randomHex(8)
            };
            if (imp === "spark" && spark) d_obj.nid = spark.slice(0, 10);
        } else {
            d_obj = {
                t:   "Test payment",
                n:   store_name,
                c:   0,
                pid: payment_id || randomHex(8)
            };
            // Monero: include viewkey from the current input field
            if (coin === "monero") {
                const vk = $("#br-viewkey-monero").val().trim();
                if (vk) d_obj.vk = vk;
            }
            // L2 chain — every row that renders the select (dynamic ERC-20
            // rows + static USDT/USDC). Plain ethereum is mainnet-only.
            if (coinHasL2Select(coin)) {
                const l2 = parseInt($('select[name="br_coin[' + coin + '][l2_chain]"]').val(), 10);
                if (!isNaN(l2) && l2 >= 0) d_obj.l2 = [l2];
            }
        }

        // ERC-20 token row: payment slug comes from the row's hidden token_slug
        let pay_coin;
        if (isErc20Token(coin)) {
            pay_coin = $('input[name="br_coin[' + coin + '][token_slug]"]').val() || "";
        } else {
            pay_coin = COIN_PAYMENT_MAP[coin] || coin;
        }
        // Read the Show QR checkbox live so toggling it updates Test buttons
        // immediately, without waiting for the autosave round-trip to refresh
        // BR.show_qr. Falls back to the localized value when the checkbox
        // isn't on the page (e.g. during early init before WC renders it).
        const $showQr = $("#woocommerce_bitrequest_show_qr");
        const show_qr = $showQr.length ? $showQr.is(":checked") : !!BR.show_qr;

        return br_url + "/?payment=" + encodeURIComponent(pay_coin)
            + "&uoa="     + encodeURIComponent(uoa)
            + "&amount=1"
            + "&address=" + encodeURIComponent(address)
            + "&d="       + btoa(JSON.stringify(d_obj)).replace(/=+$/, "")
            + "&exact=true"
            + (show_qr ? "&showqr=true" : "");
    }

    // Trigger the PWA's request panel overlay for a given URL.
    // Works with whatever click-interception convention the br_checkout lib uses:
    // we synthesize a hidden <a class="br_checkout" href="..."> and click it.
    function openRequestPanel(url) {
        const a = document.createElement("a");
        a.href = url;
        a.className = "br_checkout";
        a.style.display = "none";
        document.body.appendChild(a);
        a.click();
        setTimeout(() => a.remove(), 200);
    }

    // Test button click
    $(document).on("click", ".br-test-btn", function (e) {
        e.preventDefault();
        const coin = $(this).data("coin");

        // Find the coin's input (hybrid xpub field OR plain address field OR lightning proxy)
        let input;
        if (coin === "lightning") {
            input = $("#br-lnurl-proxy");
        } else if ($("#br-xpub-" + coin).length) {
            input = $("#br-xpub-" + coin);
        } else {
            input = $('input[name="br_coin[' + coin + '][address]"]');
        }

        const val = input.val().trim();

        // Lightning needs proxy URL + imp
        if (coin === "lightning") {
            if (!val) return alert("Please enter a Lightning proxy URL first.");
            if (!$("#br-ln-imp").val()) return alert("Please select a Lightning implementation (Spark, LND, etc.) first.");

            // On-chain fallback: derive from Bitcoin xpub → plain BTC address → "lnurl"
            const btcVal = $("#br-xpub-bitcoin").val().trim();
            let fallback = "lnurl";
            if (btcVal) {
                if (isXpubLike(btcVal)) {
                    try { fallback = deriveAddrFromXpub(btcVal, 0, "bitcoin"); }
                    catch (err) { console.warn("BTC fallback derivation failed:", err); }
                } else if (validateAddressForCoin("bitcoin", btcVal)) {
                    fallback = btcVal;
                }
            }
            openRequestPanel(buildTestUrl(coin, fallback));
            return;
        }

        if (!val) return alert("Please enter a " + coin + " address or xpub first.");

        // Reject xpubs on coins that don't support them
        if (isXpubLike(val) && !coinSupportsXpub(coin)) {
            return alert("Cannot test: " + coin + " does not support xpubs.\n\nEnter a plain " + coin + " address instead.");
        }

        // Validate format (validateAddressForCoin now rejects xpubs on plain-address coins)
        if (!isXpubLike(val) && !validateAddressForCoin(coin, val)) {
            return alert("Invalid " + coin + " address format.\n\nPlease fix the field before testing.");
        }

        // Monero: viewkey must be present and cryptographically valid before testing
        if (coin === "monero") {
            const vkInput = $("#br-viewkey-monero");
            const vkResult = validateViewkey(vkInput);
            if (vkResult.empty) {
                return alert("Cannot test: the secret viewkey is required when a Monero address is set.");
            }
            if (!vkResult.valid) {
                const msg = vkResult.reason === "mismatch"
                    ? "Cannot test: viewkey does not match the Monero address.\n\nMake sure you're using the SECRET VIEW KEY (not the spend key)."
                    : vkResult.reason === "format"
                        ? "Cannot test: viewkey must be exactly 64 hex characters."
                        : "Cannot test: crypto library not loaded — please refresh the page.";
                return alert(msg);
            }
        }

        // ERC-20 token row: require a token_slug (should always be present for dynamic rows)
        if (isErc20Token(coin)) {
            const slug = $('input[name="br_coin[' + coin + '][token_slug]"]').val();
            if (!slug) {
                return alert("Cannot test: this ERC-20 token row is missing its token slug. Please remove it and add again.");
            }
        }

        // Resolve address
        let resolved;
        try {
            resolved = resolveTestAddress(coin, val);
        } catch (err) {
            return alert("Could not derive test address: " + (err.message || err));
        }

        if (!resolved || !resolved.address) {
            return alert("Could not resolve a test address for " + coin + ".");
        }

        openRequestPanel(buildTestUrl(coin, resolved.address, resolved.payment_id || ""));
    });

    // ─── Coin-configs autosave ────────────────────────────────────────────────
    // The settings page has the standard WC "Save changes" button at the
    // bottom for the gateway-level options (Bitrequest URL, confirmations,
    // webhook URL, etc.). The coin table itself autosaves on every change so
    // admins don't have to remember to scroll down and click Save after every
    // edit under "Accepted cryptocurrencies".

    let saveTimer       = null;
    let saveInFlight    = false;
    let savePending     = false;
    const SAVE_DEBOUNCE = 300;

    function scheduleAutosave() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(performAutosave, SAVE_DEBOUNCE);
    }

    function performAutosave() {
        if (!BR.ajax_url || !BR.nonce) return; // not on a settings page
        if (saveInFlight) { savePending = true; return; }
        saveInFlight = true;

        // Serialize every br_coin[*] field currently in the DOM. jQuery
        // .serialize() omits unchecked checkboxes, which is exactly what
        // process_admin_options() expects (missing key = enabled:false).
        const formData = $('[name^="br_coin["]').serialize();

        // Top-level gateway settings that ride along with coin autosave.
        // The PHP handler (bitrequest_handle_save_coin_configs) reads these
        // out of $_POST and persists into woocommerce_bitrequest_settings.
        const showQr = $("#woocommerce_bitrequest_show_qr").is(":checked") ? "yes" : "no";

        const payload  = "action=bitrequest_save_coin_configs"
                       + "&nonce=" + encodeURIComponent(BR.nonce)
                       + "&show_qr=" + encodeURIComponent(showQr)
                       + (formData ? "&" + formData : "");

        showSavingIndicator();

        $.ajax({
            url:    BR.ajax_url,
            method: "POST",
            data:   payload,
        }).done(function (resp) {
            if (resp && resp.success) {
                showSavedIndicator();
            } else {
                showSaveError((resp && resp.data && resp.data.message) || "Save failed.");
            }
        }).fail(function () {
            showSaveError("Network error — changes not saved.");
        }).always(function () {
            saveInFlight = false;
            if (savePending) { savePending = false; scheduleAutosave(); }
        });
    }

    // ─── Saved-indicator UI (small toast, bottom-right) ───────────────────────

    function getIndicator() {
        let el = $("#br-autosave-indicator");
        if (!el.length) {
            el = $('<div id="br-autosave-indicator" style="' +
                'position:fixed;bottom:20px;right:20px;padding:8px 14px;' +
                'border-radius:4px;font-size:13px;color:white;' +
                'box-shadow:0 2px 8px rgba(0,0,0,0.15);z-index:99999;' +
                'opacity:0;transition:opacity 0.2s;pointer-events:none">' +
                '</div>').appendTo("body");
        }
        return el;
    }

    function flashIndicator(text, bg, holdMs) {
        const el = getIndicator();
        el.text(text).css({ background: bg, opacity: 1 });
        clearTimeout(flashIndicator._t);
        flashIndicator._t = setTimeout(() => el.css("opacity", 0), holdMs || 1500);
    }

    function showSavingIndicator() { flashIndicator("Saving…",         "#666",     5000); }
    function showSavedIndicator()  { flashIndicator("✓ Saved",          "#46b450",  1500); }
    function showSaveError(msg)    { flashIndicator("⚠ " + msg,         "#cc0000",  4000); }

    // ─── Wire up listeners ────────────────────────────────────────────────────

    // change covers blur-with-edit on text inputs, toggles on checkboxes, and
    // selections on <select>s. Triggered programmatically by .trigger("change")
    // from the renderXpubField ETH-family conversion path too.
    $(document).on("change", '[name^="br_coin["]', scheduleAutosave);

    // Top-level gateway checkbox that should also autosave (rides through
    // bitrequest_save_coin_configs alongside the coin configs).
    $(document).on("change", "#woocommerce_bitrequest_show_qr", scheduleAutosave);

    // Adding or removing a token row should persist immediately so the slug
    // is registered server-side even if the admin doesn't fill it in yet.
    $(document).on("click", "#br-erc20-ok",      () => setTimeout(scheduleAutosave, 50));
    $(document).on("click", ".br-delete-btn",    () => setTimeout(scheduleAutosave, 50));

    // Reset button for the Bitrequest URL field — restores the canonical PWA
    // host with a confirm dialog. Useful when a self-hosted PWA URL is broken
    // or the merchant wants to revert to the default. Standard WC "Save
    // changes" flow applies; the button sets the field value, doesn't persist
    // on its own. Mirrors get_br_base()'s fallback in the gateway class —
    // keep these two strings in sync.
    $(function () {
        const $input = $("#woocommerce_bitrequest_bitrequest_url");
        if (!$input.length) return;
        const CANONICAL = "https://bitrequest.github.io";
        const $btn = $('<button type="button" class="button" id="br-url-reset" '
                     + 'style="margin-left:6px;vertical-align:baseline">Reset</button>');
        $btn.on("click", function () {
            if (!confirm("Reset checkout URL to bitrequest.github.io?")) return;
            $input.val(CANONICAL).trigger("change").focus();
        });
        $input.after($btn);
    });

})(jQuery);

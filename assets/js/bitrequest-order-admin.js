/**
 * Bitrequest — Order edit page admin JS.
 *
 * Single responsibility for now: handle the "Check Lightning status" button
 * in the order meta box. POSTs to bitrequest_check_ln_status, renders the
 * proxy's response inline below the button.
 *
 * Bigger admin.js (settings page) is loaded on the gateway settings screen
 * only — keeping the order-edit footprint small means we don't pull in the
 * crypto libs, derive lib, or test-button machinery the settings page needs.
 */
( function ( $ ) {
    "use strict";

    if ( typeof BR_ORDER === "undefined" ) return;

    function statusColor( status ) {
        switch ( ( status || "" ).toLowerCase() ) {
            case "paid":     return "#008000";
            case "pending":  return "#b06800";
            case "canceled": return "#cc0000";
            case "waiting":  return "#666";
            default:         return "#222";
        }
    }

    // Format msat → sat with thousands separator. Returns the original
    // string when the value isn't a clean integer (e.g. blank, null).
    function formatSats( msat ) {
        if ( msat === null || msat === undefined || msat === "" ) return "";
        var n = parseInt( msat, 10 );
        if ( isNaN( n ) ) return String( msat );
        return Math.round( n / 1000 ).toLocaleString() + " sat";
    }

    function formatTime( ts ) {
        if ( ! ts ) return "";
        var n = parseInt( ts, 10 );
        if ( isNaN( n ) ) return "";
        if ( n > 1e12 ) n = Math.floor( n / 1000 ); // ms → s
        return new Date( n * 1000 ).toLocaleString();
    }

    function renderResult( $panel, payload ) {
        var d   = ( payload && payload.data ) || {};
        var rows = [];

        // Sync the top-of-meta-box Status row to whatever the proxy reports.
        // Server already updates the underlying _bitrequest_status meta when
        // status is "paid" — this keeps the visible row in sync without a
        // full page reload, and also covers the canceled/pending cases.
        updateLiveStatus( d.status );

        if ( d.status ) {
            rows.push(
                "<tr><td style='color:#666;padding:2px 6px 2px 0'>Status</td>"
              + "<td style='font-weight:600;color:" + statusColor( d.status ) + "'>"
              + escapeHtml( d.status )
              + "</td></tr>"
            );
        }
        if ( d.rqtype )           rows.push( row( "Type",     escapeHtml( d.rqtype ) ) );
        if ( d.amount_paid )      rows.push( row( "Paid",     escapeHtml( formatSats( d.amount_paid ) ) ) );
        if ( d.amount && d.amount !== d.amount_paid ) {
            rows.push( row( "Asked", escapeHtml( formatSats( d.amount ) ) ) );
        }
        if ( d.conf !== undefined && d.conf !== null && d.conf !== "" ) {
            rows.push( row( "Confirms", escapeHtml( d.conf ) ) );
        }
        if ( d.txtime )           rows.push( row( "Tx time",  escapeHtml( formatTime( d.txtime ) ) ) );
        else if ( d.timestamp )   rows.push( row( "Created",  escapeHtml( formatTime( d.timestamp ) ) ) );
        if ( d.transfer_id ) {
            // Spark only — link to sparkscan.io for the on-chain settlement.
            var url = "https://sparkscan.io/tx/" + encodeURIComponent( d.transfer_id );
            rows.push( row( "Transfer",
                "<a href='" + url + "' target='_blank' rel='noopener'>" + escapeHtml( d.transfer_id.slice( 0, 12 ) ) + "…</a>"
            ) );
        }
        if ( d.proxy )            rows.push( row( "Proxy",    escapeHtml( d.proxy ) ) );
        if ( payload && payload.source ) {
            rows.push( row( "Source",   "<code style='font-size:10px'>" + escapeHtml( payload.source ) + "</code>" ) );
        }

        var html = rows.length
            ? "<table style='width:100%;font-size:11px;border-collapse:collapse'>" + rows.join( "" ) + "</table>"
            : "<em style='color:#666'>Empty response from proxy.</em>";

        // The server auto-marks the order paid when ln-invoice-status confirms
        // status=paid and the order is still pre-paid. Surface that here so
        // the merchant knows the order_updated isn't stale UI — and offer a
        // page reload so the WC status badge + meta-box top status row refresh.
        if ( payload && payload.order_updated ) {
            html += "<div style='margin-top:6px;padding:6px 8px;background:#edfaef;border:1px solid #46b450;border-radius:3px;color:#1e6b25;font-size:11px'>"
                  + "✓ Order marked as paid. "
                  + "<a href='#' class='br-ln-reload-link' style='font-weight:600'>Reload</a> to refresh the order status."
                  + "</div>";
        }

        $panel
            .css( "background", "#f6f7f7" )
            .css( "border-color", "#dcdcde" )
            .html( html );
    }

    function row( label, valueHtml ) {
        return "<tr>"
            +    "<td style='color:#666;padding:2px 6px 2px 0;white-space:nowrap'>" + escapeHtml( label ) + "</td>"
            +    "<td style='word-break:break-all'>" + valueHtml + "</td>"
            + "</tr>";
    }

    function escapeHtml( s ) {
        return String( s == null ? "" : s )
            .replace( /&/g, "&amp;" )
            .replace( /</g, "&lt;" )
            .replace( />/g, "&gt;" )
            .replace( /"/g, "&quot;" );
    }

    function showError( $panel, message ) {
        $panel
            .css( "background", "#fcf0f1" )
            .css( "border-color", "#d63638" )
            .html( "<strong style='color:#d63638'>" + escapeHtml( message ) + "</strong>" );
    }

    // Live-update the top-of-meta-box Status row (.br-meta-status) so the
    // merchant sees pending→paid without a page reload. Color matches the
    // result panel's status color so the two read consistently. When the
    // status actually changes (not just a re-click while already paid) we
    // also do a subtle background pulse keyed to the destination color —
    // green for paid, red for canceled, amber for pending. The pulse is a
    // single-pass background transition, no @keyframes needed.
    function updateLiveStatus( newStatus ) {
        if ( ! newStatus ) return;
        var $cell = $( ".br-meta-status" );
        if ( ! $cell.length ) return;

        var prevText = $.trim( $cell.text() ).toLowerCase();
        var nextText = String( newStatus ).toLowerCase();

        $cell.text( newStatus ).css( {
            "color":       statusColor( newStatus ),
            "font-weight": ( nextText === "paid" ) ? "600" : "normal",
        } );

        if ( prevText && prevText !== nextText ) {
            var pulseColor =
                ( nextText === "paid" )     ? "rgba(70, 180, 80, 0.30)" :
                ( nextText === "canceled" ) ? "rgba(214, 54, 56, 0.22)" :
                                              "rgba(176, 104, 0, 0.22)";

            // Snap to the pulse color (no transition), force a reflow so the
            // browser commits that frame, then transition back to transparent.
            // The text-color transition runs in parallel for a smooth handoff
            // from old to new status hue.
            $cell.css( { "transition": "none", "background-color": pulseColor } );
            void $cell[ 0 ].offsetHeight; // reflow
            $cell.css( {
                "transition":       "background-color 1.4s ease-out, color 0.4s ease",
                "background-color": "transparent",
            } );
        }
    }

    $( document ).on( "click", ".br-ln-status-btn", function ( e ) {
        e.preventDefault();
        var $btn   = $( this );
        var $panel = $btn.closest( "p" ).next( ".br-ln-status-result" );
        var oid    = $btn.data( "order-id" );
        if ( ! oid ) return;

        var origText = $btn.text();
        $btn.text( "Checking…" ).css( "pointer-events", "none" ).css( "opacity", 0.6 );
        $panel.show().css( "background", "#f6f7f7" ).css( "border-color", "#dcdcde" )
              .html( "<em style='color:#666'>Querying proxy…</em>" );

        $.post( BR_ORDER.ajax_url, {
            action:   "bitrequest_check_ln_status",
            nonce:    BR_ORDER.nonce,
            order_id: oid,
        } ).done( function ( resp ) {
            if ( resp && resp.success ) {
                renderResult( $panel, resp.data );
            } else {
                var msg = ( resp && resp.data && resp.data.message ) || "Lookup failed.";
                showError( $panel, msg );
            }
        } ).fail( function () {
            showError( $panel, "Network error contacting WordPress." );
        } ).always( function () {
            $btn.text( origText ).css( "pointer-events", "" ).css( "opacity", "" );
        } );
    } );

    // Reload link inside the result panel — surfaced when the server auto-
    // marks the order paid. A full reload is the simplest way to refresh the
    // WC order status badge plus the meta box's top Status row.
    $( document ).on( "click", ".br-ln-reload-link", function ( e ) {
        e.preventDefault();
        window.location.reload();
    } );

    // ── Order-status change gate ────────────────────────────────────────────
    // For Bitrequest orders, transitioning to Processing or Completed is a
    // fulfillment commitment — the merchant is about to ship goods or grant
    // access based on the assumption that payment is final. Surface a
    // confirmation prompt with the address and recommended confirmation
    // count so the merchant can verify on-chain before committing.
    //
    // Skipped for instant-final coins (Lightning, Nano, Dash with InstantSend)
    // where recommended_confirmations is 0 — the dialog adds friction without
    // safety value.
    //
    // Only fires on forward transitions (toward fulfillment). Cancelled,
    // Refunded, Pending payment, and back-to-On-hold all bypass — those are
    // administrative escape hatches and shouldn't be gated.
    //
    // Once the merchant confirms verification, that decision sticks for the
    // life of the order — `_bitrequest_verified_at` meta is set server-side
    // and the dialog won't re-fire on subsequent upstream moves
    // (Processing → Completed). Moving downstream (any → Pending / On hold /
    // Failed / Cancelled / Refunded) clears the flag server-side, so if the
    // order returns to a fulfillment state the gate fires again.
    $(function () {
        var br = window.BR_ORDER && BR_ORDER.bitrequest;
        if ( ! br ) return;                               // not a Bitrequest order
        if ( ! br.recommended_confirmations ) return;     // instant-final coin

        // Tracks whether the merchant has confirmed verification for this
        // order. Initialized from the server-side meta flag and flipped
        // true when they click OK in the gate dialog (a parallel AJAX call
        // persists the same flag server-side so reloads stay consistent).
        var verified = !! br.verified;

        // The status select lives in two places depending on classic-post vs
        // HPOS. Both use #order_status as the field id, so a single selector
        // covers both.
        var $select = $( "#order_status" );
        if ( ! $select.length ) return;

        var prevValue = $select.val();
        var GATED = [ "wc-processing", "wc-completed" ];
        // Track previous value so we can revert on cancel.
        $select.on( "focus", function () { prevValue = $select.val(); } );

        $select.on( "change", function () {
            var nextValue = $select.val();
            if ( nextValue === prevValue ) return;
            if ( GATED.indexOf( nextValue ) === -1 ) {
                prevValue = nextValue;
                return;
            }
            // Already verified for this order — let the change through.
            if ( verified ) {
                prevValue = nextValue;
                return;
            }

            var n         = br.recommended_confirmations;
            var label     = nextValue === "wc-completed" ? "Completed" : "Processing";
            var addrLine  = br.address
                ? "  • Sent to address: " + br.address + "\n"
                : "";
            var msg = "Before marking this order as " + label + ":\n\n"
                    + "Please verify on the blockchain that the payment was:\n"
                    + addrLine
                    + "  • Confirmed with at least " + n + " confirmation"
                    + ( n === 1 ? "" : "s" ) + "\n\n"
                    + "Click OK to confirm you have verified the transaction.\n"
                    + "Click Cancel to keep the current status.";

            if ( ! window.confirm( msg ) ) {
                $select.val( prevValue );
                return false;
            }

            // Merchant confirmed — flip the in-memory flag immediately so
            // subsequent upstream moves in this session don't re-prompt,
            // and persist it server-side so future page loads also skip.
            // Fire-and-forget; if the persistence call fails the worst
            // case is the dialog fires again on next page load — annoying
            // but not destructive.
            verified = true;
            $.post( BR_ORDER.ajax_url, {
                action:   "bitrequest_mark_verified",
                nonce:    BR_ORDER.nonce,
                order_id: br.order_id,
            } );

            prevValue = nextValue;
        } );
    } );

} )( jQuery );

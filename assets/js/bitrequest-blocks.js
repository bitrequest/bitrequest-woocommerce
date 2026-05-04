/**
 * Bitrequest — Block Checkout integration.
 *
 * Registers the gateway with WooCommerce Blocks' PaymentMethodTypeRegistry so
 * the React-based Checkout block exposes it as a payment option. Everything
 * here is render-only: label, description, and an optional icon shown next to
 * the radio. The actual payment flow runs on the order-pay page after the
 * customer clicks Place Order — see bitrequest-checkout.js for that.
 *
 * No build step: depends on globals registered by WC Blocks
 * (wc.wcBlocksRegistry, wc.wcSettings) and core WP (wp.element,
 * wp.htmlEntities). All of those are declared in PHP via
 * wp_register_script(... 'wc-blocks-registry', 'wc-settings', 'wp-element',
 * 'wp-html-entities').
 */
( function () {
    "use strict";

    var registry = window.wc && window.wc.wcBlocksRegistry;
    if ( ! registry || typeof registry.registerPaymentMethod !== "function" ) return;

    var getSetting     = window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting;
    var decodeEntities = window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities;
    var createElement  = window.wp && window.wp.element && window.wp.element.createElement;
    if ( ! getSetting || ! decodeEntities || ! createElement ) return;

    var data  = getSetting( "bitrequest_data", {} );
    var label = decodeEntities( data.title || "Cryptocurrency" );
    var desc  = decodeEntities( data.description || "" );

    function Label( props ) {
        var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
        if ( PaymentMethodLabel ) {
            return createElement( PaymentMethodLabel, { text: label } );
        }
        return label;
    }

    function Content() {
        return createElement( "div", null, desc );
    }

    registry.registerPaymentMethod( {
        name:             "bitrequest",
        label:            createElement( Label, null ),
        ariaLabel:        label,
        content:          createElement( Content, null ),
        edit:             createElement( Content, null ),
        canMakePayment:   function () { return true; },
        paymentMethodId:  "bitrequest",
        supports: {
            features: data.supports || [ "products" ],
        },
    } );
} )();

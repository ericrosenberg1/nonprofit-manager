/**
 * Nonprofit Manager plugin donation form functionality
 */
(function($) {
    'use strict';

    var doc = document;

    /**
     * Safely read a field value.
     *
     * @param {string} id DOM id.
     * @return {string}
     */
    function readFieldValue( id ) {
        var field = doc.getElementById( id );
        return field ? field.value : '';
    }

    function rejectOrder(actions) {
        if (actions && typeof actions.reject === 'function') {
            return actions.reject();
        }
        return Promise.reject();
    }

    // Initialize the donation form handler.
    $( doc ).ready( function() {
        // PayPal email link redirect.
        window.npmpRedirectToPayPal = function() {
            if ( typeof npDonationData === 'undefined' ) {
                return false;
            }

            var amount = readFieldValue( 'np-donation-amount' );
            if ( ! amount || parseFloat( amount ) < npDonationData.min_amount ) {
                alert( 'Minimum donation is $' + npDonationData.min_amount.toFixed( 2 ) );
                return false;
            }

            var email = readFieldValue( 'np-donation-email' );
            if ( ! email || email.indexOf( '@' ) === -1 ) {
                alert( 'Please enter a valid email address' );
                return false;
            }

            var business = $( '#np-paypal-business' ).val();
            var url = new URL( 'https://www.paypal.com/donate' );
            url.searchParams.set( 'business', business );
            url.searchParams.set( 'amount', amount );
            url.searchParams.set( 'currency_code', 'USD' );

            window.open( url.toString(), '_blank' );

            var nonceValue = readFieldValue( 'npmp_paypal_success_nonce' );
            var successUrl = new URL( window.location.href.split( '?' )[0] );
            successUrl.searchParams.set( 'paypal_success', '1' );
            successUrl.searchParams.set( '_wpnonce', nonceValue );

            $.post( npDonationData.ajax_url, {
                action: 'npmp_log_paypal_donation',
                email: email,
                amount: amount,
                frequency: 'one_time',
                npmp_paypal_donation_nonce_field: $( '#npmp_paypal_donation_nonce_field' ).val()
            } );

            setTimeout( function() {
                window.location.href = successUrl.toString();
            }, 1500 );

            return false;
        };

        // Initialize PayPal SDK buttons if they exist.
        if ( typeof paypal !== 'undefined' && $( '#paypal-button-container' ).length ) {
            paypal.Buttons( {
                createOrder: function( data, actions ) {
                    if ( typeof npDonationData === 'undefined' ) {
                        return rejectOrder(actions);
                    }

                    var amount = readFieldValue( 'np-donation-amount' );
                    if ( ! amount || parseFloat( amount ) < npDonationData.min_amount ) {
                        alert( 'Minimum donation is $' + npDonationData.min_amount.toFixed( 2 ) );
                        return rejectOrder(actions);
                    }

                    var email = readFieldValue( 'np-donation-email' );
                    if ( ! email || email.indexOf( '@' ) === -1 ) {
                        alert( 'Please enter a valid email address' );
                        return rejectOrder(actions);
                    }

                    return actions.order.create( {
                        purchase_units: [ {
                            amount: { value: amount }
                        } ]
                    } );
                },
                onApprove: function( data, actions ) {
                    return actions.order.capture().then( function() {
                        if ( typeof npDonationData === 'undefined' ) {
                            return;
                        }

                        var email = readFieldValue( 'np-donation-email' );
                        var amount = readFieldValue( 'np-donation-amount' );
                        var frequencyField = doc.getElementById( 'np-donation-frequency' );
                        var frequency = frequencyField ? frequencyField.value : 'one_time';
                        var nonceField = doc.querySelector( 'input[name=npmp_paypal_donation_nonce_field]' );
                        var nonce = nonceField ? nonceField.value : '';

                        fetch( npDonationData.ajax_url, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams( {
                                action: 'npmp_log_paypal_donation',
                                email: email,
                                amount: amount,
                                frequency: frequency,
                                npmp_paypal_donation_nonce_field: nonce
                            } )
                        } );

                        var nonceValue = readFieldValue( 'npmp_paypal_success_nonce' );
                        var successUrl = new URL( window.location.href.split( '?' )[0] );
                        successUrl.searchParams.set( 'paypal_success', '1' );
                        successUrl.searchParams.set( '_wpnonce', nonceValue );
                        window.location.href = successUrl.toString();
                    } );
                }
            } ).render( '#paypal-button-container' );
        }
    } );
})(jQuery);

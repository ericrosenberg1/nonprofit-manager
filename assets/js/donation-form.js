/**
 * Nonprofit Manager plugin donation form functionality
 */
(function($) {
    'use strict';

    // Initialize the donation form handler
    $(document).ready(function() {
        // PayPal email link redirect
        window.npRedirectToPayPal = function() {
            const amount = document.getElementById("np-donation-amount").value;
            if (!amount || parseFloat(amount) < npDonationData.min_amount) {
                alert("Minimum donation is $" + npDonationData.min_amount.toFixed(2));
                return false;
            }
            
            const email = document.getElementById("np-donation-email").value;
            if (!email || !email.includes('@')) {
                alert("Please enter a valid email address");
                return false;
            }
            
            const business = $('#np-paypal-business').val();
            const url = new URL("https://www.paypal.com/donate");
            url.searchParams.set("business", business);
            url.searchParams.set("amount", amount);
            url.searchParams.set("currency_code", "USD");
            // Open PayPal in new window
            window.open(url.toString(), "_blank");
            
            // Get nonce for success redirect
            const nonceValue = document.getElementById("np_paypal_success_nonce").value;
            const successUrl = new URL(window.location.href.split("?")[0]);
            successUrl.searchParams.set("paypal_success", "1");
            successUrl.searchParams.set("_wpnonce", nonceValue);
            
            // Log the donation via AJAX
            $.post(npDonationData.ajax_url, {
                action: 'np_log_paypal_donation',
                email: email,
                amount: amount,
                frequency: 'one_time',
                np_paypal_donation_nonce_field: $('#np_paypal_donation_nonce_field').val()
            });
            
            // Also redirect the main page after a short delay
            setTimeout(function() {
                window.location.href = successUrl.toString();
            }, 1500);
            
            return false;
        };
        
        // Initialize PayPal SDK buttons if they exist
        if (typeof paypal !== 'undefined' && $('#paypal-button-container').length) {
            paypal.Buttons({
                createOrder: function(data, actions) {
                    const amount = document.getElementById("np-donation-amount").value;
                    if (!amount || parseFloat(amount) < npDonationData.min_amount) {
                        alert("Minimum donation is $" + npDonationData.min_amount.toFixed(2));
                        return;
                    }
                    
                    const email = document.getElementById("np-donation-email").value;
                    if (!email || !email.includes('@')) {
                        alert("Please enter a valid email address");
                        return;
                    }
                    
                    return actions.order.create({
                        purchase_units: [{
                            amount: { value: amount }
                        }]
                    });
                },
                onApprove: function(data, actions) {
                    return actions.order.capture().then(function(details) {
                        const email = document.getElementById("np-donation-email").value;
                        const amount = document.getElementById("np-donation-amount").value;
                        const freqEl = document.getElementById("np-donation-frequency");
                        const frequency = freqEl ? freqEl.value : "one_time";
                        const nonce = document.querySelector("input[name=np_paypal_donation_nonce_field]").value;

                        fetch(npDonationData.ajax_url, {
                            method: "POST",
                            headers: { "Content-Type": "application/x-www-form-urlencoded" },
                            body: new URLSearchParams({
                                action: "np_log_paypal_donation",
                                email: email,
                                amount: amount,
                                frequency: frequency,
                                np_paypal_donation_nonce_field: nonce
                            })
                        });
                        
                        // Get the nonce from the hidden input we'll add
                        const nonceValue = document.getElementById("np_paypal_success_nonce").value;
                        // Create URL with nonce for secure redirect
                        const successUrl = new URL(window.location.href.split("?")[0]);
                        successUrl.searchParams.set("paypal_success", "1");
                        successUrl.searchParams.set("_wpnonce", nonceValue);
                        window.location.href = successUrl.toString();
                    });
                }
            }).render("#paypal-button-container");
        }
    });
})(jQuery);
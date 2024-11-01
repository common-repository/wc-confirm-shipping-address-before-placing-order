(function($){
    'use strict';
    // Escape HTML
    var entityMap = {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '/': '&#x2F;', '`': '&#x60;', '=': '&#x3D;' };
    function escapeHtml(string){
        return String(string).replace(/[&<>"'`=\/]/g, function (s){
            return entityMap[s];
        });
    }
    var wc_csabpo_status = 0;
    // Get the checkout form element
    var checkoutForm = document.querySelector('form.woocommerce-checkout');
    if(!checkoutForm) checkoutForm = document.querySelector('form[name="checkout"]');
    var $checkout_form = $('form.checkout');
    $checkout_form.on('submit', function(e){
        e.preventDefault();
        this.classList.add('processing');
        var $form = $(this);
        var isBlocked = $form.data('blockUI.isBlocked');
        if(1!==isBlocked) $form.block({message:null, overlayCSS:{background:'#fff', opacity:0.6}});
        if(wc_csabpo_status===1 || wc_csabpo_status===2 || wc_csabpo_status===4){
            this.classList.remove('processing');
            if(wc_csabpo_status!==2){
                wc_csabpo_status = 0;
            }
            return true;
        }
        // Show dialog only at status 3
        if(wc_csabpo_status===3){
            show_confirm_dialog();
            return true;
        }
        $.ajax({
            type: 'POST',
            url: wc_csabpo_checkout_i18n.endpoint,
            data: $form.serialize(),
            dataType: 'json',
            success: function(result){
                if(result===1){
                    // Validation errors
                    wc_csabpo_status = 1;
                    $('form.checkout').trigger('submit');
                }
                if(result===0){
                    // No validation errors, ready to show confirmation modal to the user
                    wc_csabpo_status = 3;
                    show_confirm_dialog();
                }
            },
            error: function(){
                // An error occurred, skip the confirmation modal from now on
                wc_csabpo_status = 2; 
                $('form.checkout').trigger('submit');
            }
        });
    });
    function show_confirm_dialog(){
        var input, parent, label, html = '', i, nodes;
        if(wc_csabpo_checkout_i18n.modal.title!=='') html += '<h3>'+wc_csabpo_checkout_i18n.modal.title+'</h3>';
        if(wc_csabpo_checkout_i18n.modal.desc!=='') html += '<p>'+wc_csabpo_checkout_i18n.modal.desc+'</p>';
        html += '<table>';
        // Get shipping address
        // If "Ship to a different address?" was not checked, then use billing fields
        if($('input[name="ship_to_different_address"]').is(":checked")){
            // Ship to different address
            nodes = checkoutForm.querySelectorAll('.woocommerce-shipping-fields__field-wrapper [name^="shipping_"]');
        }else{
            // Ship to billing address
            nodes = checkoutForm.querySelectorAll('.woocommerce-billing-fields__field-wrapper [name^="billing_"]');
        }
        for(i=0; i<nodes.length; i++){
            input = nodes[i];
            parent = nodes[i].parentNode.closest('#'+(input.name)+'_field');
            label = parent.querySelector('[for="'+(input.name)+'"]');
            html += '<tr><th>'+label.innerHTML+':</th><td>'+escapeHtml(input.value)+'</td></tr>';
        }
        html += '</table>';
        html += '<label class="wc-csabpo-confirm">';
        html += '<input style="" type="checkbox" name="wc_csabpo_confirm_shipping_details" />';
        html += wc_csabpo_checkout_i18n.modal.confirm;
        html += '</label>';
        html += '<span class="wc-csabpo-edit-shipping-address">'+wc_csabpo_checkout_i18n.modal.edit+'</span>';
        html += '<span class="wc-csabpo-finalize-order">'+wc_csabpo_checkout_i18n.modal.finalize+'</span>';
        // Create a new div element for the background overlay
        var overlay = document.createElement('div');
        // set the overlay styles
        overlay.className = 'wc-csabpo-overlay';
        // create an iframe element and set its source to google.com
        var modal = document.createElement('div');
        modal.className = 'wc-csabpo-modal';
        modal.innerHTML = html;
        // Add the modal
        overlay.appendChild(modal);
        // Add the overlay (which contains everything)
        document.body.appendChild(overlay);
        $('.wc-csabpo-edit-shipping-address').on('click', function(e){
            wc_csabpo_status = 0;
            $('form.checkout').unblock();
            $('form.checkout').removeClass('processing');
            $('.wc-csabpo-overlay').remove();
        });
        $('.wc-csabpo-finalize-order').on('click', function(e){
            // Check if user confirmed that the shipping address was entered correctly
            if($('input[name="wc_csabpo_confirm_shipping_details"]').is(":checked")){
                // Submit form
                wc_csabpo_status = 4;
                $('.wc-csabpo-confirm').removeClass('wc-csabpo-error');
                $('.wc-csabpo-overlay').remove();
                $('form.checkout').trigger('submit');
                return true;
            }else{
                // Must confirm
                $('.wc-csabpo-confirm').addClass('wc-csabpo-error');
                $.fn.shakeit = function(interval,distance,times){
                    interval = typeof interval=="undefined" ? 100 : interval;
                    distance = typeof distance=="undefined" ? 10 : distance;
                    times = typeof times=="undefined" ? 3 : times;
                    var jTarget = $(this);
                    jTarget.css('position','relative');
                    for(var iter=0;iter<(times+1);iter++){
                        jTarget.animate({ left: ((iter%2==0 ? distance : distance*-1))}, interval);
                    }
                    return jTarget.animate({ left: 0},interval);
                }
                $('.wc-csabpo-confirm').shakeit(100,10,3);
            }
        });
        return false;
    }
})(jQuery);
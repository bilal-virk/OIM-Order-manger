// assets/oim-frontend.js
jQuery(document).ready(function($){
    const form = $('#oim-upload-form');
    const successMessage = $('#oim-success-message');
    const successContent = $('#oim-success-content');
    const errorMessage = $('#oim-error-message');
    const errorContent = $('#oim-error-content');

    form.on('submit', function(e){
        e.preventDefault();

        // Hide any previous messages
        successMessage.hide();
        errorMessage.hide();

        let fd = new FormData(this);
        // use AJAX action and nonce
        fd.append('action', 'oim_handle_upload');
        if (typeof oim_ajax !== 'undefined' && oim_ajax.security) {
            fd.append('security', oim_ajax.security);
        }

        $('.oim-submit').prop('disabled', true).text('Processing...');

        $.ajax({
            url: (typeof oim_ajax !== 'undefined' ? oim_ajax.ajax_url : '/wp-admin/admin-ajax.php'),
            type: 'POST',
            data: fd,
            contentType: false,
            processData: false,
            success: function(res){
                $('.oim-submit').prop('disabled', false).text('Submit Order');
                
                if (res && res.success) {
                    const d = res.data;
                    
                    let html = '<p><strong>Your order has been successfully submitted!</strong></p>';
                    
                    if (d.internal_order_id) {
                        html += '<p>Internal Order ID: <strong>' + d.internal_order_id + '</strong></p>';
                    }
                    
                    if (d.driver_link) {
                        html += '<p style="margin-top:20px;"><strong>Driver Upload Link:</strong><br><a href="' + d.driver_link + '" target="_blank">' + d.driver_link + '</a></p>';
                    }
                    
                    if (d.pdf_url) {
                        html += '<p style="margin-top:20px;"><a href="' + d.pdf_url + '" target="_blank" class="button">Download Invoice PDF</a></p>';
                    }
                    
                    successContent.html(html);
                    successMessage.slideDown();
                    
                    // Hide form after successful submission
                    form.slideUp();
                    
                    // Scroll to success message
                    $('html, body').animate({
                        scrollTop: successMessage.offset().top - 100
                    }, 500);
                    
                    // Reset form
                    form[0].reset();
                    
                } else {
                    // Show error message
                    let errorMsg = (res && res.data) ? res.data : 'Unknown error occurred';
                    errorContent.html('<p>' + errorMsg + '</p>');
                    errorMessage.slideDown();
                    
                    // Scroll to error message
                    $('html, body').animate({
                        scrollTop: errorMessage.offset().top - 100
                    }, 500);
                }
            },
            error: function(xhr){
                $('.oim-submit').prop('disabled', false).text('Submit Order');
                let text = 'Something went wrong. Please try again.';
                if (xhr && xhr.responseText) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response && response.data) {
                            text = response.data;
                        }
                    } catch(e) {
                        text = xhr.responseText;
                    }
                }
                
                errorContent.html('<p>' + text + '</p>');
                errorMessage.slideDown();
                
                // Scroll to error message
                $('html, body').animate({
                    scrollTop: errorMessage.offset().top - 100
                }, 500);
            }
        });
    });

    // Submit new order button
    $('#oim-new-order-btn').on('click', function(){
        location.reload();
    });

    // If the page was redirected after non-AJAX submit with query params
    (function(){
        const params = new URLSearchParams(window.location.search);
        
        // Check for error
        if (params.get('oim_error')) {
            const errorMsg = params.get('error_msg') ? decodeURIComponent(params.get('error_msg')) : 'An error occurred';
            errorContent.html('<p>' + errorMsg + '</p>');
            errorMessage.show();
            
            // Scroll to error message
            $('html, body').animate({
                scrollTop: errorMessage.offset().top - 100
            }, 500);
            
            // remove params from URL
            history.replaceState(null, '', window.location.pathname);
            return;
        }
        
        // Check for success
        if (params.get('oim_created')) {
            const pdf = params.get('pdf') ? decodeURIComponent(params.get('pdf')) : '';
            const link = params.get('link') ? decodeURIComponent(params.get('link')) : '';
            const orderId = params.get('order_id') ? decodeURIComponent(params.get('order_id')) : '';
            
            let html = '<p><strong>Your order has been successfully submitted!</strong></p>';
            
            if (orderId) {
                html += '<p>Internal Order ID: <strong>' + orderId + '</strong></p>';
            }
            
            if (link) {
                html += '<p style="margin-top:20px;"><strong>Driver Upload Link:</strong><br><a href="' + link + '" target="_blank">' + link + '</a></p>';
            }
            
            if (pdf) {
                html += '<p style="margin-top:20px;"><a href="' + pdf + '" target="_blank" class="button">Download Invoice PDF</a></p>';
            }
            
            successContent.html(html);
            successMessage.show();
            form.hide();
            
            // Scroll to success message
            $('html, body').animate({
                scrollTop: successMessage.offset().top - 100
            }, 500);
            
            // remove params from URL (history)
            history.replaceState(null, '', window.location.pathname);
        }
    })();

});




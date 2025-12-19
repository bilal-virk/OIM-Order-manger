<?php
if (! defined('ABSPATH')) exit;
?>
<style>
/* Keep your original styling with some small adjustments */
.oim-form {
    max-width: auto;
    margin: 2rem auto;
    padding: 2rem;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    font-family: "Inter", sans-serif;
}
.oim-form h4 {
    margin-top: 1.5rem;
    font-size: 1.1rem;
    color: #444;
    border-bottom: 1px solid #eee;
    padding-bottom: .25rem;
}
.oim-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit,minmax(220px,1fr));
    gap: 1rem;
    margin-top: .5rem;
}
.oim-form label {
    display: flex;
    flex-direction: column;
    font-weight: 500;
    font-size: .9rem;
    color: #333;
}
.oim-form input,
.oim-form textarea {
    margin-top: .3rem;
    padding: .6rem .8rem;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: .95rem;
    transition: border-color .2s;
}
.oim-submit {
    margin-top: 1.5rem;
    padding: .8rem 1.5rem;
    background: #5b67f1;
    color: #fff;
    font-weight: 600;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    transition: background .2s;
}
.company-expand .toggle-company { background:none;border:none;color:#5b67f1;cursor:pointer;padding:0;margin-bottom:.5rem;font-weight:600;}
.company-details { display:none; }
#oim-modal { display:none; position:fixed; left:0; top:0; right:0; bottom:0; background: rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center; }
#oim-modal .box { background:#fff; padding:20px; border-radius:8px; max-width:540px; width:100%; }
.oim-success {
    background: #d4edda;
    border: 2px solid #28a745;
    border-radius: 8px;
    padding: 30px;
    margin-bottom: 30px;
    text-align: center;
}

.oim-success h3 {
    color: #155724;
    margin-top: 0;
    font-size: 28px;
}

.oim-success p {
    color: #155724;
    font-size: 16px;
    margin: 15px 0;
}

.oim-success strong {
    font-size: 18px;
    color: #0c3d1a;
}

.oim-success a {
    color: #0056b3;
    text-decoration: none;
    word-break: break-all;
}

.oim-success a:hover {
    text-decoration: underline;
}

.oim-success .button {
    display: inline-block;
    background: #28a745;
    color: white;
    padding: 12px 24px;
    border-radius: 5px;
    margin-top: 10px;
    text-decoration: none;
    border: none;
    cursor: pointer;
}

.oim-success .button:hover {
    background: #218838;
    text-decoration: none;
}

#oim-new-order-btn {
    background: #007bff;
    margin-top: 20px;
    font-size: 16px;
}

#oim-new-order-btn:hover {
    background: #0056b3;
}

.oim-error {
    background: #f8d7da;
    border: 2px solid #dc3545;
    border-radius: 8px;
    padding: 30px;
    margin-bottom: 30px;
    text-align: center;
}

.oim-error h3 {
    color: #721c24;
    margin-top: 0;
    font-size: 28px;
}

.oim-error p {
    color: #721c24;
    font-size: 16px;
    margin: 15px 0;
}
</style>
<div id="oim-error-messagewe" style="display:none;" class="oim-error">
    <h3>Submission Failed</h3>
    <div id="oim-error-content"></div>
</div>

<div id="oim-success-message" style="display:none;" class="oim-success">
    <h3>Thank You!</h3>
    <div id="oim-success-content"></div>
    <button type="button" id="oim-new-order-btn" class="button">Submit New Order</button>
</div>

<form class="oim-form" id="oim-upload-form" method="post" enctype="multipart/form-data">
    <?php wp_nonce_field('oim_frontend_nonce'); ?>

    <div class="oim-grid">
        <label>Customer Reference
            <input type="text" name="customer_reference">
        </label>
        <label>Customer - VAT ID
            <input type="text" name="vat_id">
        </label>
        <label>Customer Email
            <input type="email" name="customer_email" required>
        </label>
        <label>Customer Phone Number
            <input type="text" name="customer_phone">
        </label>
        <label>Customer Company Name
            <input type="text" name="customer_company_name">
        </label>
        <label>Company ID (IČO - CRN)
            <input type="text" name="customer_company_ID_crn">
        </label>
        <label>Customer Tax ID (DIČ)
            <input type="text" name="customer_tax_ID">
        </label>
        <label>Customer Country
            <input type="text" name="customer_country">
        </label>
        <label>Customer Price
            <input type="text" name="customer_price">
        </label>
        <label>Invoice Number
            <input type="text" name="invoice_number">
        </label>
        <label>Invoice Due Date In Days
            <input type="number" name="invoice_due_date_in_days">
        </label>
        <label>Truck Number
            <input type="text" name="truck_number">
        </label>
    </div>

    <div class="company-expand">
        <button type="button" class="toggle-company">+ Add Company Details</button>
        <div class="company-details">
            <div class="oim-grid">
                <label>Company Email
                    <input type="email" name="customer_company_email">
                </label>
                <label>Company Phone Number
                    <input type="text" name="customer_company_phone_number">
                </label>
                <label>Company Address
                    <input type="text" name="customer_company_address">
                </label>
            </div>
        </div>
    </div>

    <h4>Loading Info</h4>
    <div class="oim-grid">
        <label>Loading Company Name<input type="text" name="loading_company_name"></label>
        <label>Loading Date<input type="date" name="loading_date"></label>
        <label>Loading Country<input type="text" name="loading_country"></label>
        <label>Loading Zip<input type="text" name="loading_zip"></label>
        <label>Loading City<input type="text" name="loading_city"></label>
    </div>

    <h4>Unloading Info</h4>
    <div class="oim-grid">
        <label>Unloading Company Name<input type="text" name="unloading_company_name"></label>
        <label>Unloading Date<input type="date" name="unloading_date"></label>
        <label>Unloading Country<input type="text" name="unloading_country"></label>
        <label>Unloading Zip<input type="text" name="unloading_zip"></label>
        <label>Unloading City<input type="text" name="unloading_city"></label>
    </div>
    <label>Order Note<input type="text" name="order_note"></label>
    <label class="oim-file">Attachments
        <input type="file" name="attachments[]" multiple>
    </label>

    <input type="hidden" name="oim_order_form_submit" value="1">
    <button class="oim-submit" type="submit">Submit Order</button>
</form>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const toggleBtn = document.querySelector(".toggle-company");
    const details = document.querySelector(".company-details");
    if (toggleBtn && details) {
        toggleBtn.addEventListener("click", function() {
            if (details.style.display === "none" || details.style.display === "") {
                details.style.display = "block";
                toggleBtn.textContent = "- Hide Company Details";
            } else {
                details.style.display = "none";
                toggleBtn.textContent = "+ Add Company Details";
            }
        });
    }
});
</script>

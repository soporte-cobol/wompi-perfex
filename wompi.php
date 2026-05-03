<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Wompi Payment Gateway
Description: Pasarela de pago Wompi by Bancolombia para Perfex CRM (Premium)
Version: 1.1.0
Requires at least: 2.3.2
*/

define('WOMPI_MODULE_NAME', 'wompi');
define('WOMPI_MODULE_VERSION', '1.1.0');

/** Trial registration endpoint — hosted on your server, NOT in this file */
define('WOMPI_TRIAL_ENDPOINT', 'https://control.cobol.com.co/wompi-trial-register.php');

/** Shared secret between this module and the trial endpoint */
define('WOMPI_ENDPOINT_TOKEN', 'wmp_reg_8x2kL9pQv3mNdRtY');

/**
 * Register payment gateway
 */
// Perfex expects the gateway identifier in lowercase, matching the library classname suffix.
// File/class should be libraries/Wompi_gateway.php => class Wompi_gateway (Perfex docs).
register_payment_gateway('wompi_gateway', WOMPI_MODULE_NAME);

/**
 * Register language files
 */
register_language_files(WOMPI_MODULE_NAME, [WOMPI_MODULE_NAME]);

/**
 * Module activation hook — runs when admin activates the module
 */
register_activation_hook(WOMPI_MODULE_NAME, 'wompi_module_activation_hook');

function wompi_module_activation_hook()
{
    // Initialize license cache options
    // Note: Wompi_license library uses the wompi_license_* prefix.
    add_option('wompi_license_data',      '');
    add_option('wompi_license_cached_at', 0);
    add_option('wompi_trial_requested',   0);

    // Auto-request trial if no license key is set
    wompi_maybe_request_trial();
}

/**
 * Auto-request a 30-day trial license from control.cobol.com.co
 * Only runs once (if no license key exists and trial not yet requested).
 */
function wompi_maybe_request_trial()
{
    // Skip if already has a license key
    $existing_key = get_option('paymentmethod_wompi_license_key');
    if (!empty($existing_key)) {
        return;
    }

    // Skip if trial was already requested
    if (get_option('wompi_trial_requested') == '1') {
        return;
    }

    // Mark as requested immediately to avoid duplicate calls
    update_option('wompi_trial_requested', '1');

    // Gather domain and site info
    $domain = $_SERVER['HTTP_HOST'] ?? parse_url(base_url(), PHP_URL_HOST);
    $domain = preg_replace('/^www\./', '', $domain);

    $email  = get_option('companyemail') ?: get_option('email');
    $name   = get_option('companyname')  ?: 'Cliente Wompi';

    // Call the trial registration endpoint
    $ch = curl_init(WOMPI_TRIAL_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'token'  => WOMPI_ENDPOINT_TOKEN,
            'domain' => $domain,
            'email'  => $email,
            'name'   => $name,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || empty($raw)) {
        log_message('error', '[Wompi] Trial request failed: ' . $err);
        update_option('wompi_trial_requested', '0'); // Allow retry
        return;
    }

    $response = json_decode($raw, true);

    if (!empty($response['success']) && !empty($response['license_key'])) {
        // Save the trial license key automatically
        update_option('paymentmethod_wompi_license_key', $response['license_key']);

        // Clear license cache so it gets re-validated
        update_option('wompi_license_data',      '');
        update_option('wompi_license_cached_at', 0);

        // Store trial expiry for display in admin
        update_option('wompi_trial_expires', $response['expires'] ?? '');

        log_message('info', '[Wompi] Trial license activated: ' . $response['license_key'] . ' expires: ' . ($response['expires'] ?? 'N/A'));
    } else {
        log_message('error', '[Wompi] Trial request unsuccessful: ' . ($response['message'] ?? 'Unknown error'));
        update_option('wompi_trial_requested', '0'); // Allow retry
    }
}

/**
 * Check license validity (cached for 24h).
 */
function wompi_license_valid()
{
    static $result = null;

    if ($result !== null) {
        return $result;
    }

    $CI = &get_instance();
    $CI->load->library('wompi/Wompi_license'); // file: libraries/Wompi_license.php, class: Wompi_license
    $result = $CI->wompi_license->isValid();

    return $result;
}

/**
 * Show admin notice if license is missing, invalid, or near expiry.
 */
hooks()->add_action('admin_after_body_start', 'wompi_license_admin_notice');

function wompi_license_admin_notice()
{
    $CI       = &get_instance();
    $segment2 = $CI->uri->segment(2);
    $group    = $CI->input->get('group');

    $show = ($segment2 === 'dashboard')
         || ($segment2 === 'settings' && $group === 'payment_gateways');

    if (!$show) {
        return;
    }

    $license_key = get_option('paymentmethod_wompi_license_key');
    $trial_exp   = get_option('wompi_trial_expires');

    // Inline CSS to ensure the alert is visible below the fixed header
    echo '<style>.wompi-admin-notice { margin: 20px 20px 0 !important; position: relative; z-index: 9999; clear: both; }</style>';

    // --- Trial expiry warning (7 days before) ---
    if (!empty($trial_exp) && strtotime($trial_exp) > 0) {
        $days_left = (int) ceil((strtotime($trial_exp) - time()) / 86400);

        if ($days_left > 0 && $days_left <= 7) {
            echo '<div class="alert alert-warning alert-dismissible wompi-admin-notice">'
                . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
                . '⚠️ Tu período de prueba de <strong>Wompi Payment Gateway</strong> vence en <strong>' . $days_left . ' día(s)</strong>. '
                . '<a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Activa tu plan aquí</a>.'
                . '</div>';
            return;
        }

        if ($days_left <= 0 && !wompi_license_valid()) {
            echo '<div class="alert alert-danger alert-dismissible wompi-admin-notice">'
                . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
                . '🚫 Tu prueba de <strong>Wompi Payment Gateway</strong> ha expirado. '
                . '<a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renueva tu licencia</a> para seguir recibiendo pagos.'
                . '</div>';
            return;
        }
    }

    // --- No license key at all ---
    if (empty($license_key)) {
        echo '<div class="alert alert-info alert-dismissible wompi-admin-notice">'
            . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            . '🔑 <strong>Wompi Payment Gateway</strong> está activando tu trial gratuito de 30 días... '
            . 'Si no se activa automáticamente, <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">obtén tu licencia aquí</a>.'
            . '</div>';
        return;
    }

    // --- Invalid license ---
    if (!wompi_license_valid()) {
        $CI->load->library('wompi/Wompi_license');
        $status = $CI->wompi_license->getStatus();
        $ctx    = $CI->wompi_license->getVerifyContext();
        echo '<div class="alert alert-warning alert-dismissible wompi-admin-notice">'
            . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            . '⚠️ La licencia de <strong>Wompi Payment Gateway</strong> es inválida o ha expirado. '
            . 'Estado: <strong>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</strong>. '
            . '<br><small>Validando como Domain=' . htmlspecialchars($ctx['domain'], ENT_QUOTES, 'UTF-8')
            . ' IP=' . htmlspecialchars($ctx['ip'], ENT_QUOTES, 'UTF-8')
            . ' Dir=' . htmlspecialchars($ctx['dir'], ENT_QUOTES, 'UTF-8') . '</small> '
            . '<a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renueva aquí</a>.'
            . '</div>';
    }
}

/**
 * Client and Admin area: UI logic for Wompi.
 */
hooks()->add_action('app_clients_area_footer', 'wompi_ui_scripts');
hooks()->add_action('admin_footer', 'wompi_ui_scripts');

function wompi_ui_scripts()
{
    $CI = &get_instance();
    $is_client = $CI->uri->segment(1) === 'invoice' || $CI->uri->segment(1) === 'invoices';
    $is_admin  = $CI->uri->segment(1) === 'admin' && ($CI->uri->segment(2) === 'invoices' || $CI->uri->segment(2) === 'payments');

    if (!$is_client && !$is_admin) {
        return;
    }

    $licensed = wompi_license_valid();

    // Get invoice data (client and admin invoice views)
    $invoice_id = $is_client ? $CI->uri->segment(2) : $CI->uri->segment(3);
    if (empty($invoice_id)) {
        return;
    }

    $CI->load->model('invoices_model');
    $invoice = $CI->invoices_model->get($invoice_id);
    if (!$invoice) {
        return;
    }

    // Ensure gateway library is loaded (some Perfex pages won't preload it).
    $CI->load->library('wompi/Wompi_gateway');
    $gateway         = $CI->wompi_gateway;
    $currency        = $invoice->currency_name;
    $public_key      = $gateway->getSetting('public_key');
    $redirect_url    = site_url('wompi/callback/response');
    // Partial payments require regenerating the Wompi integrity signature when the amount changes.
    // For now:
    // - If partial payments are OFF: we inject the Wompi widget and hide the amount field.
    // - If partial payments are ON: we do NOT inject the widget (falls back to Perfex submit flow),
    //   because a fixed widget signature would break when the user edits the amount.
    $allow_partial   = (get_option('paymentmethod_wompi_allow_partial_payments') === '1');
    $integrity_secret = $gateway->decryptSetting('integrity_secret');

    $can_render_widget = $licensed && !$allow_partial && !empty($public_key) && !empty($integrity_secret);

    ?>
    <style id="wompi-simple-styles">
        /* Keep this minimal on purpose: we only toggle visibility with JS */
        #wompi-simple-container { display: none; margin-top: 12px; }
        #wompi-simple-container .wompi-button-wrapper button { width: 100%; }
    </style>

    <div id="wompi-simple-container" aria-hidden="true">
        <?php if ($can_render_widget): ?>
            <div class="wompi-button-wrapper">
                <form>
                    <?php
                    // Default amount for the widget is the current outstanding invoice value (in cents).
                    $amount_in_cents = (int) round(floatval($invoice->total_left_to_pay) * 100);
                    $reference       = $invoice_id . '_' . time();
                    $signature       = hash('sha256', $reference . $amount_in_cents . $currency . $integrity_secret);
                    ?>
                    <script
                        src="https://checkout.wompi.co/widget.js"
                        data-render="button"
                        data-public-key="<?php echo htmlspecialchars($public_key, ENT_QUOTES, 'UTF-8'); ?>"
                        data-currency="<?php echo htmlspecialchars($currency, ENT_QUOTES, 'UTF-8'); ?>"
                        data-amount-in-cents="<?php echo (int) $amount_in_cents; ?>"
                        data-reference="<?php echo htmlspecialchars($reference, ENT_QUOTES, 'UTF-8'); ?>"
                        data-signature:integrity="<?php echo htmlspecialchars($signature, ENT_QUOTES, 'UTF-8'); ?>"
                        data-redirect-url="<?php echo htmlspecialchars($redirect_url, ENT_QUOTES, 'UTF-8'); ?>"
                        data-custom-data:invoice_id="<?php echo (int) $invoice_id; ?>"
                        data-custom-data:hash="<?php echo htmlspecialchars($invoice->hash, ENT_QUOTES, 'UTF-8'); ?>">
                    </script>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        'use strict';
        var allowPartial = <?php echo $allow_partial ? 'true' : 'false'; ?>;
        var invoiceTotalCents = <?php echo (int) round(floatval($invoice->total_left_to_pay) * 100); ?>;
        var wompiLicensed = <?php echo $licensed ? 'true' : 'false'; ?>;
        var wompiWidgetEnabled = <?php echo $can_render_widget ? 'true' : 'false'; ?>;

        function findPaymentForm() {
            return document.querySelector('#online_payment_form') || document.querySelector('#invoice_payment_form');
        }

        function selectedModeIsWompi() {
            // Common Perfex templates: radio group name="payment_mode"
            var radio = document.querySelector('input[type="radio"][name="payment_mode"][value="wompi"]:checked');
            if (radio) return true;

            // Your template uses name="paymentmode"
            var radio2 = document.querySelector('input[type="radio"][name="paymentmode"][value="wompi"]:checked');
            if (radio2) return true;

            // Some templates use selects
            var select =
                document.querySelector('select[name="payment_mode"]') ||
                document.querySelector('select#payment_mode') ||
                document.querySelector('select[name="paymentmode"]');
            if (select && String(select.value).toLowerCase() === 'wompi') return true;

            // Fallback: any checked radio with value wompi, regardless of name
            var anyRadio = document.querySelector('input[type="radio"][value="wompi"]:checked');
            return !!anyRadio;
        }

        function toggleSimpleWidget() {
            var form = findPaymentForm();
            var container = document.getElementById('wompi-simple-container');
            if (!form || !container) return;

            // Replace the original Perfex submit button in-place (same location in the DOM).
            var submitBtn = form.querySelector('button[type="submit"], input[type="submit"]');
            var payButtonWrap = document.getElementById('pay_button');
            if (submitBtn) {
                // Prefer inserting where Perfex renders the pay button.
                if (payButtonWrap && container.parentNode !== payButtonWrap.parentNode) {
                    payButtonWrap.parentNode.insertBefore(container, payButtonWrap);
                } else {
                    submitBtn.parentNode.insertBefore(container, submitBtn);
                }
            }

            var show = selectedModeIsWompi();
            container.style.display = show ? 'block' : 'none';
            container.setAttribute('aria-hidden', show ? 'false' : 'true');

            // If the widget isn't enabled (unlicensed, missing keys, or partial payments ON),
            // keep the standard Perfex submit flow.
            if (!wompiWidgetEnabled) {
                if (payButtonWrap) payButtonWrap.style.display = '';
                if (submitBtn) submitBtn.style.display = submitBtn.dataset.wompiOriginalDisplay || '';
                return;
            }

            // Licensed: hide Perfex submit completely when Wompi is selected (simple, avoids double-submit confusion).
            if (submitBtn) {
                if (show) {
                    if (!submitBtn.dataset.wompiOriginalDisplay) {
                        submitBtn.dataset.wompiOriginalDisplay = submitBtn.style.display || '';
                    }
                    submitBtn.style.display = 'none';
                } else {
                    submitBtn.style.display = submitBtn.dataset.wompiOriginalDisplay || '';
                }
            }
            if (payButtonWrap) {
                payButtonWrap.style.display = show ? 'none' : '';
            }

            // Amount field:
            // - When partial payments are disabled, hide the amount row entirely (and keep value fixed).
            // - When enabled (not currently used), it would remain visible/editable.
            var amountInputs = Array.prototype.slice.call(document.querySelectorAll(
                '#payment_amount,' +
                'input[name="amount"],' +
                'input[name="payment_amount"],' +
                'input[name="paymentamount"],' +
                'input[data-amount]'
            ));

            amountInputs.forEach(function(amountInput) {
                if (!amountInput) return;
                var row = amountInput.closest('.form-group, .col-md-12, .row, tr, .form-item');

                if (show && !allowPartial) {
                    amountInput.value = (invoiceTotalCents / 100).toFixed(2);
                    amountInput.readOnly = true;
                    // Hide both the input and its closest row container to remove "variable amount" UI.
                    amountInput.style.display = 'none';
                    if (row) row.style.display = 'none';
                } else if (show && allowPartial) {
                    amountInput.readOnly = false;
                    amountInput.style.display = '';
                    if (row) row.style.display = '';
                } else {
                    amountInput.readOnly = false;
                    amountInput.style.display = '';
                    if (row) row.style.display = '';
                }
            });
        }

        function bindModeChanges() {
            // Radios or selects depending on template.
            document.addEventListener('change', function(e) {
                var t = e.target;
                if (!t) return;
                if (t.name === 'payment_mode') toggleSimpleWidget();
                if (t.name === 'paymentmode') toggleSimpleWidget();
                if (t.id === 'payment_mode' || t.name === 'paymentmode') toggleSimpleWidget();
            }, true);
            // Some themes/plugins bind click instead of change.
            document.addEventListener('click', function(e) {
                var t = e.target;
                if (!t) return;
                if (t.name === 'payment_mode') toggleSimpleWidget();
                if (t.name === 'paymentmode') toggleSimpleWidget();
            }, true);
        }

        function init() {
            bindModeChanges();
            toggleSimpleWidget();

            // Some themes manipulate DOM after load; keep it in sync briefly.
            var tries = 0;
            var iv = setInterval(function() {
                toggleSimpleWidget();
                tries++;
                if (tries >= 10) clearInterval(iv);
            }, 300);
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
        else init();
    })();
    </script>
    <?php
}

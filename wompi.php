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
function wompi_license_valid(): bool
{
    static $result = null;

    if ($result !== null) {
        return $result;
    }

    $CI = &get_instance();
    $CI->load->library('wompi/wompi_license');
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
        echo '<div class="alert alert-warning alert-dismissible wompi-admin-notice">'
            . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            . '⚠️ La licencia de <strong>Wompi Payment Gateway</strong> es inválida o ha expirado. '
            . '<a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renueva aquí</a>.'
            . '</div>';
    }
}

/**
 * Client area: hide Wompi if license invalid; hide amount if partial payments disabled.
 */
hooks()->add_action('app_clients_area_footer', 'wompi_client_area_scripts');

function wompi_client_area_scripts()
{
    $CI = &get_instance();

    if ($CI->uri->segment(1) !== 'invoice' && $CI->uri->segment(1) !== 'invoices') {
        return;
    }

    if (!wompi_license_valid()) {
        ?>
        <script>
        (function() {
            var radios = document.querySelectorAll('input[value="wompi"]');
            radios.forEach(function(r) {
                var row = r.closest('.payment-mode-row, .radio, li, tr, label');
                if (row) row.style.display = 'none';
            });
        })();
        </script>
        <?php
        return;
    }

    $allow_partial = get_option('paymentmethod_wompi_allow_partial_payments');
    ?>
    <script>
    (function() {
        'use strict';
        function initWompiUI() {
            var modeRadios  = document.querySelectorAll('input[name="payment_mode"]');
            if (!modeRadios.length) return;

            var allowPartial = <?php echo ($allow_partial == '1') ? 'true' : 'false'; ?>;
            
            function applyUI() {
                var sel     = document.querySelector('input[name="payment_mode"]:checked');
                var isWompi = sel && sel.value === 'wompi';
                
                // Selector más agresivo para encontrar el contenedor del monto
                var amountInput = document.querySelector('#payment_amount');
                if (amountInput) {
                    var amountRow = amountInput.closest('.form-group, .col-md-12, .row, tr');
                    if (amountRow) {
                        if (isWompi && !allowPartial) {
                            amountRow.style.setProperty('display', 'none', 'important');
                        } else {
                            amountRow.style.display = '';
                        }
                    }
                }
            }

            modeRadios.forEach(function(r) { r.addEventListener('change', applyUI); });
            
            // Observar cambios en el DOM por si Perfex recarga la sección de pagos vía AJAX
            var observer = new MutationObserver(applyUI);
            var target = document.querySelector('#online_payment_form') || document.body;
            observer.observe(target, { childList: true, subtree: true });

            applyUI();
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initWompiUI);
        } else {
            initWompiUI();
        }
    })();
    </script>
    <?php
}

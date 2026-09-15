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

    // Auto-request trial if no license key is set
    wompi_maybe_request_trial();

    $CI->load->library('wompi/Wompi_license'); // file: libraries/Wompi_license.php, class: Wompi_license

    // In admin payment gateway settings we prefer fresh validation to reduce confusion.
    $segment2 = $CI->uri->segment(2);
    $group    = $CI->input->get('group');
    $force    = ($segment2 === 'settings' && $group === 'payment_gateways') || ($CI->input->get('wompi_revalidate') === '1');

    log_message('debug', '[Wompi] wompi_license_valid called force=' . ($force ? '1' : '0'));

    $result = $force ? $CI->wompi_license->revalidate() : $CI->wompi_license->isValid();

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

    // Force license validation on this page load so admins see up-to-date state
    // and we get deterministic logging.
    $is_valid = wompi_license_valid();

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
                . sprintf(_l('wompi_admin_notice_trial_warning'), $days_left)
                . '</div>';
            return;
        }

        if ($days_left <= 0 && !wompi_license_valid()) {
            echo '<div class="alert alert-danger alert-dismissible wompi-admin-notice">'
                . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
                . _l('wompi_admin_notice_trial_expired')
                . '</div>';
            return;
        }
    }

    // --- No license key at all ---
    if (empty($license_key)) {
        echo '<div class="alert alert-info alert-dismissible wompi-admin-notice">'
            . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            . _l('wompi_admin_notice_no_key')
            . '</div>';
        return;
    }

    // --- Invalid license ---
    if (!$is_valid) {
        $CI->load->library('wompi/Wompi_license');
        $status = $CI->wompi_license->getStatus();
        $ctx    = $CI->wompi_license->getVerifyContext();
        $tx     = $CI->wompi_license->getLastTransportInfo();
        $curl_text = !empty($tx['curl_err']) ? ' cURL=' . htmlspecialchars($tx['curl_err'], ENT_QUOTES, 'UTF-8') : '';
        
        echo '<div class="alert alert-warning alert-dismissible wompi-admin-notice">'
            . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            . sprintf(
                _l('wompi_admin_notice_invalid'),
                htmlspecialchars($status, ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($ctx['domain'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($ctx['ip'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars($ctx['dir'], ENT_QUOTES, 'UTF-8'),
                htmlspecialchars((string) ($tx['http_code'] ?? ''), ENT_QUOTES, 'UTF-8'),
                $curl_text
            )
            . '</div>';
    }
}

/**
 * Client and Admin area: UI logic for Wompi.
 */
// Perfex >= 2.3 uses app_customers_footer() which triggers the 'app_customers_footer' hook.
// Some older installs/themes may still fire 'app_clients_area_footer', so we support both.
hooks()->add_action('app_customers_footer', 'wompi_ui_scripts');
hooks()->add_action('app_clients_area_footer', 'wompi_ui_scripts');
hooks()->add_action('admin_footer', 'wompi_ui_scripts');

function wompi_ui_scripts()
{
    $CI = &get_instance();
    
    // Diagnostic log to identify the exact active file on the server
    echo '<script>console.log("🔍 Wompi Active File: ' . addslashes(str_replace('\\', '/', __FILE__)) . '");</script>';

    $admin_folder = function_exists('get_admin_uri') ? get_admin_uri() : 'admin';
    
    $is_client = $CI->uri->segment(1) === 'invoice' || $CI->uri->segment(1) === 'invoices';
    $is_admin  = $CI->uri->segment(1) === $admin_folder && ($CI->uri->segment(2) === 'invoices' || $CI->uri->segment(2) === 'payments');
    $is_admin_payment_gateways = $CI->uri->segment(1) === $admin_folder
        && $CI->uri->segment(2) === 'settings'
        && $CI->input->get('group') === 'payment_gateways';

    if (!$is_client && !$is_admin && !$is_admin_payment_gateways) {
        return;
    }

    // On the payment gateways settings page, we only need to trigger license validation (and logs).
    // No invoice context is available there.
    if ($is_admin_payment_gateways) {
        wompi_license_valid();
        echo '<link rel="stylesheet" type="text/css" href="' . site_url('wompi/callback/css') . '?v=' . WOMPI_MODULE_VERSION . '">';
        wompi_render_backend_license_panel();
        return;
    }

    // Always inject the UI script so we can hide the amount field when Wompi is selected,
    // even if the license is currently invalid.
    $licensed = wompi_license_valid();

    // Get invoice data (client and admin invoice views)
    $invoice_id = '';
    if ($is_client) {
        $invoice_id = $CI->uri->segment(2);
    } elseif ($is_admin) {
        $seg2 = $CI->uri->segment(2);
        $seg3 = $CI->uri->segment(3);
        $seg4 = $CI->uri->segment(4);

        if ($seg2 === 'invoices') {
            if ($seg3 === 'list_invoices') {
                $invoice_id = $seg4;
            } elseif ($seg3 === 'view' || $seg3 === 'invoice') {
                $invoice_id = $seg4;
            } else {
                if (is_numeric($seg3)) {
                    $invoice_id = $seg3;
                }
            }
        } elseif ($seg2 === 'payments') {
            if ($seg3 === 'payment' && is_numeric($seg4)) {
                $CI->db->select('invoiceid');
                $CI->db->where('id', $seg4);
                $pay_rec = $CI->db->get(db_prefix() . 'invoicepaymentrecords')->row();
                if ($pay_rec) {
                    $invoice_id = $pay_rec->invoiceid;
                }
            }
        }
    }

    if (empty($invoice_id) || !is_numeric($invoice_id)) {
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
    // We support this by calling a server-side endpoint that returns a fresh reference+signature
    // for the chosen amount, without exposing the integrity secret to the browser.
    $allow_partial   = (get_option('paymentmethod_wompi_allow_partial_payments') === '1');
    $integrity_secret = $gateway->decryptSetting('integrity_secret');

    $can_render_widget = $licensed && !empty($public_key) && !empty($integrity_secret);
    
    // Exceptionally defensive check for is_admin() to prevent any potential error on client-side loading
    $is_admin_user = false;
    if (function_exists('is_staff_logged_in') && is_staff_logged_in() && function_exists('is_admin')) {
        $is_admin_user = is_admin();
    }
    
    $should_render_panel = $can_render_widget || $is_admin_user;

    // Resolve Logo Assets dynamically: load them safely through the Callback controller to bypass modules/.htaccess access restrictions.
    $pse_logo         = site_url('wompi/callback/logo/pse');
    $bancolombia_logo = site_url('wompi/callback/logo/bancolombia');
    $nequi_logo       = site_url('wompi/callback/logo/nequi');
    $daviplata_logo   = site_url('wompi/callback/logo/daviplata');
    $visa_logo        = site_url('wompi/callback/logo/visa');
    $mastercard_logo  = site_url('wompi/callback/logo/mastercard');

    // Check if the invoice is already paid (status = 2)
    $is_paid = ($invoice->status == 2 || $invoice->status == '2');
    $wompi_payment = null;

    if ($is_paid) {
        // Query if there's any payment records for this invoice
        $CI->db->where('invoiceid', $invoice_id);
        $CI->db->where('paymentmode', 'wompi');
        $wompi_payment = $CI->db->get(db_prefix() . 'invoicepaymentrecords')->row();

        if (!$wompi_payment) {
            // Fallback: get any payment record
            $CI->db->where('invoiceid', $invoice_id);
            $wompi_payment = $CI->db->get(db_prefix() . 'invoicepaymentrecords')->row();
        }
    }

    // Inject unified stylesheet
    echo '<link rel="stylesheet" type="text/css" href="' . site_url('wompi/callback/css') . '?v=' . WOMPI_MODULE_VERSION . '">';
    ?>
    <div id="wompi-simple-container" aria-hidden="true">
        <?php if ($can_render_widget): ?>
            <!-- Hidden official Wompi form (visually hidden but technically active for script dimensions) -->
            <div class="wompi-button-wrapper wompi-hidden-accessible">
                <form id="wompi-real-form">
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
                        data-customer-data:invoice_id="<?php echo (int) $invoice_id; ?>"
                        data-customer-data:hash="<?php echo htmlspecialchars($invoice->hash, ENT_QUOTES, 'UTF-8'); ?>">
                    </script>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($should_render_panel): ?>
            <!-- Beautiful Premium Panel -->
            <div class="wompi-premium-panel">
                <?php if (!$can_render_widget && $is_admin_user): ?>
                    <!-- Admin Diagnostic Notice Box -->
                    <div class="wompi-admin-setup-notice" style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 8px; padding: 12px; margin-bottom: 15px; font-size: 13px; color: #b45309; text-align: left;">
                        <div style="font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 6px;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="8" x2="12" y2="12"></line>
                                <line x1="12" y1="16" x2="12.01" y2="16"></line>
                            </svg>
                            Panel de Control Wompi (Solo visible para Administradores)
                        </div>
                        <ul style="margin: 0; padding-left: 18px; line-height: 1.4;">
                            <?php if (!$licensed): ?>
                                <li><strong>Licencia requerida:</strong> La licencia de Wompi no es válida o ha expirado. Verifique en <a href="<?php echo site_url('admin/settings?group=payment_gateways'); ?>" style="text-decoration: underline; color: #b45309; font-weight: 600;">Ajustes de Pago</a>.</li>
                            <?php endif; ?>
                            <?php if (empty($public_key)): ?>
                                <li><strong>Llave Pública faltante:</strong> Ingrese su Llave Pública de Wompi en los ajustes de pasarelas de pago.</li>
                            <?php endif; ?>
                            <?php if (empty($integrity_secret)): ?>
                                <li><strong>Secreto de Integridad faltante:</strong> Ingrese su Secreto de Integridad de Wompi en los ajustes (requerido para firmar transacciones).</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <div class="wompi-premium-header">
                    <h4 class="wompi-premium-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        Métodos de Pago Soportados
                    </h4>
                    <span class="wompi-secure-badge" style="<?php echo $is_paid ? 'background: #ecfdf5; color: #047857; border-color: #a7f3d0;' : ''; ?>">
                        <?php echo $is_paid ? 'Orden Completada' : 'Pago 100% Seguro'; ?>
                    </span>
                </div>
                
                <div class="wompi-logos-grid">
                    <div class="wompi-logo-item" data-tooltip="Débito seguro desde cualquier banco">
                        <img class="wompi-logo-img" src="<?php echo $pse_logo; ?>" alt="PSE">
                        <span class="wompi-logo-caption">PSE / Bancos</span>
                    </div>
                    <div class="wompi-logo-item" data-tooltip="Transferencia directa e inmediata">
                        <img class="wompi-logo-img" src="<?php echo $bancolombia_logo; ?>" alt="Bancolombia">
                        <span class="wompi-logo-caption">Bancolombia</span>
                    </div>
                    <div class="wompi-logo-item" data-tooltip="Paga rápido desde tu celular">
                        <img class="wompi-logo-img" src="<?php echo $nequi_logo; ?>" alt="Nequi">
                        <span class="wompi-logo-caption">Nequi</span>
                    </div>
                    <div class="wompi-logo-item" data-tooltip="Usa tu cuenta Daviplata en segundos">
                        <img class="wompi-logo-img" src="<?php echo $daviplata_logo; ?>" alt="Daviplata">
                        <span class="wompi-logo-caption">Daviplata</span>
                    </div>
                    <div class="wompi-logo-item" data-tooltip="Visa o Mastercard (Crédito/Débito)">
                        <div class="wompi-cards-wrapper">
                            <img class="wompi-logo-img card-brand" src="<?php echo $visa_logo; ?>" alt="Visa">
                            <img class="wompi-logo-img card-brand" src="<?php echo $mastercard_logo; ?>" alt="Mastercard">
                        </div>
                        <span class="wompi-logo-caption">Tarjetas</span>
                    </div>
                </div>
                
                <div class="wompi-premium-button-container">
                    <?php if ($is_paid): ?>
                        <?php if ($wompi_payment): ?>
                            <!-- Beautiful status notification pill for Wompi payments -->
                            <div class="wompi-status-bar-paid">
                                <div style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 700;">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="20 6 9 17 4 12"></polyline>
                                    </svg>
                                    <span>Orden de Compra Pagada con Éxito</span>
                                </div>
                                <div style="font-size: 11px; opacity: 0.95; font-weight: 500; font-family: monospace; letter-spacing: 0.2px;">
                                    ID de Aprobación: <?php echo htmlspecialchars($wompi_payment->transactionid); ?> · <?php echo date('d/m/Y h:i A', strtotime($wompi_payment->date)); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <!-- Beautiful general paid status bar -->
                            <div class="wompi-status-bar-paid general">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="20 6 9 17 4 12"></polyline>
                                </svg>
                                <span>Factura Pagada / Saldo $0.00</span>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (!$can_render_widget): ?>
                            <!-- Inactive/Setup Pending State Button -->
                            <button type="button" class="wompi-btn-premium" style="opacity: 0.65; cursor: not-allowed;" disabled>
                                <svg class="wompi-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <span>Pasarela Deshabilitada (Falta Configuración)</span>
                            </button>
                        <?php else: ?>
                            <button type="button" class="wompi-btn-premium" id="wompi-premium-btn">
                                <div class="wompi-spinner"></div>
                                <svg class="wompi-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                </svg>
                                <span id="wompi-btn-text">Pagar Factura de Forma Segura</span>
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                
                <div class="wompi-premium-footer">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    <span>Procesado y respaldado de forma segura por Wompi Bancolombia</span>
                </div>
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
        var wompiSignatureEndpoint = <?php echo json_encode(site_url('wompi/callback/get_checkout_data/' . (int) $invoice_id . '/' . $invoice->hash)); ?>;
        var invoiceCurrency = <?php echo json_encode((string) $currency); ?>;
        var isPaid = <?php echo $is_paid ? 'true' : 'false'; ?>;
        var isAdminUser = <?php echo $is_admin_user ? 'true' : 'false'; ?>;
        var isAdminView = <?php echo $is_admin ? 'true' : 'false'; ?>;

        function findPaymentForm() {
            return document.querySelector('#online_payment_form') || 
                   document.querySelector('#invoice_payment_form') ||
                   document.querySelector('form[action*="payment"]') ||
                   document.querySelector('form[action*="process"]') ||
                   document.querySelector('form');
        }

        // Visual-only formatting: COP is typically shown without decimals in Colombia.
        // Perfex may render ".00" across invoice UIs; strip it without changing any calculation
        // or backend value. We apply this in both customer and admin invoice views.
        function wompiStripTrailingZeros() {
            try {
                if (String(invoiceCurrency || '').toUpperCase() !== 'COP') return;

                function strip00(s) {
                    return String(s).replace(/([.,]00)\\b/g, '');
                }

                var form = findPaymentForm();
                if (form) {
                    var amount = form.querySelector('input[name=\"amount\"]');
                    if (amount && amount.value) amount.value = strip00(amount.value);
                    // Also normalize max/data-total attributes when present.
                    if (amount && amount.getAttribute('max')) amount.setAttribute('max', strip00(amount.getAttribute('max')));
                    if (amount && amount.getAttribute('data-total')) amount.setAttribute('data-total', strip00(amount.getAttribute('data-total')));
                }

                // Target the most common invoice summary + items areas (customer and admin previews)
                var nodes = document.querySelectorAll([
                    '.subtotal',
                    '.total',
                    '.amount',
                    '.invoice-items-preview td',
                    '.items-preview td',
                    '.invoice-preview td',
                    '.invoice-preview .table td',
                    '.table.items td',
                    '.table td',
                    '.text-danger',
                ].join(','));

                for (var i = 0; i < nodes.length; i++) {
                    var n = nodes[i];
                    if (!n) continue;
                    if (n.children && n.children.length) continue;
                    var t = n.textContent;
                    if (!t) continue;
                    // Only change when it *ends* with .00 / ,00 to avoid touching percentage taxes, etc.
                    if (!/\\d([.,]00)\\s*$/.test(t)) continue;
                    n.textContent = strip00(t);
                }
            } catch (e) {
                // No-op: purely cosmetic.
            }
        }

        function selectedModeIsWompi() {
            var form = findPaymentForm();
            if (!form) return false;

            // 1. Check for hidden inputs (implicit selection when only one mode is active or pre-selected)
            var hiddenMode = form.querySelector('input[type="hidden"][name="payment_mode"][value="wompi"]') ||
                             form.querySelector('input[type="hidden"][name="paymentmode"][value="wompi"]') ||
                             form.querySelector('input[type="hidden"][value="wompi"]');
            if (hiddenMode) return true;

            // 2. Check radios (both common names)
            var radios = form.querySelectorAll('input[type="radio"][name="payment_mode"], input[type="radio"][name="paymentmode"], input[type="radio"][value="wompi"]');
            if (radios.length > 0) {
                var checkedRadio = form.querySelector('input[type="radio"][name="payment_mode"][value="wompi"]:checked') ||
                                   form.querySelector('input[type="radio"][name="paymentmode"][value="wompi"]:checked') ||
                                   form.querySelector('input[type="radio"][value="wompi"]:checked');
                if (checkedRadio) return true;
                
                // If there are radios, but none of them is checked, check if there is only 1 radio option and it is wompi
                if (radios.length === 1 && String(radios[0].value).toLowerCase() === 'wompi') {
                    return true;
                }
            }

            // 3. Check select dropdowns
            var select = form.querySelector('select[name="payment_mode"]') ||
                         form.querySelector('select#payment_mode') ||
                         form.querySelector('select[name="paymentmode"]');
            if (select) {
                if (String(select.value).toLowerCase() === 'wompi') return true;
            }

            // 4. If there are absolutely no choice elements (no radios, no select) inside the payment form,
            // then Wompi is implicitly selected because it's the only active gateway.
            if (radios.length === 0 && !select) {
                return true;
            }

            return false;
        }

        function toggleSimpleWidget() {
            if (isPaid) return; // Never run toggle on paid invoices

            var form = findPaymentForm();
            var container = document.getElementById('wompi-simple-container');
            if (!container) return;

            // Replace the original Perfex submit button in-place (same location in the DOM).
            if (form) {
                // EXTREMELY IMPORTANT: We must NOT select any submit button that is inside our own #wompi-simple-container!
                // Otherwise, when Wompi renders its widget inside the container, we select that button and try to insert the container inside itself,
                // causing a "Failed to execute 'insertBefore' on 'Node': The new child element contains the parent." HierarchyRequestError!
                var submitBtn = null;
                var buttons = form.querySelectorAll('#pay_now, button[type="submit"], input[type="submit"], button#pay_now');
                for (var i = 0; i < buttons.length; i++) {
                    var btn = buttons[i];
                    if (container.contains(btn)) {
                        continue; // Skip any buttons inside our own container!
                    }
                    submitBtn = btn;
                    break;
                }

                var payButtonWrap = document.getElementById('pay_button');
                if (submitBtn) {
                    if (payButtonWrap) {
                        // If payButtonWrap exists, always insert before it (and keep it there!)
                        if (container.parentNode !== payButtonWrap.parentNode) {
                            payButtonWrap.parentNode.insertBefore(container, payButtonWrap);
                        }
                    } else {
                        // Otherwise, insert before the submit button
                        if (container.parentNode !== submitBtn.parentNode) {
                            submitBtn.parentNode.insertBefore(container, submitBtn);
                        }
                    }
                } else {
                    // Append directly to the form if submitBtn is missing
                    form.appendChild(container);
                }
            } else if (isAdminView || isAdminUser) {
                // If we are in the admin preview or are an admin user and there is no form,
                // find a prominent place to insert our container so it can be previewed!
                // Commonly inside .invoice-preview, #invoice-preview, or simply append to body if nothing else.
                var previewArea = document.querySelector('.invoice-preview-container') || 
                                  document.querySelector('.invoice-preview') || 
                                  document.querySelector('#invoice-preview') ||
                                  document.querySelector('.panel-body');
                if (previewArea && container.parentNode !== previewArea) {
                    previewArea.appendChild(container);
                }
            }

            var show = selectedModeIsWompi();
            
            // If the logged-in user is an administrator, force display so they can preview the changes,
            // the branding, the logos, and verify settings.
            if (isAdminUser || isAdminView) {
                show = true;
            }

            container.style.display = show ? 'block' : 'none';
            container.setAttribute('aria-hidden', show ? 'false' : 'true');

            // If the widget isn't enabled (unlicensed, missing keys, or partial payments ON),
            // keep the standard Perfex submit flow. We still apply amount-field rules below.
            if (!wompiWidgetEnabled) {
                if (payButtonWrap) payButtonWrap.style.display = '';
                if (submitBtn) submitBtn.style.display = submitBtn.dataset.wompiOriginalDisplay || '';
            } else {
                // Licensed: hide Perfex submit completely when Wompi is selected (simple, avoids double-submit confusion).
                if (submitBtn) {
                    if (show && !isAdminView) { // Only hide the submit button for clients on actual payment pages
                        if (!submitBtn.dataset.wompiOriginalDisplay) {
                            submitBtn.dataset.wompiOriginalDisplay = submitBtn.style.display || '';
                        }
                        submitBtn.style.display = 'none';
                    } else {
                        submitBtn.style.display = submitBtn.dataset.wompiOriginalDisplay || '';
                    }
                }
                if (payButtonWrap) {
                    payButtonWrap.style.display = (show && !isAdminView) ? 'none' : '';
                }
            }

            // Amount field:
            // - When partial payments are disabled, hide the amount row entirely (and keep value fixed).
            // - When enabled, it remains visible/editable and we re-sign the widget on change.
            // Your invoice template always uses input[name="amount"] inside #online_payment_form.
            // Keep this targeted first, then fallback to generic selectors.
            var amountInputs = Array.prototype.slice.call(document.querySelectorAll(
                '#online_payment_form input[name="amount"],' +
                '#payment_amount,' +
                'input[name="amount"],' +
                'input[name="payment_amount"],' +
                'input[name="paymentamount"],' +
                'input[data-amount]'
            ));

            amountInputs.forEach(function(amountInput) {
                if (!amountInput) return;
                var row = amountInput.closest('.form-group, .col-md-12, .row, tr, .form-item');
                if (row) {
                    row.classList.add('wompi-amount-container-transition');
                }

                if (show && !allowPartial) {
                    amountInput.value = (invoiceTotalCents / 100).toFixed(2);
                    amountInput.readOnly = true;
                    if (row) row.classList.add('wompi-collapsed');
                } else if (show && allowPartial) {
                    amountInput.readOnly = false;
                    if (row) row.classList.remove('wompi-collapsed');

                    // Visual-only: COP is typically integer-only. Force integer UX in the amount input
                    // while keeping backend math in cents correct (we re-sign using the parsed value).
                    if (String(invoiceCurrency || '').toUpperCase() === 'COP') {
                        try {
                            amountInput.step = '1';
                            var n = parseAmountToMajorUnits(amountInput.value);
                            if (n != null) amountInput.value = String(Math.round(n));
                            var mx = parseAmountToMajorUnits(amountInput.getAttribute('max'));
                            if (mx != null) amountInput.setAttribute('max', String(Math.round(mx)));
                        } catch (e) {}
                    }
                } else {
                    amountInput.readOnly = false;
                    if (row) row.classList.remove('wompi-collapsed');
                }
            });

            // Partial payments: keep the widget signature in sync with the chosen amount.
            if (show && allowPartial && wompiWidgetEnabled) {
                var amountField = form.querySelector('input[name=\"amount\"]') || document.querySelector('#online_payment_form input[name=\"amount\"]');
                if (amountField) {
                    scheduleWidgetRefresh(amountField.value);
                }
            }

            // Ensure premium button interactions are wired up
            bindPremiumButton();
        }

        var _refreshTimer = null;
        var _lastAmountKey = null;

        function scheduleWidgetRefresh(amountValue) {
            if (_refreshTimer) clearTimeout(_refreshTimer);
            _refreshTimer = setTimeout(function() {
                refreshWidgetForAmount(amountValue);
            }, 250);
        }

        function parseAmountToMajorUnits(amountValue) {
            // Accept values like:
            //  - "4500000.00"
            //  - "4,500,000.00"
            //  - "4.500.000,00"
            //  - "4500000"
            var raw = String(amountValue == null ? '' : amountValue).trim();
            if (!raw) return null;

            // Remove currency symbols/spaces
            raw = raw.replace(/[^\d.,-]/g, '');

            // If both separators exist, assume the last one is the decimal separator
            var lastComma = raw.lastIndexOf(',');
            var lastDot = raw.lastIndexOf('.');
            var decSep = null;
            if (lastComma !== -1 && lastDot !== -1) {
                decSep = lastComma > lastDot ? ',' : '.';
            } else if (lastComma !== -1) {
                // If only comma exists, treat as decimal when it looks like cents (two digits after)
                decSep = (raw.length - lastComma - 1) === 2 ? ',' : null;
            } else if (lastDot !== -1) {
                decSep = (raw.length - lastDot - 1) === 2 ? '.' : null;
            }

            if (decSep) {
                var parts = raw.split(decSep);
                var intPart = parts[0].replace(/[.,]/g, '');
                var fracPart = (parts[1] || '').replace(/[^\d]/g, '').slice(0, 2);
                while (fracPart.length < 2) fracPart += '0';
                raw = intPart + '.' + fracPart;
            } else {
                // No clear decimal separator; remove thousand separators and parse as integer
                raw = raw.replace(/[.,]/g, '');
            }

            var n = parseFloat(raw);
            if (!isFinite(n)) return null;
            return n;
        }

        function refreshWidgetForAmount(amountValue) {
            if (!wompiSignatureEndpoint) return;

            var parsed = parseAmountToMajorUnits(amountValue);
            if (parsed == null || parsed <= 0) return;

            // Avoid hammering the endpoint if the value didn't change meaningfully.
            var key = parsed.toFixed(2);
            if (_lastAmountKey === key) return;
            _lastAmountKey = key;

            // Build form-encoded body; Perfex will inject CSRF token automatically for jQuery,
            // but we use fetch here so we rely on the cookie + same-origin + posted token if present.
            var body = 'amount=' + encodeURIComponent(key);

            // If Perfex defines global csrfData (it does in your HTML), include it explicitly.
            try {
                if (window.csrfData && window.csrfData.token_name && window.csrfData.hash) {
                    body += '&' + encodeURIComponent(window.csrfData.token_name) + '=' + encodeURIComponent(window.csrfData.hash);
                }
            } catch (e) {}

            fetch(wompiSignatureEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                credentials: 'same-origin',
                body: body
            }).then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            }).then(function(data) {
                if (!data || !data.public_key || !data.signature || !data.reference) return;
                rerenderWidget(data);
            }).catch(function() {
                // Keep existing widget; user can still pay full amount via last signature.
            });
        }

        function rerenderWidget(data) {
            var container = document.getElementById('wompi-simple-container');
            if (!container) return;
            var wrap = container.querySelector('.wompi-button-wrapper');
            if (!wrap) return;

            // Rebuild the widget script tag with the new amount/reference/signature.
            wrap.innerHTML = '';

            var form = document.createElement('form');
            var script = document.createElement('script');
            script.src = 'https://checkout.wompi.co/widget.js';
            script.setAttribute('data-render', 'button');
            script.setAttribute('data-public-key', String(data.public_key));
            script.setAttribute('data-currency', String(data.currency || 'COP'));
            script.setAttribute('data-amount-in-cents', String(data.amount_in_cents));
            script.setAttribute('data-reference', String(data.reference));
            script.setAttribute('data-signature:integrity', String(data.signature));
            script.setAttribute('data-redirect-url', String(data.redirect_url || ''));
            script.setAttribute('data-customer-data:invoice_id', String(data.invoice_id || ''));
            script.setAttribute('data-customer-data:hash', String(data.hash || ''));
            form.appendChild(script);
            wrap.appendChild(form);

            // Re-bind click event on our premium button
            resetPremiumButton();
        }

        function bindPremiumButton() {
            var premiumBtn = document.getElementById('wompi-premium-btn');
            if (!premiumBtn) return;

            if (premiumBtn.dataset.wompiBound) return;
            premiumBtn.dataset.wompiBound = 'true';

            premiumBtn.addEventListener('click', function(e) {
                e.preventDefault();

                var realForm = document.getElementById('wompi-real-form');
                if (!realForm) return;

                var realBtn = realForm.querySelector('button.wompi-button') || realForm.querySelector('button');
                if (realBtn) {
                    var btnText = document.getElementById('wompi-btn-text');
                    premiumBtn.classList.add('loading');
                    premiumBtn.disabled = true;
                    if (btnText) btnText.textContent = 'Abriendo pasarela segura...';

                    // Trigger actual click
                    realBtn.click();
                    
                    // Fallback reset if focus is lost/returned or after a reasonable timeout
                    setTimeout(function() {
                        resetPremiumButton();
                    }, 12000);
                } else {
                    var btnText = document.getElementById('wompi-btn-text');
                    if (btnText) {
                        var originalText = btnText.textContent;
                        btnText.textContent = 'Cargando pasarela...';
                        setTimeout(function() {
                            btnText.textContent = originalText;
                        }, 2000);
                    }
                }
            });
        }

        function resetPremiumButton() {
            var premiumBtn = document.getElementById('wompi-premium-btn');
            var btnText = document.getElementById('wompi-btn-text');
            if (premiumBtn) {
                premiumBtn.classList.remove('loading');
                premiumBtn.disabled = false;
            }
            if (btnText) {
                btnText.textContent = 'Pagar Factura de Forma Segura';
            }
        }

        // Listen for window focus to reset the button state if customer closes the modal
        window.addEventListener('focus', function() {
            resetPremiumButton();
        });

        function bindModeChanges() {
            if (isPaid) return; // Paid invoices require zero dynamic UI changes or listeners

            // Radios or selects depending on template.
            document.addEventListener('change', function(e) {
                var t = e.target;
                if (!t) return;
                if (t.name === 'payment_mode') toggleSimpleWidget();
                if (t.name === 'paymentmode') toggleSimpleWidget();
                if (t.id === 'pm_wompi' || t.id === 'payment_mode') toggleSimpleWidget();
                if (t.name === 'amount') toggleSimpleWidget();
            }, true);
            // Some themes/plugins bind click instead of change.
            document.addEventListener('click', function(e) {
                var t = e.target;
                if (!t) return;
                if (t.name === 'payment_mode') toggleSimpleWidget();
                if (t.name === 'paymentmode') toggleSimpleWidget();
            }, true);

            // Listen for typing in amount field (partial payments enabled).
            document.addEventListener('input', function(e) {
                var t = e.target;
                if (!t) return;
                if (t.name === 'amount') {
                    // Don't rely on "toggle" timing; refresh signature as the user types.
                    scheduleWidgetRefresh(t.value);
                }
            }, true);
        }

        function init() {
            bindModeChanges();

            // Mirror Perfex behavior: if there's exactly 1 payment option and it's Wompi, force-check it.
            // This avoids timing issues with jQuery themes that check it later.
            var form = findPaymentForm();
            if (form) {
                var onlyRadio = form.querySelectorAll('input[type="radio"]');
                if (onlyRadio.length === 1 && String(onlyRadio[0].value).toLowerCase() === 'wompi') {
                    onlyRadio[0].checked = true;
                }
            }

            toggleSimpleWidget();
            wompiStripTrailingZeros();

            // Some themes manipulate DOM after load; keep it in sync briefly.
            var tries = 0;
            var iv = setInterval(function() {
                toggleSimpleWidget();
                wompiStripTrailingZeros();
                tries++;
                if (tries >= 10) clearInterval(iv);
            }, 300);

            // Some Perfex themes auto-check the only payment method after DOM ready.
            // Watch for that and re-toggle once the radio state changes.
            setTimeout(toggleSimpleWidget, 1200);
            setTimeout(wompiStripTrailingZeros, 1200);

            // Perfex can update invoice totals/items after initial paint (both admin and customer views).
            // Observe the DOM briefly and re-apply cosmetic stripping when text changes.
            try {
                var start = Date.now();
                var obs = new MutationObserver(function() {
                    if (Date.now() - start > 10000) {
                        obs.disconnect();
                        return;
                    }
                    wompiStripTrailingZeros();
                });
                if (document.body) {
                    obs.observe(document.body, { subtree: true, childList: true, characterData: true });
                    setTimeout(function() { try { obs.disconnect(); } catch (e) {} }, 10000);
                }
            } catch (e) {}
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
        else init();
    })();
    </script>
    <?php
}

function wompi_render_backend_license_panel()
{
    $CI = &get_instance();
    $CI->load->library('wompi/Wompi_license');
    
    $is_valid = wompi_license_valid();
    $status = $CI->wompi_license->getStatus();
    $ctx = $CI->wompi_license->getVerifyContext();
    $expiry = $CI->wompi_license->getExpiryDate();
    
    // Status Badge & Color styling
    $status_badge_bg = '#fee2e2';
    $status_badge_color = '#991b1b';
    $status_badge_border = '#fca5a5';
    
    if ($status === 'Active' || $is_valid) {
        if ($status === 'Grace Period') {
            $status_badge_bg = '#ffedd5';
            $status_badge_color = '#c2410c';
            $status_badge_border = '#fdbb2d';
        } else {
            $status_badge_bg = '#dcfce7';
            $status_badge_color = '#15803d';
            $status_badge_border = '#86efac';
        }
    } elseif ($status === 'Grace Period') {
        $status_badge_bg = '#ffedd5';
        $status_badge_color = '#c2410c';
        $status_badge_border = '#fdbb2d';
    }
    
    // Format expiration and time remaining
    $expiry_display = !empty($expiry) ? date('Y-m-d', strtotime($expiry)) : _l('wompi_backend_license_unlimited');
    $remaining_display = _l('wompi_backend_license_unlimited');
    
    $trial_exp = get_option('wompi_trial_expires');
    $is_trial = false;
    
    if ($status === 'Active' && !empty($expiry) && strtotime($expiry) > 0) {
        $days_left = (int) ceil((strtotime($expiry) - time()) / 86400);
        $remaining_display = $days_left > 0 ? sprintf(_l('wompi_backend_license_days_left'), $days_left) : _l('wompi_license_expired');
    } elseif (!empty($trial_exp) && strtotime($trial_exp) > 0) {
        $days_left = (int) ceil((strtotime($trial_exp) - time()) / 86400);
        $is_trial = true;
        $remaining_display = $days_left > 0 ? sprintf(_l('wompi_backend_license_days_left'), $days_left) : _l('wompi_license_expired');
    }
    
    // Revalidation URL
    $revalidate_url = site_url('admin/settings?group=payment_gateways&wompi_revalidate=1');
    ?>
    <script>
    (function() {
        function initBackend() {
            var input = document.querySelector('[name="settings[paymentmethod_wompi_license_key]"]');
            if (!input) return;
            var formGroup = input.closest('.form-group');
            if (!formGroup) return;

            var card = document.createElement('div');
            card.className = 'wompi-lic-card';
            card.innerHTML = `
                <h4 class="wompi-lic-title">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #6366f1;">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>
                    </svg>
                    <?php echo _l('wompi_backend_license_title'); ?>
                </h4>
                <div>
                    <span class="wompi-lic-badge" style="background-color: <?php echo $status_badge_bg; ?>; color: <?php echo $status_badge_color; ?>; border-color: <?php echo $status_badge_border; ?>;">
                        <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
                <div class="wompi-lic-grid">
                    <div class="wompi-lic-item">
                        <span class="wompi-lic-label"><?php echo _l('wompi_backend_license_domain'); ?></span>
                        <span class="wompi-lic-value"><?php echo htmlspecialchars($ctx['domain'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="wompi-lic-item">
                        <span class="wompi-lic-label"><?php echo _l('wompi_backend_license_ip'); ?></span>
                        <span class="wompi-lic-value"><?php echo htmlspecialchars($ctx['ip'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="wompi-lic-item">
                        <span class="wompi-lic-label"><?php echo _l('wompi_backend_license_expiry'); ?></span>
                        <span class="wompi-lic-value"><?php echo htmlspecialchars($expiry_display, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="wompi-lic-item">
                        <span class="wompi-lic-label"><?php echo _l('wompi_backend_license_remaining'); ?></span>
                        <span class="wompi-lic-value" style="color: <?php echo ($status === 'Grace Period' || $is_trial) ? '#c2410c' : '#334155'; ?>;">
                            <?php echo htmlspecialchars($remaining_display, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                    </div>
                </div>
                <div class="wompi-lic-item" style="margin-top: 12px; grid-column: span 2;">
                    <span class="wompi-lic-label"><?php echo _l('wompi_backend_license_dir'); ?></span>
                    <span class="wompi-lic-value" style="font-size: 11px;"><?php echo htmlspecialchars($ctx['dir'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <a href="<?php echo $revalidate_url; ?>" class="wompi-lic-btn" onclick="this.innerHTML='<?php echo _l('wompi_backend_license_revalidating'); ?>';">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;margin-right:4px;">
                        <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path>
                    </svg>
                    <?php echo _l('wompi_backend_license_revalidate'); ?>
                </a>
            `;
            formGroup.parentNode.insertBefore(card, formGroup);
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initBackend);
        } else {
            initBackend();
        }
    })();
    </script>
    <?php
}


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
    if (!$is_valid) {
        $CI->load->library('wompi/Wompi_license');
        $status = $CI->wompi_license->getStatus();
        $ctx    = $CI->wompi_license->getVerifyContext();
        $tx     = $CI->wompi_license->getLastTransportInfo();
        echo '<div class="alert alert-warning alert-dismissible wompi-admin-notice">'
            . '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            . '⚠️ La licencia de <strong>Wompi Payment Gateway</strong> es inválida o ha expirado. '
            . 'Estado: <strong>' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</strong>. '
            . '<br><small>Validando como Domain=' . htmlspecialchars($ctx['domain'], ENT_QUOTES, 'UTF-8')
            . ' IP=' . htmlspecialchars($ctx['ip'], ENT_QUOTES, 'UTF-8')
            . ' Dir=' . htmlspecialchars($ctx['dir'], ENT_QUOTES, 'UTF-8')
            . ' HTTP=' . htmlspecialchars((string) ($tx['http_code'] ?? ''), ENT_QUOTES, 'UTF-8')
            . (!empty($tx['curl_err']) ? ' cURL=' . htmlspecialchars($tx['curl_err'], ENT_QUOTES, 'UTF-8') : '')
            . '</small> '
            . '<a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renueva aquí</a>.'
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
    $is_client = $CI->uri->segment(1) === 'invoice' || $CI->uri->segment(1) === 'invoices';
    $is_admin  = $CI->uri->segment(1) === 'admin' && ($CI->uri->segment(2) === 'invoices' || $CI->uri->segment(2) === 'payments');
    $is_admin_payment_gateways = $CI->uri->segment(1) === 'admin'
        && $CI->uri->segment(2) === 'settings'
        && $CI->input->get('group') === 'payment_gateways';

    if (!$is_client && !$is_admin && !$is_admin_payment_gateways) {
        return;
    }

    // On the payment gateways settings page, we only need to trigger license validation (and logs).
    // No invoice context is available there.
    if ($is_admin_payment_gateways) {
        wompi_license_valid();
        return;
    }

    // Always inject the UI script so we can hide the amount field when Wompi is selected,
    // even if the license is currently invalid.
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
    // We support this by calling a server-side endpoint that returns a fresh reference+signature
    // for the chosen amount, without exposing the integrity secret to the browser.
    $allow_partial   = (get_option('paymentmethod_wompi_allow_partial_payments') === '1');
    $integrity_secret = $gateway->decryptSetting('integrity_secret');

    $can_render_widget = $licensed && !empty($public_key) && !empty($integrity_secret);

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

    ?>
    <style id="wompi-premium-styles">
        /* Base Premium Styling for Wompi Checkout & Confirmation */
        .wompi-premium-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px;
            margin-top: 15px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            text-align: left;
            width: 100%;
            max-width: 100%;
            box-sizing: border-box;
        }
        .wompi-premium-panel:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            border-color: #cbd5e1;
        }
        .wompi-premium-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
            border-bottom: 1px dashed #e2e8f0;
            padding-bottom: 14px;
        }
        .wompi-premium-title {
            font-size: 14px;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .wompi-premium-title svg {
            color: #6366f1;
        }
        .wompi-secure-badge {
            font-size: 11px;
            background: #f0fdf4;
            color: #166534;
            padding: 4px 10px;
            border-radius: 30px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 4px;
            border: 1px solid #bbf7d0;
        }
        
        /* Grid of logos in a single horizontal line */
        .wompi-logos-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 20px;
            width: 100%;
        }
        .wompi-logo-item {
            background: #f8fafc;
            border: 1px solid #f1f5f9;
            border-radius: 12px;
            padding: 12px 4px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            min-height: 84px;
        }
        .wompi-logo-item:hover {
            background: #ffffff;
            border-color: #cbd5e1;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.04);
        }
        .wompi-logo-img {
            max-height: 34px;
            max-width: 90%;
            object-fit: contain;
            filter: grayscale(10%) contrast(105%);
            transition: all 0.25s ease;
        }
        .wompi-logo-item:hover .wompi-logo-img {
            filter: grayscale(0%) contrast(110%);
        }
        .wompi-logo-caption {
            font-size: 8px;
            color: #475569;
            margin-top: 8px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 0.1px;
            white-space: nowrap;
        }

        /* Combined Visa & Mastercard wrapper inside the 5th card */
        .wompi-cards-wrapper {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            max-width: 90%;
            height: 34px;
        }
        .wompi-logo-img.card-brand {
            max-height: 24px;
            width: auto;
            max-width: 45%;
        }
        
        /* Premium custom button */
        .wompi-btn-premium {
            width: 100%;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            padding: 14px 24px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.12);
            text-transform: none !important;
            letter-spacing: 0.1px;
        }
        .wompi-btn-premium:hover {
            background: linear-gradient(135deg, #1e293b 0%, #334155 100%);
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.2);
            color: #ffffff !important;
        }
        .wompi-btn-premium:active {
            transform: translateY(0);
        }
        
        /* Shimmer reflection animation */
        .wompi-btn-premium::after {
            content: '';
            position: absolute;
            top: 0;
            left: -50%;
            width: 30%;
            height: 100%;
            background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.15) 50%, rgba(255,255,255,0) 100%);
            transform: skewX(-25deg);
            animation: wompiShimmer 5s infinite;
        }
        @keyframes wompiShimmer {
            0% { left: -150%; }
            30% { left: 150%; }
            100% { left: 150%; }
        }
        
        /* Loading spinner */
        .wompi-spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.35);
            border-radius: 50%;
            border-top-color: #ffffff;
            animation: wompiSpin 0.8s linear infinite;
            display: none;
        }
        @keyframes wompiSpin {
            to { transform: rotate(360deg); }
        }
        
        /* Active loading state */
        .wompi-btn-premium.loading {
            pointer-events: none;
            opacity: 0.9;
            background: #1e293b;
        }
        .wompi-btn-premium.loading .wompi-spinner {
            display: inline-block;
        }
        .wompi-btn-premium.loading .wompi-btn-icon {
            display: none;
        }
        
        .wompi-btn-icon {
            transition: transform 0.2s ease;
        }
        .wompi-btn-premium:hover .wompi-btn-icon {
            transform: translateX(1px);
        }
        
        /* Footnote */
        .wompi-premium-footer {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            margin-top: 14px;
            font-size: 11px;
            color: #64748b;
        }
        .wompi-premium-footer svg {
            color: #94a3b8;
        }
        
        /* Hide Wompi's default button completely */
        #wompi-simple-container .wompi-button-wrapper button.wompi-button {
            display: none !important;
        }

        /* Accessible visually-hidden helper to allow script dimensions to initialize */
        .wompi-hidden-accessible {
            position: absolute !important;
            width: 1px !important;
            height: 1px !important;
            padding: 0 !important;
            margin: -1px !important;
            overflow: hidden !important;
            clip: rect(0, 0, 0, 0) !important;
            white-space: nowrap !important;
            border: 0 !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }
        
        /* Responsive adjustments for mobile devices */
        @media (max-width: 480px) {
            .wompi-logos-grid {
                grid-template-columns: repeat(5, 1fr);
                gap: 4px;
            }
            .wompi-logo-item {
                min-height: 64px;
                padding: 8px 2px;
                border-radius: 8px;
            }
            .wompi-logo-img {
                max-height: 24px;
            }
            .wompi-cards-wrapper {
                height: 24px;
                gap: 2px;
            }
            .wompi-logo-caption {
                font-size: 7px;
                margin-top: 5px;
            }
            .wompi-premium-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
            }
            .wompi-secure-badge {
                align-self: flex-start;
            }
        }
    </style>

    <?php if ($is_paid): ?>
        <!-- Beautiful Paid Confirmation Card -->
        <div class="wompi-premium-panel wompi-premium-paid-card" style="margin-top: 15px; border-color: #a7f3d0; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.05); display: none;">
            <div class="wompi-premium-header" style="border-bottom-color: #ecfdf5;">
                <h4 class="wompi-premium-title" style="color: #059669;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="color: #10b981; vertical-align: middle; margin-right: 6px; display: inline-block;">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Orden de Compra Pagada
                </h4>
                <span class="wompi-secure-badge" style="background: #ecfdf5; color: #047857; border-color: #a7f3d0; font-weight: 700;">
                    Pago Confirmado
                </span>
            </div>
            
            <div class="wompi-paid-details" style="font-size: 13px; color: #475569; line-height: 1.6;">
                <p style="margin: 0 0 12px;">Esta factura ha sido liquidada de forma segura mediante nuestra pasarela. El saldo actual es de <strong>$0.00</strong>.</p>
                <?php if ($wompi_payment): ?>
                    <div style="background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px; padding: 14px; margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 500;">ID de Aprobación:</span>
                            <strong style="color: #0f172a; font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($wompi_payment->transactionid ?: 'N/A'); ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; margin-bottom: 6px; border-bottom: 1px dashed #e2e8f0; padding-bottom: 6px;">
                            <span style="color: #64748b; font-weight: 500;">Fecha de Pago:</span>
                            <strong style="color: #0f172a;"><?php echo date('d/m/Y h:i A', strtotime($wompi_payment->date)); ?></strong>
                        </div>
                        <div style="display: flex; justify-content: space-between; padding-top: 2px;">
                            <span style="color: #64748b; font-weight: 500;">Monto Recibido:</span>
                            <strong style="color: #059669; font-size: 14px;"><?php echo htmlspecialchars($currency) . ' ' . number_format($wompi_payment->amount, 2, '.', ','); ?></strong>
                        </div>
                    </div>
                <?php else: ?>
                    <div style="background: #f8fafc; border: 1px solid #f1f5f9; border-radius: 12px; padding: 14px; margin-top: 10px;">
                        <div style="display: flex; justify-content: space-between;">
                            <span style="color: #64748b; font-weight: 500;">Estado de Factura:</span>
                            <strong style="color: #059669; font-size: 13px;">Completado / Pagado</strong>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div id="wompi-simple-container" aria-hidden="true">
        <?php if ($can_render_widget && !$is_paid): ?>
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

            <!-- Beautiful Premium Panel -->
            <div class="wompi-premium-panel">
                <div class="wompi-premium-header">
                    <h4 class="wompi-premium-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        Métodos de Pago Disponibles
                    </h4>
                    <span class="wompi-secure-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                        Pago 100% Seguro
                    </span>
                </div>
                
                <div class="wompi-logos-grid">
                    <div class="wompi-logo-item" title="PSE - Pagos Seguros en Línea">
                        <img class="wompi-logo-img" src="<?php echo $pse_logo; ?>" alt="PSE">
                        <span class="wompi-logo-caption">PSE / Bancos</span>
                    </div>
                    <div class="wompi-logo-item" title="Bancolombia">
                        <img class="wompi-logo-img" src="<?php echo $bancolombia_logo; ?>" alt="Bancolombia">
                        <span class="wompi-logo-caption">Bancolombia</span>
                    </div>
                    <div class="wompi-logo-item" title="Nequi">
                        <img class="wompi-logo-img" src="<?php echo $nequi_logo; ?>" alt="Nequi">
                        <span class="wompi-logo-caption">Nequi</span>
                    </div>
                    <div class="wompi-logo-item" title="Daviplata">
                        <img class="wompi-logo-img" src="<?php echo $daviplata_logo; ?>" alt="Daviplata">
                        <span class="wompi-logo-caption">Daviplata</span>
                    </div>
                    <div class="wompi-logo-item" title="Tarjetas de Crédito y Débito (Visa, Mastercard)">
                        <div class="wompi-cards-wrapper">
                            <img class="wompi-logo-img card-brand" src="<?php echo $visa_logo; ?>" alt="Visa">
                            <img class="wompi-logo-img card-brand" src="<?php echo $mastercard_logo; ?>" alt="Mastercard">
                        </div>
                        <span class="wompi-logo-caption">Tarjetas</span>
                    </div>
                </div>
                
                <div class="wompi-premium-button-container">
                    <button type="button" class="wompi-btn-premium" id="wompi-premium-btn">
                        <div class="wompi-spinner"></div>
                        <svg class="wompi-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                        </svg>
                        <span id="wompi-btn-text">Pagar Factura de Forma Segura</span>
                    </button>
                </div>
                
                <div class="wompi-premium-footer">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: middle;">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                    <span>Respaldado por Bancolombia · Conexión Cifrada SSL</span>
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

        function findPaymentForm() {
            return document.querySelector('#online_payment_form') || document.querySelector('#invoice_payment_form');
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
            // Common Perfex templates: radio group name="payment_mode"
            var radio = document.querySelector('input[type="radio"][name="payment_mode"][value="wompi"]:checked');
            if (radio) return true;

            // Your template uses name="paymentmode"
            var radio2 = document.querySelector('input[type="radio"][name="paymentmode"][value="wompi"]:checked');
            if (radio2) return true;

            // Also support the concrete id used in your HTML.
            var byId = document.getElementById('pm_wompi');
            if (byId && byId.checked) return true;
            // If it's the ONLY online payment option and it's Wompi, treat it as selected even if
            // the theme auto-check happens after our script runs.
            if (byId && String(byId.value).toLowerCase() === 'wompi') {
                var form = findPaymentForm();
                if (form) {
                    var wompiRadios = form.querySelectorAll('input[type="radio"][value="wompi"]');
                    var allRadios   = form.querySelectorAll('input[type="radio"]');
                    if (wompiRadios.length === 1 && allRadios.length === 1) {
                        return true;
                    }
                }
            }

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
            if (isPaid) return; // Never run toggle on paid invoices

            var form = findPaymentForm();
            var container = document.getElementById('wompi-simple-container');
            if (!form || !container) return;

            // Replace the original Perfex submit button in-place (same location in the DOM).
            var submitBtn = form.querySelector('#pay_now, button[type="submit"], input[type="submit"]');
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
            // keep the standard Perfex submit flow. We still apply amount-field rules below.
            if (!wompiWidgetEnabled) {
                if (payButtonWrap) payButtonWrap.style.display = '';
                if (submitBtn) submitBtn.style.display = submitBtn.dataset.wompiOriginalDisplay || '';
            } else {
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
                    amountInput.style.display = '';
                    if (row) row.style.display = '';
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

        // Safe DOM placement of the Paid Confirmation Card inside the invoice view
        function placePaidCard() {
            try {
                var card = document.querySelector('.wompi-premium-paid-card');
                if (!card) return;

                // Find public client invoice or admin preview main panels
                var container = document.querySelector('.invoice') 
                    || document.querySelector('.invoice-preview') 
                    || document.querySelector('.panel-body')
                    || document.querySelector('.col-md-12')
                    || document.querySelector('.main-content')
                    || document.querySelector('main');

                if (container) {
                    container.appendChild(card);
                    card.style.display = 'block';
                } else {
                    card.style.display = 'block'; // fallback
                }
            } catch (e) {}
        }

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
            
            // If the invoice is paid, place our beautiful card dynamically inside the main panel
            if (isPaid) {
                placePaidCard();
            }

            // Some themes manipulate DOM after load; keep it in sync briefly.
            var tries = 0;
            var iv = setInterval(function() {
                toggleSimpleWidget();
                wompiStripTrailingZeros();
                tries++;
                if (tries >= 10) {
                    clearInterval(iv);
                    if (isPaid) placePaidCard(); // re-ensure placement on late paints
                }
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

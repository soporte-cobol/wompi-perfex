<?php
$lang['settings_paymentmethod_wompi_public_key']              = 'Public Key';
$lang['settings_paymentmethod_wompi_private_key']             = 'Private Key';
$lang['settings_paymentmethod_wompi_integrity_secret']        = 'Integrity Secret';
$lang['settings_paymentmethod_wompi_events_secret']           = 'Events Secret (Webhooks)';
$lang['settings_paymentmethod_wompi_test_mode']               = 'Sandbox Mode (Testing)';
$lang['settings_paymentmethod_wompi_allow_partial_payments']  = 'Allow partial payments (customer can edit amount)';
$lang['settings_paymentmethod_wompi_license_key']             = 'License Key';
$lang['wompi_payment_success']                                = 'Payment successfully processed by Wompi!';
$lang['wompi_payment_failed']                                 = 'Wompi payment could not be verified or failed.';
$lang['wompi_license_invalid']                                = 'Invalid or expired Wompi license. Please enter a valid key in Settings → Payment Gateways → Wompi.';
$lang['wompi_license_active']                                 = 'License active';
$lang['wompi_license_trial']                                  = 'Trial period active';
$lang['wompi_license_expired']                                = 'License expired. Renew at control.cobol.com.co';

// Admin panel notifications (internationalization)
$lang['wompi_admin_notice_trial_warning']                     = '⚠️ Your trial period for <strong>Wompi Payment Gateway</strong> expires in <strong>%s day(s)</strong>. <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Activate your plan here</a>.';
$lang['wompi_admin_notice_trial_expired']                     = '🚫 Your trial for <strong>Wompi Payment Gateway</strong> has expired. <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renew your license</a> to continue receiving payments.';
$lang['wompi_admin_notice_no_key']                            = '🔑 <strong>Wompi Payment Gateway</strong> is activating your 30-day free trial... If it does not activate automatically, <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">get your license here</a>.';
$lang['wompi_admin_notice_invalid']                           = '⚠️ The license for <strong>Wompi Payment Gateway</strong> is invalid or has expired. Status: <strong>%s</strong>. <br><small>Validating as Domain=%s IP=%s Dir=%s HTTP=%s %s</small> <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renew here</a>.';

// Backend settings license panel
$lang['wompi_backend_license_title']                          = '🛡️ WOMPI PAYMENT GATEWAY LICENSE STATUS';
$lang['wompi_backend_license_status']                         = 'License Status';
$lang['wompi_backend_license_domain']                         = 'Authorized Domain';
$lang['wompi_backend_license_ip']                             = 'Server IP';
$lang['wompi_backend_license_dir']                            = 'Local Path';
$lang['wompi_backend_license_expiry']                         = 'Expiration';
$lang['wompi_backend_license_remaining']                      = 'Time Remaining';
$lang['wompi_backend_license_revalidate']                     = 'Revalidate License Now 🔄';
$lang['wompi_backend_license_unlimited']                      = 'Unlimited / Lifetime';
$lang['wompi_backend_license_revalidating']                   = 'Revalidating...';
$lang['wompi_backend_license_days_left']                      = '%s day(s) remaining';

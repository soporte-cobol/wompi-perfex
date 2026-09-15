<?php
$lang['settings_paymentmethod_wompi_public_key']              = 'Llave Pública';
$lang['settings_paymentmethod_wompi_private_key']             = 'Llave Privada';
$lang['settings_paymentmethod_wompi_integrity_secret']        = 'Secreto de Integridad';
$lang['settings_paymentmethod_wompi_events_secret']           = 'Secreto de Eventos (Webhooks)';
$lang['settings_paymentmethod_wompi_test_mode']               = 'Modo Sandbox (Pruebas)';
$lang['settings_paymentmethod_wompi_allow_partial_payments']  = 'Permitir pagos parciales (el cliente puede cambiar el monto)';
$lang['settings_paymentmethod_wompi_license_key']             = 'Llave de Licencia';
$lang['wompi_payment_success']                                = '¡Pago procesado con éxito por Wompi!';
$lang['wompi_payment_failed']                                 = 'El pago de Wompi no pudo ser verificado o falló.';
$lang['wompi_license_invalid']                                = 'Licencia Wompi inválida o expirada. Por favor ingresa una llave válida en Ajustes → Pago → Wompi.';
$lang['wompi_license_active']                                 = 'Licencia activa';
$lang['wompi_license_trial']                                  = 'Período de prueba activo';
$lang['wompi_license_expired']                                = 'Licencia expirada. Renueva en control.cobol.com.co';

// Admin panel notifications (internationalization)
$lang['wompi_admin_notice_trial_warning']                     = '⚠️ Tu período de prueba de <strong>Wompi Payment Gateway</strong> vence en <strong>%s día(s)</strong>. <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Activa tu plan aquí</a>.';
$lang['wompi_admin_notice_trial_expired']                     = '🚫 Tu prueba de <strong>Wompi Payment Gateway</strong> ha expirado. <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renueva tu licencia</a> para seguir recibiendo pagos.';
$lang['wompi_admin_notice_no_key']                            = '🔑 <strong>Wompi Payment Gateway</strong> está activando tu trial gratuito de 30 días... Si no se activa automáticamente, <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">obtén tu licencia aquí</a>.';
$lang['wompi_admin_notice_invalid']                           = '⚠️ La licencia de <strong>Wompi Payment Gateway</strong> es inválida o ha expirado. Estado: <strong>%s</strong>. <br><small>Validando como Domain=%s IP=%s Dir=%s HTTP=%s %s</small> <a href="https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex" target="_blank">Renueva aquí</a>.';

// Backend settings license panel
$lang['wompi_backend_license_title']                          = '🛡️ ESTADO DE LICENCIA WOMPI PAYMENT GATEWAY';
$lang['wompi_backend_license_status']                         = 'Estado de la Licencia';
$lang['wompi_backend_license_domain']                         = 'Dominio Autorizado';
$lang['wompi_backend_license_ip']                             = 'IP del Servidor';
$lang['wompi_backend_license_dir']                            = 'Ruta Local';
$lang['wompi_backend_license_expiry']                         = 'Vencimiento';
$lang['wompi_backend_license_remaining']                      = 'Tiempo Restante';
$lang['wompi_backend_license_revalidate']                     = 'Revalidar Licencia ahora 🔄';
$lang['wompi_backend_license_unlimited']                      = 'Ilimitado / Vitalicio';
$lang['wompi_backend_license_revalidating']                   = 'Revalidando...';
$lang['wompi_backend_license_days_left']                      = '%s día(s) restante(s)';


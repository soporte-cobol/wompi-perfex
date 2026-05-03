<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Wompi_gateway extends App_gateway
{
    public function __construct()
    {
        $this->setId('wompi');
        $this->setName('Wompi (Bancolombia)');

        parent::__construct();

        $this->setSettings([
            [
                'name'  => 'license_key',
                'label' => 'settings_paymentmethod_wompi_license_key',
            ],
            [
                'name'          => 'currencies',
                'label'         => 'settings_paymentmethod_currencies',
                'default_value' => 'COP',
            ],
            [
                'name'  => 'public_key',
                'label' => 'settings_paymentmethod_wompi_public_key',
            ],
            [
                'name'      => 'private_key',
                'label'     => 'settings_paymentmethod_wompi_private_key',
                'encrypted' => true,
            ],
            [
                'name'      => 'integrity_secret',
                'label'     => 'settings_paymentmethod_wompi_integrity_secret',
                'encrypted' => true,
            ],
            [
                'name'      => 'events_secret',
                'label'     => 'settings_paymentmethod_wompi_events_secret',
                'encrypted' => true,
            ],
            [
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'Pago de Factura {invoice_number}',
            ],
            [
                'name'          => 'test_mode',
                'type'          => 'yes_no',
                'default_value' => 1,
                'label'         => 'settings_paymentmethod_wompi_test_mode',
            ],
            [
                'name'          => 'allow_partial_payments',
                'type'          => 'yes_no',
                'default_value' => 0,
                'label'         => 'settings_paymentmethod_wompi_allow_partial_payments',
            ],
        ]);
    }

    public function process_payment($data)
    {
        $invoice          = $data['invoice'];
        $amount           = $data['amount'];
        $currency         = $invoice->currency_name;
        $amount_in_cents  = (int) round(floatval($amount) * 100);
        $reference        = $data['invoiceid'] . '_' . time();
        $integrity_secret = $this->decryptSetting('integrity_secret');
        $signature        = hash('sha256', $reference . $amount_in_cents . $currency . $integrity_secret);
        $public_key       = $this->getSetting('public_key');
        $redirect_url     = site_url('wompi/callback/response');

        echo payment_gateway_head('Procesando Pago Seguro');
        ?>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&display=swap');
            
            :root {
                --primary: #0f172a;
                --accent: #6366f1;
                --accent-glow: rgba(99, 102, 241, 0.2);
                --success: #10b981;
                --text-main: #1e293b;
                --text-muted: #64748b;
                --glass: rgba(255, 255, 255, 0.8);
                --glass-border: rgba(255, 255, 255, 0.2);
            }

            body { 
                background: radial-gradient(circle at top left, #f8fafc, #e2e8f0);
                font-family: 'Outfit', sans-serif; 
                margin: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                overflow: hidden;
            }

            /* Animated Background Shapes */
            .bg-shape {
                position: absolute;
                z-index: -1;
                filter: blur(80px);
                border-radius: 50%;
                opacity: 0.4;
                animation: move 20s infinite alternate;
            }
            .shape-1 { width: 400px; height: 400px; background: #6366f1; top: -100px; right: -100px; }
            .shape-2 { width: 300px; height: 300px; background: #a855f7; bottom: -50px; left: -50px; animation-delay: -5s; }

            @keyframes move {
                from { transform: translate(0, 0); }
                to { transform: translate(100px, 50px); }
            }

            .wompi-card {
                background: var(--glass);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                border: 1px solid var(--glass-border);
                border-radius: 32px;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.1);
                padding: 48px;
                max-width: 480px;
                width: 90%;
                text-align: center;
                position: relative;
                overflow: hidden;
            }

            .wompi-logo {
                width: 130px;
                margin-bottom: 32px;
                filter: drop-shadow(0 4px 6px rgba(0,0,0,0.05));
            }

            .loader-wrapper {
                position: relative;
                display: inline-flex;
                margin-bottom: 32px;
            }

            .loader-ring {
                width: 64px;
                height: 64px;
                border: 4px solid #f1f5f9;
                border-top: 4px solid var(--accent);
                border-radius: 50%;
                animation: spin 1s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            }

            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }

            .summary {
                background: rgba(0,0,0,0.02);
                border-radius: 20px;
                padding: 24px;
                margin-bottom: 32px;
                text-align: left;
                border: 1px solid rgba(0,0,0,0.03);
            }

            .summary-item {
                display: flex;
                justify-content: space-between;
                margin-bottom: 12px;
                font-size: 0.9rem;
            }

            .summary-label { color: var(--text-muted); }
            .summary-value { color: var(--text-main); font-weight: 600; }

            .summary-total {
                margin-top: 16px;
                padding-top: 16px;
                border-top: 1px dashed #cbd5e1;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }

            .total-label { font-weight: 600; color: var(--primary); }
            .total-value { font-size: 1.5rem; font-weight: 800; color: var(--accent); }

            .status-msg {
                font-size: 1.2rem;
                font-weight: 600;
                color: var(--primary);
                margin-bottom: 8px;
            }

            .status-sub {
                font-size: 0.95rem;
                color: var(--text-muted);
                margin-bottom: 0;
            }

            .btn-wompi-fallback {
                display: none;
                background: var(--accent);
                color: white;
                border: none;
                padding: 18px 36px;
                border-radius: 16px;
                font-weight: 600;
                font-size: 1rem;
                cursor: pointer;
                box-shadow: 0 10px 15px -3px var(--accent-glow);
                transition: all 0.3s;
                margin-top: 24px;
            }

            .btn-wompi-fallback:hover {
                transform: translateY(-2px);
                box-shadow: 0 20px 25px -5px var(--accent-glow);
            }

            #wompi-button-container {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }
        </style>

        <div class="bg-shape shape-1"></div>
        <div class="bg-shape shape-2"></div>

        <div class="wompi-card">
            <img class="wompi-logo" src="https://wompi.com/assets/downloadble/logos_wompi/Wompi_LogoPrincipal.svg" alt="Wompi">
            
            <div class="loader-wrapper">
                <div class="loader-ring"></div>
            </div>

            <div class="status-msg">Iniciando pago seguro</div>
            <p class="status-sub">Conectando con los servidores de Wompi...</p>

            <div class="summary">
                <div class="summary-item">
                    <span class="summary-label">Referencia</span>
                    <span class="summary-value">#<?php echo $reference; ?></span>
                </div>
                <div class="summary-item">
                    <span class="summary-label">Factura</span>
                    <span class="summary-value"><?php echo format_invoice_number($data['invoiceid']); ?></span>
                </div>
                <div class="summary-total">
                    <span class="total-label">Total a pagar</span>
                    <span class="total-value"><?php echo $currency . ' ' . number_format($amount, 2, '.', ','); ?></span>
                </div>
            </div>
            
            <button id="fallback-btn" class="btn-wompi-fallback" style="display:none;" onclick="location.reload()">Reintentar Conexión</button>
        </div>

        <script src="https://checkout.wompi.co/widget.js"></script>
        <script>
            var checkout;
            var opened = false;

            function initAndOpen() {
                if (typeof WompiCheckout === 'undefined') return false;
                if (opened) return true;

                try {
                    checkout = new WompiCheckout({
                        publicKey: "<?php echo $public_key; ?>",
                        currency: "<?php echo $currency; ?>",
                        amountInCents: <?php echo $amount_in_cents; ?>,
                        reference: "<?php echo $reference; ?>",
                        signature: "<?php echo $signature; ?>",
                        redirectUrl: "<?php echo $redirect_url; ?>",
                        customerData: {
                            invoice_id: "<?php echo $data['invoiceid']; ?>",
                            hash: "<?php echo $invoice->hash; ?>"
                        }
                    });

                    checkout.open(function ( result ) {
                        var transaction = result.transaction;
                        if (transaction.status === 'APPROVED' || transaction.status === 'DECLINED' || transaction.status === 'VOIDED') {
                            window.location.href = "<?php echo $redirect_url; ?>?id=" + transaction.id;
                        }
                    });
                    opened = true;
                    return true;
                } catch (e) {
                    console.error("Wompi Init Error:", e);
                    return false;
                }
            }

            // Reintento agresivo cada 500ms
            var checkInterval = setInterval(function() {
                if (initAndOpen()) {
                    clearInterval(checkInterval);
                }
            }, 500);

            // Tiempo límite de 10 segundos
            setTimeout(function() {
                clearInterval(checkInterval);
                if (!opened) {
                    document.querySelector('.status-msg').innerText = 'Error de Conexión';
                    document.querySelector('.status-sub').innerText = 'No se pudo conectar con Wompi. Por favor revisa tu conexión.';
                    document.getElementById('fallback-btn').style.display = 'inline-block';
                    document.querySelector('.loader-wrapper').style.display = 'none';
                }
            }, 10000);
        </script>
        <?php
        echo payment_gateway_footer();
    }
}

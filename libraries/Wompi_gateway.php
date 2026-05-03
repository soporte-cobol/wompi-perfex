<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Wompi_gateway extends App_gateway
{
    public function __construct()
    {
        $this->setId('wompi');
        $this->setName('Wompi (Bancolombia)');

        parent::__construct();

        // Perfex loads gateway libraries on the payment gateways settings page.
        // Trigger license validation there so admins see the real state and logs are emitted.
        try {
            if (function_exists('wompi_license_valid')) {
                $CI = &get_instance();
                if ($CI && $CI->uri->segment(1) === 'admin'
                    && $CI->uri->segment(2) === 'settings'
                    && $CI->input->get('group') === 'payment_gateways') {
                    wompi_license_valid();
                }
            }
        } catch (Throwable $e) {
            // Don't break admin pages if validation fails unexpectedly.
        }

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
                // Wompi "integrity secret" used to sign the checkout widget.
                'name'      => 'integrity_secret',
                'label'     => 'settings_paymentmethod_wompi_integrity_secret',
                'encrypted' => true,
            ],
            [
                // Wompi events/webhooks secret for signature verification.
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
        // UX is kept on the invoice page (official widget button + modal).
        $invoice = $data['invoice'];

        if (!function_exists('wompi_license_valid') || !wompi_license_valid()) {
            set_alert('warning', _l('wompi_license_invalid'));
        }

        redirect(site_url('invoice/' . $data['invoiceid'] . '/' . $invoice->hash));
    }
}

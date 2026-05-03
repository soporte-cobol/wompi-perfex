<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Wompi Callback Controller
 *
 * Handles:
 *  1. response()  — Browser redirect after customer completes (or abandons) payment.
 *  2. webhook()   — Asynchronous server-to-server event notification from Wompi.
 *
 * @version 1.1.0
 */
class Callback extends App_Controller
{
    /** @var string Wompi Sandbox base URL */
    private const API_SANDBOX    = 'https://sandbox.wompi.co/v1';

    /** @var string Wompi Production base URL */
    private const API_PRODUCTION = 'https://production.wompi.co/v1';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('wompi/wompi_gateway');
        $this->load->model('payments_model');
        $this->load->model('invoices_model');
    }

    // -------------------------------------------------------------------------
    // 1. BROWSER REDIRECT — Cliente regresa desde el checkout de Wompi
    // -------------------------------------------------------------------------

    /**
     * Wompi redirects the customer here after the checkout flow.
     * URL example: /wompi/callback/response?id=<transaction_id>
     */
    public function response()
    {
        $transaction_id = $this->input->get('id');

        if (empty($transaction_id)) {
            set_alert('danger', _l('wompi_payment_failed'));
            redirect(site_url());
            return;
        }

        $transaction = $this->_fetch_transaction($transaction_id);

        if (!$transaction) {
            set_alert('danger', _l('wompi_payment_failed'));
            redirect(site_url());
            return;
        }

        $status     = $transaction['status']                    ?? '';
        $invoice_id = $transaction['custom_data']['invoice_id'] ?? '';
        $hash       = $transaction['custom_data']['hash']       ?? '';
        $amount     = ($transaction['amount_in_cents'] ?? 0) / 100;
        $currency   = $transaction['currency']                  ?? 'COP';
        $tx_id      = $transaction['id']                        ?? $transaction_id;

        // Validate we have all the data we need
        if (empty($invoice_id) || empty($hash)) {
            $this->_render_result([
                'status'         => 'ERROR',
                'transaction_id' => $tx_id,
                'amount'         => 0,
                'currency'       => 'COP',
                'invoice_url'    => site_url(),
                'redirect_delay' => 5,
            ]);
            return;
        }

        // Make sure the invoice actually exists and the hash matches
        $invoice = $this->invoices_model->get($invoice_id);
        if (!$invoice || $invoice->hash !== $hash) {
            $this->_render_result([
                'status'         => 'ERROR',
                'transaction_id' => $tx_id,
                'amount'         => 0,
                'currency'       => 'COP',
                'invoice_url'    => site_url(),
                'redirect_delay' => 5,
            ]);
            return;
        }

        // Register payment in Perfex if approved
        if ($status === 'APPROVED' && !$this->_payment_exists($tx_id)) {
            $this->payments_model->add([
                'amount'        => $amount,
                'invoiceid'     => $invoice_id,
                'paymentmode'   => 'wompi',
                'transactionid' => $tx_id,
                'note'          => 'Pago aprobado vía Wompi Checkout',
            ]);
        }

        // Render the custom result view (auto-redirects after delay)
        $this->_render_result([
            'status'         => $status,
            'transaction_id' => $tx_id,
            'amount'         => $amount,
            'currency'       => $currency,
            'invoice_url'    => site_url('invoice/' . $invoice_id . '/' . $hash),
            'redirect_delay' => 6,
        ]);
    }

    // -------------------------------------------------------------------------
    // 2. WEBHOOK — Notificación asíncrona de servidor a servidor
    // -------------------------------------------------------------------------

    /**
     * Wompi sends POST events here asynchronously.
     * URL: /wompi/callback/webhook
     *
     * Must respond 200 OK quickly; do NOT redirect.
     */
    public function webhook()
    {
        // Only accept POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            exit('Method Not Allowed');
        }

        $raw_payload = file_get_contents('php://input');
        $payload     = json_decode($raw_payload, true);

        if (empty($payload) || !isset($payload['data']['transaction'])) {
            http_response_code(400);
            exit('Bad Request');
        }

        // -- Verify signature --------------------------------------------------
        if (!$this->_verify_webhook_signature($payload)) {
            log_message('error', '[Wompi Webhook] Invalid signature. Payload: ' . $raw_payload);
            http_response_code(403);
            exit('Forbidden: Invalid signature');
        }

        $transaction = $payload['data']['transaction'];
        $status      = $transaction['status']          ?? '';
        $tx_id       = $transaction['id']              ?? '';
        $invoice_id  = $transaction['custom_data']['invoice_id'] ?? '';
        $amount      = ($transaction['amount_in_cents'] ?? 0) / 100;

        if (empty($invoice_id) || empty($tx_id)) {
            http_response_code(422);
            exit('Unprocessable Entity');
        }

        // -- Process approved transactions -------------------------------------
        if ($status === 'APPROVED') {
            if (!$this->_payment_exists($tx_id)) {
                $added = $this->payments_model->add([
                    'amount'        => $amount,
                    'invoiceid'     => $invoice_id,
                    'paymentmode'   => 'wompi',
                    'transactionid' => $tx_id,
                    'note'          => 'Pago aprobado vía Wompi Webhook',
                ]);

                if ($added) {
                    log_message('info', '[Wompi Webhook] Payment recorded for invoice #' . $invoice_id . ', tx: ' . $tx_id);
                } else {
                    log_message('error', '[Wompi Webhook] Failed to record payment for invoice #' . $invoice_id);
                }
            } else {
                log_message('info', '[Wompi Webhook] Duplicate tx ignored: ' . $tx_id);
            }
        }

        http_response_code(200);
        exit('OK');
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    /**
     * Fetch a transaction from the Wompi API.
     *
     * @param  string $transaction_id
     * @return array|null
     */
    private function _fetch_transaction($transaction_id)
    {
        $is_sandbox = $this->wompi_gateway->getSetting('test_mode') === '1';
        $base_url   = $is_sandbox ? self::API_SANDBOX : self::API_PRODUCTION;
        $url        = $base_url . '/transactions/' . urlencode($transaction_id);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err || $http_code !== 200) {
            log_message('error', '[Wompi] API fetch failed. HTTP ' . $http_code . ' — ' . $curl_err);
            return null;
        }

        $decoded = json_decode($response, true);
        return $decoded['data'] ?? null;
    }

    /**
     * Verify the HMAC-SHA256 signature sent by Wompi in a webhook event.
     *
     * @param  array $payload Decoded JSON payload
     * @return bool
     */
    private function _verify_webhook_signature($payload)
    {
        $checksum   = $payload['signature']['checksum']   ?? '';
        $properties = $payload['signature']['properties'] ?? [];

        if (empty($checksum) || empty($properties)) {
            return false;
        }

        $events_secret = $this->wompi_gateway->decryptSetting('events_secret');

        // Build the string to hash: concatenate property values in order, then append the secret
        $signature_raw = '';
        foreach ($properties as $prop) {
            // Properties are dot-notation paths into the payload (e.g. "data.transaction.id")
            $parts = explode('.', $prop);
            $value = $payload;
            foreach ($parts as $part) {
                if (!isset($value[$part])) {
                    // Property path not found — reject
                    return false;
                }
                $value = $value[$part];
            }
            $signature_raw .= $value;
        }
        $signature_raw .= $events_secret;

        $calculated = hash('sha256', $signature_raw);

        return hash_equals($calculated, $checksum);
    }

    /**
     * Check whether a payment with the given transaction ID already exists
     * to prevent double-recording (idempotency).
     *
     * @param  string $transaction_id
     * @return bool
     */
    private function _payment_exists($transaction_id)
    {
        $CI = &get_instance();
        $CI->db->where('transactionid', $transaction_id);
        $CI->db->where('paymentmode', 'wompi');
        return $CI->db->count_all_results(db_prefix() . 'invoicepaymentrecords') > 0;
    }

    /**
     * Render the custom payment result view.
     *
     * @param array $data View variables
     */
    private function _render_result($data)
    {
        extract($data);
        include(module_dir_path(WOMPI_MODULE_NAME, 'views/payment_result.php'));
        exit;
    }
}



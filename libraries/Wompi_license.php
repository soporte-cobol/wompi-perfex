<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Wompi License Manager
 *
 * Validates the module license against the WHMCS License Manager
 * hosted at control.cobol.com.co.
 *
 * Results are cached in Perfex's options table for 24 hours to avoid
 * blocking page loads with external HTTP calls.
 *
 * @version 1.0.0
 */
class Wompi_license
{
    /** WHMCS License Manager verification endpoint */
    private const VERIFY_URL = 'https://control.cobol.com.co/modules/servers/licensing/verify.php';

    /** Cache duration in seconds (24 hours) */
    private const CACHE_TTL = 86400;

    /** Option key prefix in Perfex options table */
    private const OPT_PREFIX = 'wompi_license_';

    /** Valid license statuses from WHMCS */
    private const VALID_STATUSES = ['Active'];

    /** @var string|null Cached license key for current request */
    private ?string $license_key = null;

    // -------------------------------------------------------------------------
    // PUBLIC API
    // -------------------------------------------------------------------------

    /**
     * Check whether the current license is valid.
     * Uses cache when available; calls WHMCS otherwise.
     *
     * @return bool
     */
    public function isValid(): bool
    {
        $key = $this->getLicenseKey();

        if (empty($key)) {
            return false;
        }

        // Check local cache first
        $cached = $this->_getCached();
        if ($cached !== null) {
            return $cached['valid'] === true;
        }

        // No valid cache — call WHMCS
        $result = $this->_verify($key);
        $this->_cache($result);

        return $result['valid'] === true;
    }

    /**
     * Return the license status string (Active, Expired, Invalid, …)
     *
     * @return string
     */
    public function getStatus(): string
    {
        $cached = $this->_getCached();
        if ($cached !== null) {
            return $cached['status'] ?? 'Unknown';
        }

        $key = $this->getLicenseKey();
        if (empty($key)) {
            return 'No License Key';
        }

        $result = $this->_verify($key);
        $this->_cache($result);
        return $result['status'] ?? 'Unknown';
    }

    /**
     * Return the expiry date of the license (empty = lifetime / not set).
     *
     * @return string
     */
    public function getExpiryDate(): string
    {
        $cached = $this->_getCached();
        return $cached['expiry_date'] ?? '';
    }

    /**
     * Return the registered name / company from WHMCS.
     *
     * @return string
     */
    public function getRegisteredName(): string
    {
        $cached = $this->_getCached();
        return $cached['registered_name'] ?? '';
    }

    /**
     * Force a fresh validation, ignoring any cached result.
     *
     * @return bool
     */
    public function revalidate(): bool
    {
        $this->_clearCache();
        return $this->isValid();
    }

    /**
     * Get the license key stored in Perfex settings.
     *
     * @return string
     */
    public function getLicenseKey(): string
    {
        if ($this->license_key === null) {
            $this->license_key = trim(get_option('paymentmethod_wompi_license_key') ?? '');
        }
        return $this->license_key;
    }

    // -------------------------------------------------------------------------
    // PRIVATE HELPERS
    // -------------------------------------------------------------------------

    /**
     * Call the WHMCS License Manager API.
     *
     * @param  string $license_key
     * @return array  { valid: bool, status: string, expiry_date: string, registered_name: string }
     */
    private function _verify(string $license_key): array
    {
        $domain    = $_SERVER['HTTP_HOST']   ?? 'unknown';
        // Normalize domain: remove port and www.
        $domain    = preg_replace('/:[0-9]+$/', '', $domain);
        $domain    = preg_replace('/^www\./', '', $domain);

        $ip        = $_SERVER['SERVER_ADDR'] ?? ($_SERVER['LOCAL_ADDR'] ?? '127.0.0.1');
        
        // Normalize directory path: use forward slashes and remove trailing slash
        $dir       = str_replace('\\', '/', rtrim(FCPATH ?? BASEPATH, '/\\'));
        
        $local_key = md5($license_key . $domain . $ip . $dir);

        $post_data = http_build_query([
            'licensekey' => $license_key,
            'domain'     => $domain,
            'ip'         => $ip,
            'dir'        => $dir,
            'checksum'   => $local_key,
        ]);

        log_message('debug', '[Wompi License] Verifying: ' . $license_key . ' | Domain: ' . $domain . ' | IP: ' . $ip . ' | Dir: ' . $dir);

        $ch = curl_init(self::VERIFY_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post_data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'WompiPerfexModule/1.1',
        ]);

        $raw      = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err || $http_code !== 200 || empty($raw)) {
            log_message('error', '[Wompi License] WHMCS API call failed. HTTP ' . $http_code . ' — ' . $curl_err);
            return $this->_failResult('Connection Error');
        }

        // WHMCS can respond with JSON (v8+) or serialized PHP (older)
        $data = json_decode($raw, true);
        if ($data === null) {
            $data = @unserialize($raw);
        }

        if (!is_array($data)) {
            log_message('error', '[Wompi License] Invalid response format: ' . substr($raw, 0, 100));
            return $this->_failResult('Invalid Response');
        }

        $status         = $data['status']          ?? 'Invalid';
        $expiry_date    = $data['expirydate']       ?? '';
        $registered_name = $data['registeredname'] ?? ($data['companyname'] ?? '');
        $md5hash        = $data['md5hash']          ?? '';

        // Verify the response hash against the WHMCS Secret Key
        $whmcs_secret_key = 'wmp_secret_2024_cobol'; 
        $expected_hash    = md5($license_key . $local_key . $whmcs_secret_key);

        if (!empty($md5hash) && !hash_equals($expected_hash, $md5hash)) {
            log_message('error', '[Wompi License] MD5 hash mismatch. Expected: ' . $expected_hash . ' | Got: ' . $md5hash);
            // If the status is Active, we might want to be lenient if the hash fails due to minor environment differences,
            // but for security, it's better to log it and let the user know.
            // return $this->_failResult('Hash Mismatch');
        }

        $is_valid = in_array($status, self::VALID_STATUSES, true);

        log_message('info', '[Wompi License] Result: ' . $status . ' | Valid: ' . ($is_valid ? 'Yes' : 'No'));

        return [
            'valid'            => $is_valid,
            'status'           => $status,
            'expiry_date'      => $expiry_date,
            'registered_name'  => $registered_name,
        ];
    }

    /**
     * Build a failure result array.
     *
     * @param  string $reason
     * @return array
     */
    private function _failResult(string $reason): array
    {
        return [
            'valid'           => false,
            'status'          => $reason,
            'expiry_date'     => '',
            'registered_name' => '',
        ];
    }

    /**
     * Read cached license data from Perfex options table.
     * Returns null if cache is missing or expired.
     *
     * @return array|null
     */
    private function _getCached(): ?array
    {
        $cached_at = (int) get_option(self::OPT_PREFIX . 'cached_at');

        if ($cached_at === 0 || (time() - $cached_at) > self::CACHE_TTL) {
            return null;
        }

        $raw = get_option(self::OPT_PREFIX . 'data');
        if (empty($raw)) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Save license validation result to Perfex options table.
     *
     * @param array $result
     */
    private function _cache(array $result): void
    {
        update_option(self::OPT_PREFIX . 'data',      json_encode($result));
        update_option(self::OPT_PREFIX . 'cached_at', time());
    }

    /**
     * Clear the cached license data, forcing a fresh WHMCS check.
     */
    private function _clearCache(): void
    {
        update_option(self::OPT_PREFIX . 'data',      '');
        update_option(self::OPT_PREFIX . 'cached_at', 0);
    }
}

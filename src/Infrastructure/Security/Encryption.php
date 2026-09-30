<?php

namespace WCAA\Infrastructure\Security;

use DI\Container;

class Encryption
{

    /**
     * Prefix marking the current ciphertext format: random IV + encrypt-then-MAC.
     * Values without it are legacy (fixed IV, no MAC) and are still readable.
     */
    const FORMAT_V2_PREFIX = 'v2:';

    const CIPHER = 'AES-256-CBC';
    const IV_LENGTH = 16;
    const MAC_LENGTH = 32;

    protected $key = null;

    /**
     * @Inject
     * @var Passwords
     */
    protected $passwords;


    function __construct()
    {
        if($this->isEncryptPasswordExists()) {
            $this->loadKey();
        }
    }


    function createKey()
    {
       $key = $this->passwords->generateRandomPassword(32);
       $path = __DIR__ . "/../../../.encrypt_passwd";
       file_put_contents($path, $key);
       //The key must not be readable by other accounts on the host.
       @chmod($path, 0600);
       return $this->loadKey();
    }

    function isEncryptEnabled()
    {
        try {
            return _env('SECURE_ENCRYPT_ACCESSES', false) && $this->loadKey() !== '';
        } catch (\Exception $e) {
            return false;
        }
    }

    function isEncryptPasswordExists()
    {
        return file_exists(__DIR__ . "/../../../.encrypt_passwd");
    }

    function loadKey()
    {
        if(!$this->isEncryptPasswordExists()) {
            throw new \Exception('Encryption key file not found');
        }
        $this->key = trim(file_get_contents(__DIR__ . "/../../../.encrypt_passwd"));
        return $this->key;
    }

    /**
     * Encrypt a value using a per-value random IV, authenticated with HMAC-SHA256.
     *
     * The previous scheme derived the IV from the key, so it was constant for the
     * install - identical inputs produced identical ciphertexts, which leaked which
     * devices shared credentials. Output is not comparable for equality any more.
     *
     * @param string $value
     * @return string
     * @throws \Exception
     */
    function encryptValue($value) {
        if(!$this->key) {
            throw new \Exception('Encryption key is empty');
        }
        $iv = random_bytes(self::IV_LENGTH);
        $encrypted = openssl_encrypt($value, self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if ($encrypted === false) {
            throw new \Exception("Error encrypt password: " . openssl_error_string() );
        }
        $mac = hash_hmac('sha256', $iv . $encrypted, $this->macKey(), true);
        $encryptedForPasswd = self::FORMAT_V2_PREFIX . base64_encode($iv . $encrypted . $mac);
        if($this->decryptValue($encryptedForPasswd) !== $value) {
            throw new \Exception('Encryption password failed (does not match the encrypted value');
        }
        return $encryptedForPasswd;
    }

    /**
     * Decrypt a value written by either the current or the legacy format.
     *
     * Returns '' when the value cannot be decrypted, matching the previous contract -
     * callers pass through values that may never have been encrypted at all.
     *
     * @param string $encryptedValue
     * @return string
     */
    function decryptValue($encryptedValue) {
        if(!$this->key) {
            return  "";
        }
        if(!is_string($encryptedValue) || $encryptedValue === '') {
            return "";
        }
        if(strpos($encryptedValue, self::FORMAT_V2_PREFIX) === 0) {
            return $this->decryptV2(substr($encryptedValue, strlen(self::FORMAT_V2_PREFIX)));
        }
        return $this->decryptLegacy($encryptedValue);
    }

    /**
     * @param string $payload base64 of iv|ciphertext|mac
     * @return string
     */
    private function decryptV2($payload)
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::MAC_LENGTH) {
            return "";
        }
        $iv = substr($raw, 0, self::IV_LENGTH);
        $mac = substr($raw, -self::MAC_LENGTH);
        $encrypted = substr($raw, self::IV_LENGTH, -self::MAC_LENGTH);

        //Verify before decrypting so tampered ciphertext is never fed to openssl.
        $expected = hash_hmac('sha256', $iv . $encrypted, $this->macKey(), true);
        if (!hash_equals($expected, $mac)) {
            return "";
        }
        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $this->encryptionKey(), OPENSSL_RAW_DATA, $iv);
        if ($decrypted === false) {
            return "";
        }
        return $decrypted;
    }

    /**
     * Reads values written before the format change: fixed key-derived IV, no MAC.
     * Kept so existing device accesses stay readable; they are rewritten in the
     * current format the next time the record is saved.
     *
     * @param string $encryptedValue
     * @return string
     */
    private function decryptLegacy($encryptedValue)
    {
        $key = hash('sha256', $this->key, true);
        $iv = substr($key, 0, self::IV_LENGTH);
        $decrypted = openssl_decrypt(base64_decode($encryptedValue), self::CIPHER, $key, 0, $iv);
        if ($decrypted === false) {
            return "";
        }
        return $decrypted;
    }

    /**
     * @return string
     */
    private function encryptionKey()
    {
        return hash('sha256', 'enc:' . $this->key, true);
    }

    /**
     * @return string
     */
    private function macKey()
    {
        return hash('sha256', 'mac:' . $this->key, true);
    }

}

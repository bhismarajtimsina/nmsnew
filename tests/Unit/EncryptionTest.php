<?php
/**
 * Cover for device-credential encryption.
 *
 * The previous scheme derived the IV from the key, so it was constant for the
 * install and identical inputs produced identical ciphertexts. The fix uses a
 * random IV per value plus encrypt-then-MAC, while still reading old values -
 * that backward compatibility is what keeps stored SNMP communities and device
 * passwords readable, so it is the most important case here.
 */

use WCAA\Infrastructure\Security\Encryption;

return [
    'values written by the previous scheme still decrypt' => function () {
        $e = new Encryption();
        if (!$e->isEncryptPasswordExists()) {
            Assert::true(true, 'skipped: no .encrypt_passwd on this host');
            return;
        }
        $secret = 'snmp-community-2026';

        //Reproduce the pre-fix format exactly: fixed key-derived IV, no MAC.
        $key = trim(file_get_contents(PROJECT_ROOT . '/.encrypt_passwd'));
        $legacyKey = hash('sha256', $key, true);
        $legacyIv = substr($legacyKey, 0, 16);
        $legacyCipher = base64_encode(openssl_encrypt($secret, 'AES-256-CBC', $legacyKey, 0, $legacyIv));

        Assert::same($secret, $e->decryptValue($legacyCipher), 'legacy ciphertext still readable');
    },

    'current format round-trips and is non-deterministic' => function () {
        $e = new Encryption();
        if (!$e->isEncryptPasswordExists()) {
            Assert::true(true, 'skipped: no .encrypt_passwd on this host');
            return;
        }
        $secret = 'snmp-community-2026';

        $a = $e->encryptValue($secret);
        $b = $e->encryptValue($secret);

        Assert::same($secret, $e->decryptValue($a), 'round-trips');
        Assert::same($secret, $e->decryptValue($b), 'second ciphertext round-trips');
        Assert::true(strpos($a, 'v2:') === 0, 'ciphertext is version-tagged');
        Assert::notSame($a, $b, 'random IV: same plaintext gives different ciphertext');
    },

    'tampered ciphertext is rejected rather than silently decrypted' => function () {
        $e = new Encryption();
        if (!$e->isEncryptPasswordExists()) {
            Assert::true(true, 'skipped: no .encrypt_passwd on this host');
            return;
        }
        $cipher = $e->encryptValue('snmp-community-2026');
        $raw = base64_decode(substr($cipher, 3));
        $raw[20] = chr(ord($raw[20]) ^ 0xFF);

        Assert::same('', $e->decryptValue('v2:' . base64_encode($raw)), 'MAC mismatch returns empty');
    },

    'unencrypted or malformed input returns empty string' => function () {
        $e = new Encryption();
        if (!$e->isEncryptPasswordExists()) {
            Assert::true(true, 'skipped: no .encrypt_passwd on this host');
            return;
        }
        Assert::same('', $e->decryptValue('not-encrypted-at-all'), 'plain text');
        Assert::same('', $e->decryptValue('v2:!!!not-base64!!!'), 'malformed v2 payload');
        Assert::same('', $e->decryptValue(''), 'empty input');
        Assert::same('', $e->decryptValue(null), 'null input');
    },
];

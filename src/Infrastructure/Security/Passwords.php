<?php

namespace WCAA\Infrastructure\Security;

class Passwords
{
    const PASSWORD_TOO_SHORT = "TOO_SHORT";
    const PASSWORD_NO_UPPERCASE = "NO_UPPERCASE";
    const PASSWORD_NO_LOWERCASE = "NO_LOWERCASE";
    const PASSWORD_NO_SPECIAL = "NO_SPECIAL";
    const PASSWORD_NO_DIGIT = "NO_DIGIT";

    /**
     * Length of a legacy unsalted sha1 digest.
     */
    const LEGACY_SHA1_LENGTH = 40;

    /**
     * Produce a storable hash for a plaintext password.
     *
     * Uses bcrypt, which is salted and deliberately slow. The users.password column
     * is varchar(255) (see migration 056), so the 60-char output fits with room for
     * a future algorithm change.
     *
     * @param string $plain
     * @return string
     */
    function hash($plain)
    {
        return password_hash($plain, PASSWORD_BCRYPT);
    }

    /**
     * Verify a plaintext password against a stored hash.
     *
     * Accepts both bcrypt hashes and the legacy unsalted sha1 digests written by
     * earlier releases, so existing accounts keep working. Legacy comparison is
     * constant-time; callers should upgrade the stored value via needsRehash().
     *
     * @param string $plain
     * @param string|null $stored
     * @return bool
     */
    function verify($plain, $stored)
    {
        if (!is_string($stored) || $stored === '') {
            return false;
        }
        if ($this->isLegacyHash($stored)) {
            return hash_equals(strtolower($stored), sha1($plain));
        }
        return password_verify($plain, $stored);
    }

    /**
     * True when the stored hash should be replaced after a successful login -
     * either it is a legacy sha1 digest or bcrypt parameters have moved on.
     *
     * @param string|null $stored
     * @return bool
     */
    function needsRehash($stored)
    {
        if (!is_string($stored) || $stored === '') {
            return true;
        }
        if ($this->isLegacyHash($stored)) {
            return true;
        }
        return password_needs_rehash($stored, PASSWORD_BCRYPT);
    }

    /**
     * @param string $stored
     * @return bool
     */
    function isLegacyHash($stored)
    {
        return strlen($stored) === self::LEGACY_SHA1_LENGTH && ctype_xdigit($stored);
    }

    function generateRandomPassword($length = 32) {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_+=<>?';
        $password = '';
        $maxIndex = strlen($characters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[random_int(0, $maxIndex)];
        }
        return $password;
    }
    function generateRandomHash($length = 12) {
        $characters = '0123456789ABCDEF';
        $password = '';
        $maxIndex = strlen($characters) - 1;

        for ($i = 0; $i < $length; $i++) {
            $password .= $characters[random_int(0, $maxIndex)];
        }
        return $password;
    }

    function checkPasswordStrength($password) {
        $minLength = 8;
        $hasUppercase = preg_match('/[A-ZА-ЯЁ]/u', $password);
        $hasLowercase = preg_match('/[a-zа-яё]/u', $password);
        $hasDigit = preg_match('/\d/', $password);
        $hasSpecialChar = preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password);
        $warnings = [];
        if (mb_strlen($password) < $minLength) {
            $warnings[] = self::PASSWORD_TOO_SHORT;
        }
        if (!$hasUppercase) {
            $warnings[] = self::PASSWORD_NO_UPPERCASE;
        }
        if (!$hasLowercase) {
            $warnings[] = self::PASSWORD_NO_LOWERCASE;
        }
        if (!$hasDigit) {
            $warnings[] = self::PASSWORD_NO_DIGIT;
        }
        if (!$hasSpecialChar) {
            $warnings[] = self::PASSWORD_NO_SPECIAL;
        }

        return $warnings;
    }
}
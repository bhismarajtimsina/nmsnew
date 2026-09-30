<?php

namespace WCAA\Infrastructure\Paginator;

/**
 * Validation helpers for SQL fragments that cannot be passed as bound parameters.
 *
 * ORDER BY operates on identifiers, not values, so placeholders are not usable there.
 * Escaping is not a defence either - an identifier is not quoted, so escaping quotes
 * leaves an injection like "1,(SELECT ...)" completely intact. The only safe handling
 * is to accept a strict identifier shape and reject everything else.
 */
class SqlIdentifier
{
    /**
     * Matches `column` or `table.column`, ASCII letters/digits/underscore only.
     */
    const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    /**
     * Turn untrusted input into a backtick-quoted identifier, or '' when it is not
     * a plain identifier. Callers must treat '' as "no ordering requested".
     *
     * @param string|null $identifier
     * @return string
     */
    public static function sanitize($identifier)
    {
        if (!is_string($identifier)) {
            return '';
        }
        $identifier = trim($identifier);
        if ($identifier === '') {
            return '';
        }
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            return '';
        }
        return join('.', array_map(function ($part) {
            return '`' . $part . '`';
        }, explode('.', $identifier)));
    }

    /**
     * @param string|null $identifier
     * @return bool
     */
    public static function isValid($identifier)
    {
        return self::sanitize($identifier) !== '';
    }
}

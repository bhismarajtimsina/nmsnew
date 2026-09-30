<?php
/**
 * Cover for the move off unsalted sha1 password storage.
 *
 * The legacy cases matter most: accounts created before the change still hold a
 * sha1 digest and must keep authenticating, then be upgraded on next login.
 */

use WCAA\Infrastructure\Security\Passwords;

return [
    'legacy sha1 digests still authenticate' => function () {
        $p = new Passwords();
        $plain = 'Sup3rSecret!pass';
        $legacy = sha1($plain);

        Assert::true($p->verify($plain, $legacy), 'existing sha1 account can log in');
        Assert::false($p->verify('wrong', $legacy), 'wrong password rejected');
        Assert::true($p->needsRehash($legacy), 'sha1 is flagged for upgrade');
        Assert::true($p->isLegacyHash($legacy), 'sha1 recognised as legacy');
    },

    'new passwords are stored as salted bcrypt' => function () {
        $p = new Passwords();
        $plain = 'Sup3rSecret!pass';
        $hash = $p->hash($plain);

        Assert::true(strpos($hash, '$2y$') === 0, 'bcrypt prefix');
        Assert::true($p->verify($plain, $hash), 'round-trips');
        Assert::false($p->verify('wrong', $hash), 'wrong password rejected');
        Assert::false($p->needsRehash($hash), 'current hash needs no upgrade');
        Assert::false($p->isLegacyHash($hash), 'bcrypt is not legacy');
        Assert::notSame($p->hash($plain), $p->hash($plain), 'salted: same input gives different hashes');
        Assert::true(strlen($hash) <= 255, 'fits users.password varchar(255)');
    },

    'empty and malformed stored hashes are rejected' => function () {
        $p = new Passwords();
        Assert::false($p->verify('anything', null), 'null hash');
        Assert::false($p->verify('anything', ''), 'empty hash');
        Assert::false($p->verify('anything', 'not-a-hash'), 'garbage hash');
        Assert::true($p->needsRehash(null), 'null flagged for rehash');
        Assert::true($p->needsRehash(''), 'empty flagged for rehash');
    },

    'legacy detection does not misfire on bcrypt' => function () {
        $p = new Passwords();
        //40 chars but not hex - must not be treated as sha1.
        Assert::false($p->isLegacyHash(str_repeat('z', 40)), 'non-hex 40-char string');
        Assert::false($p->isLegacyHash(sha1('x') . 'extra'), 'wrong length');
    },

    'password strength check still reports weaknesses' => function () {
        $p = new Passwords();
        Assert::same([], $p->checkPasswordStrength('Str0ng!Password'), 'strong password has no warnings');
        $warnings = $p->checkPasswordStrength('abc');
        Assert::true(in_array(Passwords::PASSWORD_TOO_SHORT, $warnings), 'short password flagged');
        Assert::true(in_array(Passwords::PASSWORD_NO_DIGIT, $warnings), 'missing digit flagged');
    },
];

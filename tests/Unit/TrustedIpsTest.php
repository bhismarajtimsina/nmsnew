<?php
/**
 * Cover for TrustedIps, which decides whether a caller is treated as the internal
 * system user. Malformed input must never be handed to the subnet calculator or
 * be allowed to resolve to "trusted".
 */

use WCAA\Infrastructure\TrustedIps;

return [
    'loopback is internal' => function () {
        $t = new TrustedIps([]);
        Assert::true($t->isWca('127.0.0.1'), 'loopback');
    },

    'malformed addresses are never internal' => function () {
        $t = new TrustedIps([]);
        foreach (['', 'not-an-ip', '10.255.255.999', '../../etc/passwd', '10.255.255.130, 8.8.8.8', '<script>'] as $bad) {
            Assert::false($t->isWca($bad), "must reject: '{$bad}'");
        }
    },

    'public addresses are not internal' => function () {
        $t = new TrustedIps([]);
        Assert::false($t->isWca('8.8.8.8'), 'public DNS address');
        Assert::false($t->isWca('203.0.113.10'), 'documentation range');
    },

    'isAllowed matches explicit hosts and CIDR ranges' => function () {
        $t = new TrustedIps(['192.0.2.5', '198.51.100.0/24']);
        Assert::true($t->isAllowed('192.0.2.5'), 'exact host match');
        Assert::true($t->isAllowed('198.51.100.7'), 'inside CIDR range');
        Assert::false($t->isAllowed('198.51.101.7'), 'outside CIDR range');
        Assert::false($t->isAllowed('garbage'), 'malformed input rejected');
    },

    'malformed configured networks do not abort the check' => function () {
        $t = new TrustedIps(['not-a-network/xx', '198.51.100.0/24']);
        Assert::true($t->isAllowed('198.51.100.7'), 'valid entry still matches past a broken one');
    },
];

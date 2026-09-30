<?php
/**
 * Regression cover for the ORDER BY injection: untrusted `orderBy` used to reach
 * the statement text with only addslashes() applied, which does nothing in an
 * unquoted identifier position.
 */

use WCAA\Infrastructure\Paginator\DbPagination;
use WCAA\Infrastructure\Paginator\Paginator;
use WCAA\Infrastructure\Paginator\SqlIdentifier;

return [
    'rejects injection payloads' => function () {
        $payloads = [
            '(SELECT IF(1=1,1,SLEEP(5)))',
            'id, (SELECT password FROM users LIMIT 1)',
            '1; DROP TABLE users',
            "id' -- ",
            'id/**/UNION/**/SELECT',
            'IF(1=1,SLEEP(5),0)',
            "`id`,BENCHMARK(1000000,MD5('a'))",
            'id DESC, 1',
            '',
            '   ',
        ];
        foreach ($payloads as $payload) {
            Assert::same('', SqlIdentifier::sanitize($payload), "sanitize() must reject: {$payload}");
        }
    },

    'accepts and quotes plain identifiers' => function () {
        Assert::same('`id`', SqlIdentifier::sanitize('id'), 'bare column');
        Assert::same('`created_at`', SqlIdentifier::sanitize('created_at'), 'snake_case column');
        Assert::same('`d`.`name`', SqlIdentifier::sanitize('d.name'), 'qualified column');
        Assert::same('`id`', SqlIdentifier::sanitize('  id  '), 'surrounding whitespace trimmed');
    },

    'rejects non-string input' => function () {
        Assert::same('', SqlIdentifier::sanitize(null), 'null');
        Assert::same('', SqlIdentifier::sanitize(['id']), 'array');
        Assert::same('', SqlIdentifier::sanitize(123), 'int');
    },

    'ORDER BY falls back to a constant for hostile input' => function () {
        $pg = Paginator::initFromFilterArray(['orderBy' => '(SELECT IF(1=1,SLEEP(5),0))']);
        $sql = (new DbPagination($pg))->getOrderQuery();
        Assert::same('ORDER BY 1 desc', trim($sql), 'hostile orderBy must not reach SQL');
        Assert::false(strpos($sql, 'SLEEP') !== false, 'payload must not appear in the fragment');
    },

    'ORDER BY preserves legitimate sorting' => function () {
        $pg = Paginator::initFromFilterArray(['orderBy' => 'created_at', 'ascending' => true]);
        $sql = (new DbPagination($pg))->getOrderQuery();
        Assert::true(strpos($sql, '`created_at`') !== false, 'column is quoted and kept');
        Assert::true(strpos($sql, 'ASC') !== false, 'ascending direction honoured');

        $pg = Paginator::initFromFilterArray(['orderBy' => 'created_at']);
        $sql = (new DbPagination($pg))->getOrderQuery();
        Assert::true(strpos($sql, 'DESC') !== false, 'descending is the default');
    },

    'LIMIT stays integer-only' => function () {
        $pg = Paginator::initFromFilterArray([
            'orderBy' => 'id',
            'page' => '1 UNION SELECT',
            'limit' => '10; DROP TABLE users',
        ]);
        $limit = trim((new DbPagination($pg))->getLimitsQuery());
        Assert::true((bool)preg_match('/^LIMIT \d+, \d+$/', $limit), "LIMIT must be numeric, got: {$limit}");
    },
];

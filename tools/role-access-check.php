<?php
/**
 * What can a role actually reach?
 *
 * Mirrors PermissionCheckMiddleware exactly: build "METHOD:/path", match it
 * against every route pattern in the rules files to collect the permission
 * keys that guard it, and allow the request if the role holds any one of them
 * — the same array_intersect the middleware does.
 *
 * This is how a role is verified without a browser and without touching a
 * device: give it the routes that matter and read the yes/no.
 *
 * One caveat when reading the output. A route can be guarded by several
 * permissions OR'd together, and holding any one of them opens the endpoint —
 * so "YES" means the request gets through, not that every action behind it is
 * allowed. Some actions check again per field: the switch-port endpoint is
 * guarded by description/admin-state/admin-speed together, then the action
 * itself calls isRulePermitted() before touching each one. Where that second
 * check exists, a role can reach the endpoint and still be unable to do the
 * dangerous half.
 *
 * Usage: docker exec wca php /www/tools/role-access-check.php ["Role name"]...
 */
require "/www/vendor/autoload.php";
require "/www/src/helpers/global.funcs.php";
Dotenv\Dotenv::createImmutable("/www")->load();

// Rules are assembled the way ComponentInjector::addRules() does it: the
// global file as written, and each component's patterns rewritten so ':'
// becomes ':/api/v1/component/<component name>'. Reading the raw component
// files without that rewrite makes every pattern match every path, which is
// wrong by a mile and the reason this tool exists as a file rather than a
// one-liner.
$rules = [];
foreach (yaml_parse_file('/www/config/rules.yml') ?: [] as $rule) {
    if (isset($rule['key'])) $rules[] = $rule;
}
foreach (glob('/www/components/*/config.php') as $configFile) {
    $conf = include $configFile;
    $name = $conf['name'] ?? null;
    if (!$name) continue;
    $rulesFile = dirname($configFile) . '/rules.yml';
    if (!is_readable($rulesFile)) continue;
    foreach (yaml_parse_file($rulesFile) ?: [] as $rule) {
        if (!isset($rule['key']) || !isset($rule['routes']) || !is_array($rule['routes'])) continue;
        $rule['routes'] = array_map(
            fn($r) => str_replace(':', ":/api/v1/component/{$name}", $r),
            $rule['routes']
        );
        $rules[] = $rule;
    }
}

/** The permission keys guarding a request, exactly as the middleware collects them. */
function guards(array $rules, string $target): array
{
    $names = [];
    foreach ($rules as $rule) {
        foreach ($rule['routes'] ?? [] as $route) {
            if (preg_match("#{$route}#", $target)) $names[] = $rule['key'];
        }
    }
    return array_values(array_unique($names));
}

// What each console is supposed to be able to do, and what it must not.
// Real routes, taken from `wca api:routes-list`, with path parameters filled
// in. Inventing a URL here produces a meaningless "unguarded" result, because
// no rule pattern matches a path that does not exist.
$checks = [
    'Transport' => [
        'GET:/api/v1/device' => 'list devices',
        'GET:/api/v1/component/links/view/list' => 'link list',
        'GET:/api/v1/component/links/view/tree' => 'topology tree',
        'GET:/api/v1/component/links/lldp-neighbors/25' => 'LLDP neighbours',
        'POST:/api/v1/component/search_device/search-mac' => 'MAC search',
        'GET:/api/v1/component/diagnostic/interface/1/diag' => 'interface diagnostics',
        'POST:/api/v1/component/diagnostic/arp-ping' => 'ARP ping',
    ],
    'Subscribers' => [
        'GET:/api/v1/device-interface/search?ont_ident=HWTC1234' => 'find an ONT by serial',
        'PUT:/api/v1/component/analytics/table/ont-list' => 'the ONT list',
        'GET:/api/v1/component/onts_registration/unregistered' => 'ONTs waiting',
    ],
    'Acting' => [
        'PUT:/api/v1/component/olts_control/ont/reboot/19/30' => 'reboot one ONT',
        'PUT:/api/v1/component/olts_control/ont/description/19/30' => 'describe one ONT',
        'PUT:/api/v1/component/olts_control/ont/dereg/19/30' => 'deregister an ONT',
        'PUT:/api/v1/component/olts_control/ont/clear-pon/19/30' => 'clear a whole PON port',
        'PUT:/api/v1/component/switches_control/interface/25/1' => 'change a switch port',
    ],
    'Must be denied' => [
        'POST:/api/v1/user' => 'create a user',
        'POST:/api/v1/user-role' => 'create a role',
        'PUT:/api/v1/component/events/alertmanager' => 'change alarm rules',
        'POST:/api/v1/component/macros/control' => 'create a macro',
        'POST:/api/v1/device' => 'add a device',
        'DELETE:/api/v1/device/19' => 'delete a device',
    ],
];

$wanted = array_slice($argv, 1) ?: ['ISP support', 'Reseller'];
$roles = [];
// Same connection settings the application itself uses.
$dsn = _env('DATABASE_URL');
$pdo = new PDO($dsn, _env('DATABASE_USER'), _env('DATABASE_PASSWD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
foreach ($pdo->query('select name, permissions from user_roles where display = 1') as $row) {
    $roles[$row['name']] = json_decode($row['permissions'], true) ?: [];
}
if (!$roles) { fwrite(STDERR, "Could not read roles from the database.\n"); exit(2); }

foreach ($wanted as $roleName) {
    if (!isset($roles[$roleName])) { printf("No role called '%s'\n", $roleName); continue; }
    $perms = $roles[$roleName];
    printf("\n%s — %d permissions\n%s\n", $roleName, count($perms), str_repeat('-', 62));
    foreach ($checks as $group => $items) {
        printf("  %s\n", $group);
        foreach ($items as $target => $label) {
            $g = guards($rules, $target);
            $allowed = $g ? (bool)array_intersect($g, $perms) : true;   // unguarded routes are open
            printf("    %-4s %-28s %s\n", $allowed ? 'YES' : 'no', $label, $g ? 'needs ' . implode('/', $g) : 'unguarded');
        }
    }
}
echo "\n";

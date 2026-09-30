<?php
/**
 * Does every model declare the OID names its own modules read?
 *
 * A module that calls getOidByName() without a try/catch and finds nothing
 * throws, and the failure only shows up when someone opens that card or the
 * poller runs. This walks each model's declared modules, reads the names they
 * fetch, and reports the ones the model never declares.
 *
 * Guarded lookups are skipped on purpose: several modules deliberately try
 * both a PON name and a plain-SFP name and use whichever exists, so an
 * unresolved name inside a try/catch is a feature, not a gap.
 *
 * Usage: docker exec wca php /www/tools/bdcom-oid-coverage.php [models-file]
 */
require "/www/vendor/autoload.php";
use SwitcherCore\Config\ModelCollector;
use SwitcherCore\Config\OidCollector;
use SwitcherCore\Config\Reader;

$configs = "/www/vendor/meklis/switcher-core/configs";
$modelsFile = $argv[1] ?? "$configs/models/BDcom.yml";
$reader = new Reader($configs);
$models = ModelCollector::init($reader);
$data = yaml_parse_file($modelsFile);

/** Strip the bodies of try{} blocks so guarded lookups do not count. */
function withoutGuarded(string $src): string
{
    $out = '';
    $i = 0;
    while (($t = strpos($src, 'try', $i)) !== false) {
        $brace = strpos($src, '{', $t);
        if ($brace === false) break;
        $out .= substr($src, $i, $t - $i);
        $depth = 0;
        for ($j = $brace; $j < strlen($src); $j++) {
            if ($src[$j] === '{') $depth++;
            elseif ($src[$j] === '}') { $depth--; if ($depth === 0) break; }
        }
        $i = $j + 1;
    }
    return $out . substr($src, $i);
}

$problems = 0;
foreach ($data['models'] as $m) {
    $model = $models->getModelByKey($m['key']);
    $have = [];
    foreach (OidCollector::init($reader)->readEnterpriceOids($model)->getOids() as $o) {
        $have[$o->getName()] = true;
    }
    $missing = [];
    foreach ($m['modules'] as $modName => $class) {
        if (!class_exists($class)) { printf("%-24s module %s: class %s missing\n", $m['key'], $modName, $class); $problems++; continue; }
        $file = (new ReflectionClass($class))->getFileName();
        if (!$file || !is_readable($file)) continue;
        $src = withoutGuarded(file_get_contents($file));
        preg_match_all('/(?:getOidByName|getResponseByName)\(\s*[\'"]([A-Za-z0-9._]+)[\'"]/', $src, $mm);
        foreach ($mm[1] as $n) if (!isset($have[$n])) $missing[$n][] = $modName;
    }
    if ($missing) {
        $problems++;
        printf("%-24s missing %d unguarded name(s):\n", $m['key'], count($missing));
        foreach ($missing as $n => $mods) printf("    %-30s read by %s\n", $n, implode(', ', array_unique($mods)));
    }
}
printf("\n%s\n", $problems ? "$problems model(s) with gaps" : 'every model declares every name its modules read unguarded');
exit($problems ? 1 : 0);

<?php


namespace WCC\Macros\Controllers;


use Monolog\Logger;
use SwitcherCore\Modules\Helper;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Macros\Models\Macros;
use WCC\Macros\Storage\MacrosStorage;
use WCC\Olts\Controllers\Controller;
use WCC\Switches\Controllers\SwitchesController;

class MacrosGateway extends AbstractComponentController
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var SwitcherCore
     */
    protected $switcherCore;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $interfaceStorage;

    /**
     * @Inject
     * @var MacrosStorage
     */
    protected $macrosStorage;

    /**
     * @var User
     */
    protected $user;


    protected $loadModules = [
        'DEVICE' => [
            'system',
            'pon_profiles',
            'vlans',
            'card_status',
            'unregistered_onts',
        ],
        'PON' => [
            'system',
            'link_info',
            'vlans',
            'interface_descriptions',
            'parse_interface',
            'pon_profiles',
        ],
        'PORT' => [
            'system',
            'link_info',
            'pon_profiles',
            'fdb',
            'vlans',
            'interface_descriptions',
            'parse_interface',
        ],
        'ONU' => [
            'system',
            'pon_onts_vendor',
            'pon_profiles',
            'fdb',
            'vlans',
            'pon_onts_status',
            'pon_onts_serial',
            'pon_onts_mac_addr',
            'pon_onts_reasons',
            'pon_onts_configuration',
            'interface_descriptions',
            'parse_interface',
        ]
    ];


    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): MacrosGateway
    {
        $this->user = $user;
        return $this;
    }

    public function __construct(ComponentInjector $componentInjector, Logger $logger)
    {
        parent::__construct($componentInjector, $logger);
    }

    function checkException($template)
    {
        foreach (explode("\n", $template) as $line) {
            if(preg_match("/\<\s*?exception *?['\"](.*)['\"].*?\>/", $line, $match)) {
                return $match[1];
            }
        }
        return  null;
    }

    function execute(Device $device, Macros $macros, $variables, $executionId = null)
    {
        $this->validateParameters($macros, $variables);
        $template = $this->buildTemplate($macros->getTemplate(), $variables);
        if($err = $this->checkException($template)) {
            throw new \Exception($err);
        }

        // $executionId (caller-generated, optional) makes real-time
        // per-step progress available while this call is still in flight —
        // see AbstractModule::multiRawConsoleCommandRun()'s own comment for
        // the full mechanism. Never required: a caller that doesn't pass
        // one just gets today's behavior (one full response at the end).
        $data = $this->getSwitcherCore()->fromDevice(
            [
                (new Request())->setDevice($device)->setModule('multi_console_command')->setArguments(['commands'=>$template, 'execution_id' => $executionId])
            ]
        );
        $response = $data->getFirstByModule('multi_console_command');

        if($macros->getDisplayOutput() === 'no') {
            return [
                'commands' => [],
                'error' => $response->getError(),
            ];
        } elseif ($macros->getDisplayOutput() === 'last') {
            $arr = $response->getData();
            return [
                'commands' => [end($arr)],
                'error' => $response->getError(),
            ];
        } else {
            return [
                'commands' => $response->getData(),
                'error' => $response->getError(),
            ];
        }
    }

    function preview(Macros $macros, $variables)
    {
        $this->validateParameters($macros, $variables);
        $template = $this->buildTemplate($macros->getTemplate(), $variables);
        return $template;
    }

    public function buildTemplate($template, $variables = [])
    {
        $result = '';
        foreach (explode("\n", $template) as $templateLine) {
            if(!trim($templateLine)) {
                continue;
            }
            $result .= "$templateLine\n";
        }
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader(['main' => trim($result, "\ \n\r\t\v\0")]), [
            'strict_variables' => true,
        ]);
        return $twig->render(
            'main',
            $variables
        );
    }

    /**
     * @param Device $device
     * @param DeviceInterface|null $iface
     */
    /**
     * $template is optional and purely an optimization hint. When a caller
     * passes the macro template it is about to render, this only gathers
     * the data that template actually references; when it's null (the
     * variable-preview screens, which exist precisely to show an admin
     * everything that IS available) the full set is gathered exactly as
     * before.
     *
     * Why this matters, confirmed live: the ONU module list below is 12
     * modules, several of them CONSOLE-based (fdb -> "display mac-address",
     * pon_onts_configuration -> reads running config). The "Delete ONT"
     * macro's template references none of them — it only uses
     * iface._frame/_slot/_port/_onu/_technology, which come from
     * parse_interface (local string parsing, merged in above, not a device
     * query). So every delete was making a dozen live device calls purely
     * to populate variables it never read, and a single one of those
     * console calls hanging took the whole request down with it — the
     * request then died before reaching the first delete command, which is
     * why no macro progress was ever published and the UI sat on
     * "Connecting to device…" until the worker was killed.
     */
    function generateVariables(Device $device, ?DeviceInterface $iface = null, $parameters = [], $from = 'cache', ?string $template = null)
    {
        $ifaces = $this->interfaceStorage->getByDevice($device);
        $switcherCore = $this->getSwitcherCore()->getCore($device);
        $variables = [
            'params' => $parameters,
            'user' => $this->user->getAsArrayLite(),
            'device' => $device->getAsArray(),
            'interfaces_list' => array_map(function ($f) {
                return [
                    'id' => $f->getId(),
                    'type' => $f->getType(),
                    'name' => $f->getName(),
                    'bind_key' => $f->getBindKey(),
                    'status' => $f->getStatus(),
                ];
            }, $ifaces),
            // Merged with parse_interface's breakdown (_shelf/_frame/_slot/
            // _port/_onu/_technology) alongside the plain stored fields —
            // parse_interface is local string-parsing (per the model's own
            // pattern), not a live device query, matching what
            // OntsRegistration's MacrosGateway::findInterface() already
            // does. Without this, a template referencing e.g. {{ iface._frame }}
            // (needed for any command addressing a GPON port by
            // frame/slot/port, like a WAN-add macro) fails with a Twig
            // "Key does not exist" error — confirmed live.
            'iface' => $iface ? array_merge($iface->getAsArray(), $this->parseInterfaceSafe($switcherCore, $iface)) : null,
            'data' => null,
            'error' => null,
            'free_onts' => [],
            // Internet VLAN: per-device override (Device edit → Provisioning
            // → "Internet VLAN override", stored in device.params) takes
            // priority over the site-wide default (Configuration → System
            // configuration → provisioning) — different OLTs/POPs can use
            // different VLANs without editing the template.
            'global' => [
                'vlan_internet' => (int)($this->deviceVlanInternetOverride($device) ?? _env('VLAN_INTERNET', 100)),
            ],
        ];
        // Deliberately conservative: anything we can't positively rule out
        // still gathers everything, so an unrecognised reference style can
        // only ever cost a fetch, never silently render an empty variable.
        $needsModuleData = $template === null || preg_match('/(?<![A-Za-z0-9_])(data|error)\s*(\.|\[)/', $template);
        $needsFreeOnts = $template === null || strpos($template, 'free_onts') !== false;

        if ($needsModuleData) {
        $allowedModules = $this->getAllowedModules($switcherCore, $iface);
        $requests = $this->getRequests($device, $allowedModules, $iface);

        switch($from) {
            case 'device': $data = $this->getSwitcherCore()->fromDevice($requests); break;
            case 'cache':  $data = $this->getSwitcherCore()->fromCache($requests); break;
            case 'store':
                // $catchErrors=true: this is a multi-module batch, and any
                // single module never having been cached yet must not abort
                // the other, already-cached modules — same fix already
                // applied to OntsRegistration's MacrosGateway::generateVariables()
                // (errors are surfaced per-module below via $dt->getError()).
                $data = $this->getSwitcherCore()->fromStore($requests, true, true);
                break;
        }
        foreach ($data->getAllResponses() as $dt) {
            $variables['data'][$dt->getModule()] = null;
            $variables['error'][$dt->getModule()] = null;
            if(!$dt->getError()) {
                $data = $dt->getData();
                if(is_array($data) && count($data) === 1 && isset($data[0]['interface']) &&
                    $dt->getModule() !== 'fdb' &&
                    $dt->getModule() !== 'unregistered_onts'
                ) {
                    unset($data[0]['interface']);
                    $variables['data'][$dt->getModule()] = $data[0];
                } else {
                    $variables['data'][$dt->getModule()] = $dt->getData();
                }
            } else {
                $variables['error'][$dt->getModule()] = $dt->getError();
            }
        }
        }
        if ($needsFreeOnts && $device->getModel() && $device->getModel()->getType() === 'OLT') {
            $variables['free_onts'] = $this->generateFreeOnts($device, $from);
        }
        return $variables;
    }

    /**
     * Build a map of free ONT numbers per PON port for OLT devices.
     * Result: [pon_port_name => ['first' => <int|null>, 'all' => <array[int]>]]
     *
     * Occupied ONT numbers are taken from pon_onts_serial|pon_onts_mac_addr,
     * whichever module is supported by the device.
     *
     * @return array
     */
    function generateFreeOnts(Device $device, $from = 'cache')
    {
        $core = $this->getSwitcherCore()->getCore($device);

        $identModules = [];
        if ($core->isModuleExist('pon_onts_serial')) {
            $identModules[] = 'pon_onts_serial';
        }
        if ($core->isModuleExist('pon_onts_mac_addr')) {
            $identModules[] = 'pon_onts_mac_addr';
        }
        if (!$identModules) {
            return [];
        }

        $requests = [];
        foreach ($identModules as $module) {
            $requests[] = (new Request())->setDevice($device)->setModule($module);
        }
        switch ($from) {
            case 'device': $data = $this->getSwitcherCore()->fromDevice($requests); break;
            case 'store':  $data = $this->getSwitcherCore()->fromStore($requests, true, true); break;
            case 'cache':
            default:       $data = $this->getSwitcherCore()->fromCache($requests); break;
        }

        // Collect occupied ONT numbers grouped by parent PON port bind_key
        $occupied = [];
        foreach ($data->getAllResponses() as $resp) {
            if ($resp->getError()) {
                continue;
            }
            foreach ($resp->getDataAsArray() as $ont) {
                if (!isset($ont['interface']['parent']) || !isset($ont['interface']['_onu'])) {
                    continue;
                }
                $occupied[$ont['interface']['parent']][(int)$ont['interface']['_onu']] = true;
            }
        }

        $freeOnts = [];
        foreach ($this->interfaceStorage->getByDevice($device, DeviceInterface::TYPE_PON) as $iface) {
            try {
                $parsed = $core->action('parse_interface', ['interface' => $iface->getBindKey()]);
            } catch (\Throwable $e) {
                $this->logger->warning("free_onts: can't parse interface {$iface->getBindKey()}: {$e->getMessage()}");
                continue;
            }
            $free = $this->calculateFreeOnts(
                $parsed,
                isset($occupied[$iface->getBindKey()]) ? $occupied[$iface->getBindKey()] : [],
                $this->getOntOffset($device, $parsed)
            );
            if ($free === null) {
                continue;
            }
            $freeOnts[$iface->getName()] = $free;
        }
        return $freeOnts;
    }

    /**
     * @param array $iface parsed PON port interface
     * @param array $occupied [onu_number => true] of already registered ONTs on the port
     * @param int $offset shift of the ONT numbering range
     * @return array|null ['first' => <int|null>, 'all' => <array[int]>] or null if max size is unknown
     */
    protected function calculateFreeOnts($iface, $occupied, $offset = 0)
    {
        if (isset($iface['_pon_max_ont_size'])) {
            $maxOnts = (int)$iface['_pon_max_ont_size'];
        } elseif (isset($iface['_technology']) && $iface['_technology'] === 'epon') {
            $maxOnts = 64;
        } elseif (isset($iface['_technology']) && $iface['_technology'] === 'gpon') {
            $maxOnts = 128;
        } else {
            return null;
        }

        $free = [];
        for ($i = 1 - $offset; $i <= $maxOnts - $offset; $i++) {
            if (isset($occupied[$i])) {
                continue;
            }
            $free[] = $i;
        }
        return [
            'first' => count($free) ? $free[0] : null,
            'all' => $free,
        ];
    }

    protected function getOntOffset(Device $device, $iface)
    {
        if ($device->getModel()->getVendor() === 'Huawei' && isset($iface['_technology']) && $iface['_technology'] === 'gpon') {
            return 1;
        }
        return 0;
    }

    function getRequests(Device $device, $modules, ?DeviceInterface $iface = null)
    {
        $requests = [];
        foreach ($modules as $module) {
            $req = (new Request())
                ->setDevice($device)
                ->setModule($module);
            if($iface) {
                $req->setArguments(['interface' => $iface->getBindKey()]);
            }
            $requests[] = $req;
        }
        return $requests;
    }

    function getAllowedModules($switcherCore,  ?DeviceInterface $iface)
    {
        $supportedModules = array_map(function ($module) {
            return $module['name'];
        }, $switcherCore->getModulesData());
        $modules = array_filter($this->loadModules[$this->getDisplayFor($iface)], function ($module) use ($supportedModules) {
            return in_array($module, $supportedModules);
        });
        return $modules;
    }

    function getDisplayFor(?DeviceInterface $iface = null)
    {
        $for = 'DEVICE';
        if ($iface && $iface->getType() === 'PON') {
            $for = 'PON';
        } elseif ($iface && $iface->getType() === 'ONU') {
            $for = 'ONU';
        } elseif ($iface) {
            $for = 'PORT';
        }
        return $for;
    }


    function validateParameters(Macros $macros, $variables)
    {
        foreach ($macros->getParameters() as $variable) {
            if (isset($variables['params'][$variable['key']])) {
                $value = $variables['params'][$variable['key']];
            } else {
                $value = '';
            }
            switch ($variable['type']) {
                case 'select_from_variable':
                    $list = getArrayElementByKey($variables, $variable['source_parameter_key']);
                    if (!is_array($list)) {
                        throw new \Exception("{$variable['source_parameter_key']} not exists or not presented as list");
                    }
                    if (!in_array($value, $list)) {
                        throw new \Exception("Variable {$variable['key']} has incorrect value ".json_encode($value).". Possible variants: " .  json_encode($list));
                    }
                    break;
                case 'select_from_predefined':
                    $variants = explode("\n", $variable['variants_list']);
                    if (!in_array($value, $variants)) {
                        throw new \Exception("Variable {$variable['key']} has incorrect value $value. Possible variants: " . join(",", $variants));
                    }
                    break;
                case 'input_variable':
                case 'input_string':
                    if ($variable['regular_expr'] && !preg_match("/{$variable['regular_expr']}/", $value)) {
                        throw new \Exception("Variable {$variable['key']} has incorrect value $value by regular {$variable['regular_expr']}");
                    }
                    break;
            }
        }
    }

    /**
     * @return SwitcherCore
     */
    function getSwitcherCore()
    {
        return $this->switcherCore->setUser($this->user);
    }

    /**
     * @return string|null Null means "not overridden, use the site-wide default" —
     * distinct from an empty string, which the device-form field can leave
     * behind after a blank save.
     */
    private function deviceVlanInternetOverride(Device $device): ?string
    {
        $params = $device->getParams();
        $value = is_array($params) ? ($params['vlan_internet'] ?? null) : null;
        return ($value !== null && $value !== '') ? (string)$value : null;
    }

    /**
     * parse_interface's breakdown (_shelf/_frame/_slot/_port/_onu/
     * _technology etc.) for the given stored interface — local string
     * parsing per the model's own pattern, not a live device query. Never
     * throws: a model/interface combination that can't be parsed just
     * means no extra fields on top of the interface's plain stored ones.
     */
    private function parseInterfaceSafe($switcherCore, DeviceInterface $iface): array
    {
        try {
            return $switcherCore->action('parse_interface', ['interface' => $iface->getBindKey()]);
        } catch (\Throwable $e) {
            return [];
        }
    }
}


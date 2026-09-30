<?php


namespace WCC\OntsRegistration\Controllers;


use Monolog\Logger;
use WCAA\Exceptions\SwitcherCore\SnmpException;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\PrometheusMetrics;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\Exceptions\RecordNotFoundException;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\ResponsesWrapper;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\OntsRegistration\Models\UnregisteredOntMacro;
use WCC\OntsRegistration\Storage\UnregisteredOntMacroStorage;

class MacrosGateway extends AbstractComponentController
{


    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $eventsObserver;

    /**
     * @Inject
     * @var PrometheusMetrics
     */
    protected $prom;

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
     * @var UnregisteredOntMacroStorage
     */
    protected $macrosStorage;

    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;

    /**
     * @var User
     */
    protected $user;


    protected $loadModules = [
        'system',
        'vlans',
        'pon_profiles',
        'pon_onts_serial',
        'pon_onts_mac_addr',
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

    function execute(Device $device, UnregisteredOntMacro $macros, $variables, $executionId = null)
    {
        $this->validateParameters($macros, $variables);
        $template = $this->buildTemplate($macros->getTemplate(), $variables);
        if ($err = $this->checkException($template)) {
            throw new \Exception($err);
        }

        // $executionId (caller-generated, optional) makes real-time
        // per-step progress available while this call is still in flight —
        // see AbstractModule::multiRawConsoleCommandRun()'s own comment
        // (vendor, switcher-core) for the full mechanism, and the generic
        // Macros component's MacrosGateway::execute() for the same wiring.
        $data = $this->getSwitcherCore()->fromDevice(
            [
                (new Request())->setDevice($device)->setModule('multi_console_command')->setArguments(['commands' => $template, 'break_on_error' => 'yes', 'execution_id' => $executionId])
            ]
        );
        $response = $data->getFirstByModule('multi_console_command');

        if ($this->user->isRulePermitted('unregistered_onts_result_output')) {
            return [
                'commands' => $response->getData(),
                'error' => $response->getError(),
            ];
        } else {
            return [
                'commands' => [],
                'error' => $response->getError(),
            ];
        }
    }

    function preview(UnregisteredOntMacro $macros, $variables)
    {
        $this->validateParameters($macros, $variables);
        $template = $this->buildTemplate($macros->getTemplate(), $variables);
        return $template;
    }

    public function buildTemplate($template, $variables = [])
    {
        $result = '';
        foreach (explode("\n", $template) as $templateLine) {
            if (!trim($templateLine)) {
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
    function generateVariables(Device $device, $onuIdent, $parameters = [], $from = 'cache')
    {
        $ont = $this->getUnregisteredOntByDeviceIdent($device, $onuIdent, $from);
        $ifaces = $this->interfaceStorage->getByDevice($device);
        if (!$ont) {
            throw new SupportException("ONT with ident {$onuIdent} not found on device");
        }
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
                    'params' => $f->getParams(),
                    'description' => $f->getDescription(),
                ];
            }, $ifaces),
            'ont' => $ont,
            'iface' => $this->findInterface($device, $ont['interface']['parent']),
            'data' => null,
            'error' => null,
            'free' => [
                'first' => null,
                'all' => []
            ],
            // Internet VLAN: per-device override (Device edit → Provisioning
            // → "Internet VLAN override", stored in device.params) takes
            // priority over the site-wide default (Configuration → System
            // configuration → provisioning) — different OLTs/POPs can use
            // different VLANs without editing the template. {{ global.vlan_internet }}
            // instead of asking the operator for it every time either way.
            'global' => [
                'vlan_internet' => (int)($this->deviceVlanInternetOverride($device) ?? _env('VLAN_INTERNET', 100)),
            ],
        ];
        $switcherCore = $this->getSwitcherCore()->getCore($device);
        $allowedModules = $this->getAllowedModules($switcherCore);
        $requests = $this->getRequests($device, $allowedModules);

        switch ($from) {
            case 'device':
                $data = $this->getSwitcherCore()->fromDevice($requests);
                break;
            case 'cache':
                $data = $this->getSwitcherCore()->fromCache($requests);
                break;
            case 'store':
                // $catchErrors=true: this is a multi-module batch (one
                // request per allowed module), and any single module never
                // having been cached yet (e.g. pon_profiles) must not abort
                // the other, already-cached modules — errors are surfaced
                // per-module below via $dt->getError() instead.
                $data = $this->getSwitcherCore()->fromStore($requests, true, true);
                break;
        }
        foreach ($data->getAllResponses() as $dt) {
            $variables['data'][$dt->getModule()] = null;
            $variables['error'][$dt->getModule()] = null;
            if (!$dt->getError()) {
                $variables['data'][$dt->getModule()] = $dt->getData();
            } else {
                $variables['error'][$dt->getModule()] = $dt->getError();
            }
        }
        if (!$variables['params']) {
            $variables['params'] = new \stdClass();
        }
        $this->getFreeInterface($variables, $this->getOntOffset($device, $variables['iface']));


        $ident = $onuIdent;
        if(isset($variables['ont']['serial'])) {
            $ident = $variables['ont']['serial'];
        } elseif (isset($variables['ont']['mac_address'])) {
            $ident = $variables['ont']['mac_address'];
        }

        //Set external data
        $variables['external'] = $this->getExternalData($device, $ident);

        return $variables;
    }

    protected function getOntOffset(Device $device, $iface)
    {
        if ($device->getModel()->getVendor() === 'Huawei' && $iface['_technology'] === 'gpon') {
            return 1;
        }
        return 0;
    }

    function getFreeInterface(&$data, $offset = 0)
    {
        if (isset($data['iface']['_pon_max_ont_size'])) {
            $maxOnts = (int)$data['iface']['_pon_max_ont_size'];
        } elseif ($data['iface']['_technology'] == 'epon') {
            $maxOnts = 64;
        } elseif ($data['iface']['_technology'] == 'gpon') {
            $maxOnts = 128;
        } else {
            throw new \Exception("Can't detect number of ONTs on port");
        }

        $onts = [];
        if ($data['iface']['_technology'] === 'gpon' && $data['data']['pon_onts_serial']) {
            $onts = $data['data']['pon_onts_serial'];
        }
        if ($data['iface']['_technology'] === 'epon' && $data['data']['pon_onts_mac_addr']) {
            $onts = $data['data']['pon_onts_mac_addr'];
        }
        $keys = [];
        foreach ($onts as $ont) {
            if ($ont['interface']['parent'] != $data['iface']['id']) continue;
            $keys[$ont['interface']['_onu']] = $ont;
        }
        $freeNumbers = [];
        for ($i = 1 - $offset; $i <= $maxOnts - $offset; $i++) {
            if (isset($keys[$i])) continue;
            $freeNumbers[] = $i ;
        }

        if (count($freeNumbers) == 0) {
            throw new \Exception("Not found empty interfaces");
        }

        $data['free']['first'] = $freeNumbers[0];
        $data['free']['all'] = $freeNumbers;
        return $data;
    }

    function findInterface($device, $iface)
    {
        return $this->getSwitcherCore()->getCore($device)->action('parse_interface', ['interface' => $iface]);
    }

    /**
     * @throws RecordNotFoundException
     * @throws SwitcherCoreException
     * @throws SnmpException
     * @throws \ErrorException
     * @throws \Exception
     */
    function getUnregistered(?Device $device = null, $from = 'cache')
    {
        $requests = [];
        if ($device) {
            if (!$this->getSwitcherCore()->getCore($device)->isModuleExist('unregistered_onts')) {
                throw new \Exception("Current device not supported module unregistered ONTs");
            }
            $requests[] = (new Request())
                ->setDevice($device)
                ->setModule('unregistered_onts');
        } else {
            $unregistered = $this->macrosStorage->getAll();
            $allowedModelKeys = [];
            foreach ($unregistered as $unreg) {
                if(!$unreg->isEnabled()) continue;
                foreach($unreg->getModels() as $model) {
                    $allowedModelKeys[] = $model->getKey();
                }
            }
            $allowedModelKeys = array_unique($allowedModelKeys);

            foreach ($this->deviceStorage->fetchAll() as $device) {
                if ($device->getModel()->getType() != 'OLT') {
                    continue;
                }
                if (!$device->isEnabled()) {
                    continue;
                }
                if(!in_array($device->getModel()->getKey(), $allowedModelKeys)) {
                    continue;
                }
                try {
                    $requests[] = (new Request())
                        ->setDevice($device)
                        ->setModule('unregistered_onts');
                } catch (\Exception $e) {
                    $this->logger->error("Error get unregistered: " . $e->getMessage());
                }
            }
        }
        $responses = [];

        switch ($from) {
            case 'device':
                $responses = $this->getSwitcherCore()->fromDeviceMultiCall($requests);
                break;
            case 'cache':
                $responses = $this->getSwitcherCore()->fromCache($requests);
                break;
            case 'store':
                $responses = $this->getSwitcherCore()->fromStore($requests, true, true);
                break;
        }
        $data = [];
        foreach ($responses->getAllResponses() as $respons) {
            foreach ($respons->getDataAsArray() as $ont) {
                if (!isset($ont['interface'])) continue;
                // Huawei's "ont add" (used by the registration macro) does
                // not clear the device's own auto-find/unregistered table
                // the way "ont confirm" does — confirmed live: an ONT
                // registered via this feature kept reappearing here
                // indefinitely, and re-running "Register" on it hung the
                // console waiting on a confirmation prompt for an ONT ID
                // already in use. Cross-check against our own ident poller
                // (independent of whatever the device itself still thinks)
                // and hide anything already bound to a real interface.
                $ident = $ont['serial'] ?? ($ont['mac_address'] ?? null);
                if ($ident && $this->ontIdentStorage->getByIdent($ident)) {
                    continue;
                }
                $ont['device'] = $respons->getDevice()->getAsArray();
                $this->notifyAboutUnregisteredONT($ont);
                $data[] = $ont;
            }
        }
        return array_values($data);
    }


    function findOnuByIdent(Device $device, $ident)
    {
        if (preg_match('/^[[:xdigit:]]{2}:[[:xdigit:]]{2}:[[:xdigit:]]{2}:[[:xdigit:]]{2}:[[:xdigit:]]{2}:[[:xdigit:]]{2}$/', $ident)) {
            //This is MAC address
            $idents = $this->getSwitcherCore()->getCore($device)->action('pon_onts_mac_addr', ['use_cache' => 'no']);
        } else {
            $idents = $this->getSwitcherCore()->getCore($device)->action('pon_onts_serial', ['use_cache' => 'no']);
        }
        foreach ($idents as $ont) {
            if (isset($ont['serial']) && $ont['serial'] == $ident) return $ont;
            if (isset($ont['mac_address']) && $ont['mac_address'] == $ident) return $ont;
        }
        return null;
    }

    function getUnregisteredOntByDeviceIdent(Device $device, $ontIdent, $from = 'device')
    {
        $requests = [
            (new Request())->setDevice($device)->setModule('unregistered_onts')
        ];
        switch ($from) {
            case 'device':
                $data = $this->getSwitcherCore()->fromDevice($requests);
                break;
            case 'cache':
                $data = $this->getSwitcherCore()->fromCache($requests);
                break;
            case 'store':
                // Same fix as generateVariables() above: don't let a raw
                // CacheNotFound escape here — let the existing
                // $unreg->getError() check below turn it into a normal,
                // friendlier Exception instead.
                $data = $this->getSwitcherCore()->fromStore($requests, true, true);
                break;
        }
        $unreg = $data->getFirstByModule('unregistered_onts');
        if ($unreg->getError()) {
            throw new \Exception($unreg->getError());
        }
        foreach ($unreg->getData() as $nt) {
            if (isset($nt['serial']) && $nt['serial'] == $ontIdent) {
                $nt['_ident'] = $nt['serial'];
                return $nt;
            }
            if (isset($nt['mac_address']) && $nt['mac_address'] == $ontIdent) {
                $nt['_ident'] = $nt['mac_address'];
                return $nt;
            }
            if (isset($nt['_serial_hex']) && $nt['_serial_hex'] == $ontIdent) {
                return $nt;
            }
            if (isset($nt['_serial_ascii']) && $nt['_serial_ascii'] == $ontIdent) {
                return $nt;
            }
        }
        return null;
    }

    function validateParameters(UnregisteredOntMacro $macros, $variables)
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
                        throw new \Exception("Variable {$variable['key']} has incorrect value " . json_encode($value) . ". Possible variants: " . json_encode($list));
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

    private function notifyAboutUnregisteredONT($data)
    {
        $type = 'SERIAL';
        if (isset($data['mac_address']) && $data['mac_address']) {
            $type = 'MAC';
        }
        $ident = '';
        if (isset($data['mac_address']) && $data['mac_address']) $ident = $data['mac_address'];
        if (isset($data['serial']) && $data['serial']) $ident = $data['serial'];
        if (!$this->prom->getLastValues("unregistered_ont", ['ident' => $ident])) {
            $this->eventsObserver->notify("unregistered_ont:new", [
                'data' => $data,
                'type' => $type,
                'ident' => $ident,
                'device' => [
                    'id' => $data['device']['id'],
                    'ip' => $data['device']['ip'],
                    'name' => $data['device']['name'],
                ],
                'iface_name' => $data['interface']['name']
            ]);
        }
        $this->prom->setGauge("unregistered_ont", 1, [
            'dev_id' => $data['device']['id'],
            'ip' => $data['device']['ip'],
            'iface_id' => $data['interface']['parent'],
            'iface_name' => $data['interface']['name'],
            'ident' => $ident,
            'type' => $type,
        ], "List of unregistered ONTs", 600);
    }

    private function getRequests(Device $device, $modules)
    {
        $requests = [];
        foreach ($modules as $module) {
            $req = (new Request())
                ->setDevice($device)
                ->setModule($module);
            $requests[] = $req;
        }
        return $requests;
    }

    private function getAllowedModules($switcherCore)
    {
        $supportedModules = array_map(function ($module) {
            return $module['name'];
        }, $switcherCore->getModulesData());
        $modules = array_filter($this->loadModules, function ($module) use ($supportedModules) {
            return in_array($module, $supportedModules);
        });
        return $modules;
    }

    private function checkException($template)
    {
        foreach (explode("\n", $template) as $line) {
            if (preg_match("/\<\s*?exception *?['\"](.*)['\"].*?\>/", $line, $match)) {
                return $match[1];
            }
        }
        return null;
    }




    protected function getExternalData(Device $device, $serialMac = '')
    {
        $externalData = [
            'mikbill' => null,
        ];
        if(
            $serialMac &&
            $this->app->getComponentInjector()->isComponentEnabled('mikbill_integration') &&
            $mikbill = $this->app->getComponentInjector()->getController('mikbill_integration')
        ) {
            /**
             * @var $mikbill \WCC\MikBillIntegration\Controllers\Controller
             */
            $type = 'serial';
            if(preg_match('/^[[:xdigit:]]{2}:/', $serialMac)) {
                $type = 'mac';
            }
            try {
                $externalData['mikbill'] = $mikbill->searchClientBy($type, $serialMac);
            } catch (\Exception $e) {
                $this->logger->error("error get information from mikbill: {$e->getMessage()}");
            }
        }
        return $externalData;
    }
}


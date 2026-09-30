<?php


namespace WCAA\SwitcherCore;


use Curl\Curl;
use Curl\MultiCurl;
use DI\Annotation\Inject;
use Monolog\Logger;
use SwitcherCore\Switcher\CacheInterface;
use SwitcherCore\Switcher\Console\ConsoleInterface;
use SwitcherCore\Switcher\CoreConnector;
use WCAA\App;
use WCAA\Exceptions\SwitcherCore\DeviceIsIcmpDown;
use WCAA\Exceptions\SwitcherCore\SnmpException;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Models\Devices\Device;
use WCAA\Models\SwitcherCoreAction;
use WCAA\Models\User\User;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SwitcherCoreActionStorage;
use WCAA\SwitcherCore\CacheSystem\CacheSystemInterface;
use WCC\Pinger\Storage\PingerDeviceStatusStorage;

class SwitcherCore
{
    /**
     * @var SwitcherCoreActionStorage
     */
    protected $actionStorage;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var User
     */
    protected $user;


    /**
     * @var CacheSystemInterface
     */
    protected $swcCache;

    /**
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @var CoreConnector
     */
    protected $core;

    /**
     * @Inject
     * @var PingerDeviceStatusStorage
     */
    protected $icmp;

    /**
     * @var App
     */
    protected $app;


    function __construct(CacheInterface $switcherCoreCache, App $app, CacheSystemInterface $cache, CoreConnector $core, DeviceStorage $deviceStorage, SwitcherCoreActionStorage $actionStorage, Logger $logger)
    {
        $this->app = $app;
        $this->core = $core;
        $this->deviceStorage = $deviceStorage;
        $this->actionStorage = $actionStorage;
        $this->logger = $logger;
        $this->swcCache = $cache;

        $this->core->setLogger($this->logger->withName('switcher-core'))->setCache($switcherCoreCache);
    }

    function setUser(User $user)
    {
        $this->logger->info("Setted user", $user->getAsArray());
        $this->user = $user;
        return clone $this;
    }


    /**
     * @return CoreConnector
     */
    function getClient()
    {
        return $this->core;
    }


    /**
     * @param Request[] $reqs
     * @param null|bool|int $concurrency
     * @return ResponsesWrapper
     * @throws \ErrorException
     */
    function fromDevice(array $reqs, $concurrency = null)
    {
        $reqs = $this->prepareHacksForRequests($reqs);

        $respForDownDevices = [];
        if ($this->app->conf('switcher_core.check_icmp_ping')) {
            $devices = [];
            foreach ($reqs as $key => $req) {
                if (isset($devices[$req->getDevice()->getId()])) {
                    $status = $devices[$req->getDevice()->getId()];
                } else {
                    $status = $this->icmp->getDeviceStatus($req->getDevice());
                    $devices[$req->getDevice()->getId()] = $status;
                }
                if(!$status) {
                    $response = (new Response())
                        ->setDevice($req->getDevice())
                        ->setArguments($req->getArguments())
                        ->setModule($req->getModule())
                        ->setUser($this->user)
                        ->setSource('device');
                    $response->setStatus(Response::STATUS_FAILED);
                    $response->setError(new DeviceIsIcmpDown("Device has just been added, try again later"));
                    $respForDownDevices[] = $response;
                    unset($reqs[$key]);
                } elseif (!$status->isUp()) {
                    $response = (new Response())
                        ->setDevice($req->getDevice())
                        ->setArguments($req->getArguments())
                        ->setModule($req->getModule())
                        ->setUser($this->user)
                        ->setSource('device');
                    $response->setStatus(Response::STATUS_FAILED);
                    $response->setError(new DeviceIsIcmpDown("Device is DOWN over ICMP"));
                    $respForDownDevices[] = $response;
                    unset($reqs[$key]);
                }
            }
        }


        //Send request
        if ($concurrency) {
            if (is_numeric($concurrency)) {
                $concurrencySize = $concurrency;
            } else {
                $concurrencySize = null;
            }
            $responses = $this->fromDeviceMultiCall($reqs, $concurrencySize);
        } else {
            $responses = $this->fromDeviceBatchCall($reqs);
        }
        foreach ($respForDownDevices as $resp) {
            $responses->addResponse($resp);
        }

        //Write to cache and save errors
        foreach ($responses->getAllResponses() as $response) {
            $this->swcCache->write($response);
            $this->writeCacheWithReponsesSplittedByInterface($response);
            if ($response->getError() && $response->getError()['type'] !== 'SWC_ICMP_NOT_RESPOND') {
                try {
                    /**
                     * @var $telnet ConsoleInterface
                     */
                    $telnet = $this->core
                        ->get($response->getDevice()->getIp())
                        ->getContainer()
                        ->get(ConsoleInterface::class);
                    $buffer = $telnet->getGlobalBuffer();
                } catch (\Throwable $e) {
                    $buffer = "";
                }
                $buffer = str_replace(
                    [
                        $response->getDevice()->getAccess()->getLogin(),
                        $response->getDevice()->getAccess()->getPassword(),
                        $response->getDevice()->getAccess()->getPublicCommunity(),
                    ],
                    [
                        str_repeat("*", strlen($response->getDevice()->getAccess()->getLogin())),
                        str_repeat("*", strlen($response->getDevice()->getAccess()->getPassword())),
                        str_repeat("*", strlen($response->getDevice()->getAccess()->getPublicCommunity())),
                    ],
                    $buffer);
                $this->actionStorage->add(
                    (new SwitcherCoreAction())
                        ->setUser($this->user)
                        ->setDevice($response->getDevice())
                        ->setModule($response->getModule())
                        ->setStatus($response->getError() ? SwitcherCoreAction::STATUS_FAILED : SwitcherCoreAction::STATUS_SUCCESS)
                        ->setData($response->getData())
                        ->setArguments($response->getArguments())
                        ->setMeta([
                            'error' => $response->getError(),
                            'telnet_output' => $buffer,
                        ])
                );
            } elseif ($response->getModule() === 'multi_console_command') {
                foreach ($response->getData() as $d) {
                    if (!$d['success']) {
                        $response->setError(new \Exception("Error execute command: '{$d['command']}'"));
                    }
                }
                try {
                    /**
                     * @var $telnet ConsoleInterface
                     */
                    $telnet = $this->core
                        ->get($response->getDevice()->getIp())
                        ->getContainer()
                        ->get(ConsoleInterface::class);
                    $buffer = $telnet->getGlobalBuffer();
                } catch (\Throwable $e) {
                    $buffer = "";
                }
                $buffer = str_replace(
                    [
                        $response->getDevice()->getAccess()->getLogin(),
                        $response->getDevice()->getAccess()->getPassword(),
                        $response->getDevice()->getAccess()->getPublicCommunity(),
                    ],
                    [
                        str_repeat("*", strlen($response->getDevice()->getAccess()->getLogin())),
                        str_repeat("*", strlen($response->getDevice()->getAccess()->getPassword())),
                        str_repeat("*", strlen($response->getDevice()->getAccess()->getPublicCommunity())),
                    ],
                    $buffer);
                $this->actionStorage->add(
                    (new SwitcherCoreAction())
                        ->setUser($this->user)
                        ->setDevice($response->getDevice())
                        ->setModule($response->getModule())
                        ->setStatus($response->getError() ? SwitcherCoreAction::STATUS_FAILED : SwitcherCoreAction::STATUS_SUCCESS)
                        ->setData($response->getData())
                        ->setArguments($response->getArguments())
                        ->setMeta([
                            'error' => $response->getError(),
                            'telnet_output' => $buffer,
                        ])
                );
            }
        }
        return $responses;
    }


    /**
     * @param Request[] $reqs
     * @return ResponsesWrapper
     * @throws \WCAA\Storage\Exceptions\RecordNotFoundException
     */
    function fromCache(array $reqs)
    {
        $reqs = $this->prepareHacksForRequests($reqs);
        $failedRequests = [];
        $responses = [];
        foreach ($reqs as $req) {
            $this->logger->withName('swcore')->notice("Calling from cache {$req->getDevice()->getIp()} module {$req->getModule()}", $req->getArguments());
            try {
                $data = $this->fromStore([$req], false)->findByRequest($req);
                if ($data->getError()) {
                    throw new \Exception($data->getError());
                }
                $responses[] = $data;
            } catch (\Exception $e) {
                $failedRequests[] = $req;
                $this->logger->warning("Error get info from cache for {$req->getDevice()->getIp()}:{$req->getModule()}: {$e->getMessage()}");
            }
        }
        if ($failedRequests) {
            $data = $this->fromDevice($failedRequests);
            foreach ($data->getAllResponses() as $resp) {
                $responses[] = $resp;
            }
        }
        return (new ResponsesWrapper($responses));
    }

    /**
     * @param Request[] $reqs
     * @param bool $noTimeout
     * @return ResponsesWrapper
     * @throws \Exception
     */
    function fromStore(array $reqs, $noTimeout = true, $catchErrors = false)
    {
        $reqs = $this->prepareHacksForRequests($reqs);
        $responses = [];
        foreach ($reqs as $req) {
            try {
                if ($noTimeout) {
                    $responses[] = $this->swcCache->getWithoutTimeout($req)->setSource('cache');
                } else {
                    $responses[] = $this->swcCache->get($req)->setSource('cache');
                }
            } catch (\Exception $e) {
                if($catchErrors) {
                    $responses[] = (new Response())
                        ->setDevice($req->getDevice())
                        ->setArguments($req->getArguments())
                        ->setModule($req->getModule())
                        ->setUser($this->user)
                        ->setError($e->getMessage())
                        ->setSource('cache');
                } else {
                    throw $e;
                }
            }
        }
        return new ResponsesWrapper($responses);
    }


    /**
     * @param Device $dev
     * @return \SwitcherCore\Switcher\Core
     * @throws SnmpException
     * @throws SwitcherCoreException
     */
    function getCore(Device $dev): \SwitcherCore\Switcher\Core
    {
        $params = App::getInstance()->getConfig()['switcher_core'];

        $pubCommunity = $dev->getAccess()->getPublicCommunity();
        $privateCommunity = $dev->getAccess()->getPrivateCommunity();
        $login = $dev->getAccess()->getLogin();
        $password = $dev->getAccess()->getPassword();

        if ($dev->getModel()) {
            $modelParams = $dev->getModel()->getParams();
            if (isset($modelParams['access']['login'])) {
                $login = $modelParams['access']['login'];
            }
            if (isset($modelParams['access']['password'])) {
                $password = $modelParams['access']['password'];
            }
            if (isset($modelParams['access']['public_community'])) {
                $pubCommunity = $modelParams['access']['public_community'];
            }
            if (isset($modelParams['access']['private_community'])) {
                $privateCommunity = $modelParams['access']['private_community'];
            }
        }
        $devParams = $dev->getParams();
        if (isset($devParams['access']['login'])) {
            $login = $devParams['access']['login'];
        }
        if (isset($devParams['access']['password'])) {
            $password = $devParams['access']['password'];
        }
        if (isset($devParams['access']['public_community'])) {
            $pubCommunity = $devParams['access']['public_community'];
        }
        if (isset($devParams['access']['private_community'])) {
            $privateCommunity = $devParams['access']['private_community'];
        }

        $device = (new \SwitcherCore\Switcher\Device())
            ->setIp($dev->getIp())
            ->setPublicCommunity($pubCommunity)
            ->setPrivateCommunity($privateCommunity)
            ->setLogin($login)
            ->setPassword($password);
        if ($model = $dev->getModel()) {
            $device->setModelKey($model->getKey());
        }
        $acParam = $dev->getAccess()->getParams();
        if (isset($acParam['sw_core_connection'])) {
            $conn = $acParam['sw_core_connection'];
            if (isset($conn['console_port']) && $conn['console_port']) {
                $params['console_port'] = $conn['console_port'];
            }
            if (isset($conn['snmp_version']) && $conn['snmp_version']) {
                $params['snmp_version'] = $conn['snmp_version'];
            }
            if (isset($conn['console_wait_byte_sec']) && $conn['console_wait_byte_sec']) {
                $params['console_wait_byte_sec'] = $conn['console_wait_byte_sec'];
            }
            if (isset($conn['console_timeout_sec']) && $conn['console_timeout_sec']) {
                $params['console_timeout_sec'] = $conn['console_timeout_sec'];
            }
            if (isset($conn['console_connection_type']) && $conn['console_connection_type']) {
                $params['console_connection_type'] = $conn['console_connection_type'];
            }
            if (isset($conn['snmp_timeout_sec']) && $conn['snmp_timeout_sec']) {
                $params['snmp_timeout_sec'] = $conn['snmp_timeout_sec'];
            }
            if (isset($conn['snmp_repeats']) && $conn['snmp_repeats']) {
                $params['snmp_repeats'] = $conn['snmp_repeats'];
            }
            if (isset($conn['mikrotik_api_port']) && $conn['mikrotik_api_port']) {
                $params['mikrotik_api_port'] = $conn['mikrotik_api_port'];
            }
            if (isset($conn['snmp_port']) && $conn['snmp_port']) {
                $params['snmp_port'] = $conn['snmp_port'];
            }
        }

        $modelParams = $dev->getModel();
        if ($modelParams && isset($modelParams->getParams()['sw_core_connection'])) {
            $modelConnParams = $modelParams->getParams()['sw_core_connection'];
            if (isset($modelConnParams['console_port']) && $modelConnParams['console_port']) {
                $params['console_port'] = $modelConnParams['console_port'];
            }
            if (isset($modelConnParams['console_timeout_sec']) && $modelConnParams['console_timeout_sec']) {
                $params['console_timeout_sec'] = $modelConnParams['console_timeout_sec'];
            }
            if (isset($modelConnParams['console_connection_type']) && $modelConnParams['console_connection_type']) {
                $params['console_connection_type'] = $modelConnParams['console_connection_type'];
            }
            if (isset($modelConnParams['snmp_timeout_sec']) && $modelConnParams['snmp_timeout_sec']) {
                $params['snmp_timeout_sec'] = $modelConnParams['snmp_timeout_sec'];
            }
            if (isset($modelConnParams['snmp_repeats']) && $modelConnParams['snmp_repeats']) {
                $params['snmp_repeats'] = $modelConnParams['snmp_repeats'];
            }
            if (isset($modelConnParams['mikrotik_api_port']) && $modelConnParams['mikrotik_api_port']) {
                $params['mikrotik_api_port'] = $modelConnParams['mikrotik_api_port'];
            }
            if (isset($modelConnParams['snmp_port']) && $modelConnParams['snmp_port']) {
                $params['snmp_port'] = $modelConnParams['snmp_port'];
            }
            if (isset($modelConnParams['snmp_version']) && $modelConnParams['snmp_version']) {
                $params['snmp_version'] = $modelConnParams['snmp_version'];
            }
            if (isset($modelConnParams['console_wait_byte_sec']) && $modelConnParams['console_wait_byte_sec']) {
                $params['console_wait_byte_sec'] = $modelConnParams['console_wait_byte_sec'];
            }
        }
        $devParam = $dev->getParams();
        if (isset($devParam['sw_core_connection'])) {
            $conn = $devParam['sw_core_connection'];
            if (isset($conn['console_port']) && $conn['console_port']) {
                $params['console_port'] = $conn['console_port'];
            }
            if (isset($conn['console_timeout_sec']) && $conn['console_timeout_sec']) {
                $params['console_timeout_sec'] = $conn['console_timeout_sec'];
            }
            if (isset($conn['console_connection_type']) && $conn['console_connection_type']) {
                $params['console_connection_type'] = $conn['console_connection_type'];
            }
            if (isset($conn['snmp_timeout_sec']) && $conn['snmp_timeout_sec']) {
                $params['snmp_timeout_sec'] = $conn['snmp_timeout_sec'];
            }
            if (isset($conn['snmp_repeats']) && $conn['snmp_repeats']) {
                $params['snmp_repeats'] = $conn['snmp_repeats'];
            }
            if (isset($conn['mikrotik_api_port']) && $conn['mikrotik_api_port']) {
                $params['mikrotik_api_port'] = $conn['mikrotik_api_port'];
            }
            if (isset($conn['snmp_port']) && $conn['snmp_port']) {
                $params['snmp_port'] = $conn['snmp_port'];
            }
            if (isset($conn['snmp_version']) && $conn['snmp_version']) {
                $params['snmp_version'] = $conn['snmp_version'];
            }
            if (isset($conn['console_wait_byte_sec']) && $conn['console_wait_byte_sec']) {
                $params['console_wait_byte_sec'] = $conn['console_wait_byte_sec'];
            }
        }
        $device->consolePort = $params['console_port'];
        $device->consoleTimeout = $params['console_timeout_sec'];
        $device->consoleWaitByteSec = $params['console_wait_byte_sec'];
        $device->consoleConnectionType = $params['console_connection_type'];
        $device->mikrotikApiPort = $params['mikrotik_api_port'];
        $device->snmpTimeoutSec = $params['snmp_timeout_sec'];
        $device->snmpRepeats = $params['snmp_repeats'];
        $device->snmpPort = $params['snmp_port'];
        $device->snmpVersion = $params['snmp_version'];

        $this->logger->info("Initializing core for device {$dev->getName()} ({$dev->getIp()})");
        try {
            $core = $this->core->getOrInit($device);
            return $core;
        } catch (\Exception $e) {
            if ($e instanceof \SNMPException) {
                if (strpos($e->getMessage(), "SNMPException: No response from") !== false) {
                    throw new SnmpException("Device {$device->getIp()} not respond over SNMP");
                } else {
                    throw new SnmpException("error working with device  {$device->getIp()} over SNMP: " . $e->getMessage());
                }
            } else {
                throw new SwitcherCoreException($e->getMessage(), 500, $e);
            }
        }
    }

    /**
     * @param Request[] $reqs
     * @return ResponsesWrapper
     */
    function fromDeviceBatchCall(array $reqs)
    {
        $responses = [];
        foreach ($reqs as $req) {
            $this->logger->withName('swcore')->notice("Calling from device {$req->getDevice()->getIp()} module {$req->getModule()}", $req->getArguments());

            $response = (new Response())
                ->setDevice($req->getDevice())
                ->setArguments($req->getArguments())
                ->setModule($req->getModule())
                ->setUser($this->user)
                ->setSource('device');
            try {
                $this->logger->notice("Start calling switcher-core {$req->getDevice()->getIp()} {$req->getModule()}", $req->getAsArray());
                $starttime = microtime(true);
                $resp = $this->getCore($req->getDevice())->action($req->getModule(), $req->getArguments());
                $response->setData($resp);
                $response->setSpent(microtime(TRUE) - $starttime);
            } catch (\Throwable $e) {
                $this->logger->error("Error calling switcher-core {$req->getDevice()->getIp()} {$req->getModule()}", $req->getAsArray());
                foreach (explode("\n", $e->getTraceAsString()) as $line) {
                    $this->logger->debug($line);
                }
                $response->setStatus(Response::STATUS_FAILED);
                $response->setError($e);
                if (!$req->isCatchErrors()) {
                    throw $e;
                }
            }
            $responses[] = $response;
        }
        return new ResponsesWrapper($responses);
    }

    /**
     * @param Request[] $reqs
     * @return ResponsesWrapper
     * @throws \ErrorException
     */
    function fromDeviceMultiCall(array $reqs, $concurrency = null)
    {
        //Init curl
        if (!$concurrency) {
            $concurrency = $this->app->conf('switcher_core.cuncurrency');
        }

        $mCurl = new MultiCurl();
        $mCurl->setUserAgent("SwitcherCore multicalling");
        $mCurl->setConcurrency($concurrency);
        $mCurl->setRetry(1);
        $mCurl->setTimeout(300);
        $mCurl->setJsonDecoder(function ($resp) {
            return json_decode($resp, true);
        });

        //Add curl requests
        foreach ($reqs as $request) {
            $this->logger->withName('swcore')->notice("Calling multicall device {$request->getDevice()->getIp()} module {$request->getModule()}", $request->getArguments());
            if (!($request instanceof Request)) {
                throw new \Exception("Must be array of SwitcherCore\Request");
            }
            $curl = new Curl();
            $curl->setTimeout(300);
            $curl->setUserAgent("SwitcherCore multicalling");
            $curl->setHeader('Content-Type', 'application/json');
            $curl->setUrl($this->app->conf('switcher_core.swc_url') .
                "/device/{$request->getModule()}/{$request->getDevice()->getId()}");
            $curl->setOpt(CURLOPT_POST, true);
            $curl->setOpt(CURLOPT_POSTFIELDS, $curl->buildPostData($request->getArguments()));
            $req = $mCurl->addCurl($curl);
            $req->req = $request;
        }

        $responses = [];

        //Init functions
        $mCurl->success(function ($instance) use (&$responses) {
            /**
             * @var  Request $request
             */
            $request = $instance->req;
            $responses[] = (new Response())
                ->setArguments($request->getArguments())
                ->setModule($request->getModule())
                ->setDevice($request->getDevice())
                ->setUser($this->user)
                ->setError(isset($instance->response['error']) ? $instance->response['error'] : null)
                ->setStatus(isset($instance->response['error']) ? Response::STATUS_FAILED : Response::STATUS_SUCCESS)
                ->setData(isset($instance->response['data']) ? $instance->response['data'] : null)
                ->setMeta(isset($instance->response['meta']) ? $instance->response['meta'] : null);
        });
        $mCurl->error(function ($instance) use (&$responses) {
            /**
             * @var  Request $request
             */
            $request = $instance->req;
            if (!$request->isCatchErrors() && (isset($instance->response['error']) || $instance->errorMessage)) {
                throw new SwitcherCoreException(isset($instance->response['error']) ? $instance->response['error'] : $instance->errorMessage);
            }
            $responses[] = (new Response())
                ->setArguments($request->getArguments())
                ->setModule($request->getModule())
                ->setDevice($request->getDevice())
                ->setUser($this->user)
                ->setError(isset($instance->response['error']) ? $instance->response['error'] : null)
                ->setStatus(isset($instance->response['error']) ? Response::STATUS_FAILED : Response::STATUS_SUCCESS)
                ->setData(isset($instance->response['data']) ? $instance->response['data'] : null)
                ->setMeta(isset($instance->response['meta']) ? $instance->response['meta'] : null);
        });
        $mCurl->start();
        return new ResponsesWrapper($responses);
    }

    function prepareHacksForRequests(&$reqs)
    {
        //Added hack for SN as ASCII on Huawei.
        foreach ($reqs as $key => $req) {
            if (
                $req->getDevice()->getModel()->getVendor() === 'Huawei' &&
                $req->getDevice()->getModel()->getType() === 'OLT' &&
                in_array($req->getModule(), ['pon_onts_serial', 'unregistered_onts'])
            ) {
                if ($ascii = $req->getDevice()->getParamByName('sn_as_ascii')) {
                    $arguments = $req->getArguments();
                    $arguments['sn_as_ascii'] = $ascii;
                    $reqs[$key]->setArguments($arguments);
                } elseif ($ascii = $req->getDevice()->getModel()->getParamByName('sn_as_ascii')) {
                    $arguments = $req->getArguments();
                    $arguments['sn_as_ascii'] = $ascii;
                    $reqs[$key]->setArguments($arguments);
                }
            }
        }

        //Added hack for description in ZTE epon
        foreach ($reqs as $key => $req) {
            if (
                $req->getDevice()->getModel()->getVendor() === 'ZTE' &&
                $req->getDevice()->getModel()->getType() === 'OLT' &&
                in_array($req->getModule(), ['interface_descriptions'])
            ) {
                $blockByDevice = $req->getDevice()->getParamByName('epon_description_block_index');
                $blockByModel = $req->getDevice()->getModel()->getParamByName('epon_description_block_index');
                if ($blockByDevice !== null) {
                    $arguments = $req->getArguments();
                    $arguments['_description_block_index'] = $blockByDevice;
                    $reqs[$key]->setArguments($arguments);
                } elseif ($blockByModel) {
                    $arguments = $req->getArguments();
                    $arguments['_description_block_index'] = $blockByModel;
                    $reqs[$key]->setArguments($arguments);
                }
            }
        }

        return $reqs;
    }

    function writeCacheWithReponsesSplittedByInterface(Response $data)
    {
        if(!$this->app->conf('switcher_core.enable_splitting'))  return;
        if(in_array('interface', $data->getArguments())) return;
        if($data->getError()) return;
        if(!$data->getData()) return;
        if(!is_array($data->getData())) return;
        if(!in_array($data->getModule(), $this->app->conf('switcher_core.split_by_interface_methods'))) {
            return;
        }
        $dataElements = array_filter($data->getData(), function ($d) {return isset($d['interface']['id']) && $d['interface']; });
        //GroupingElements
        $grouped = [];
        foreach ($dataElements as $element) {
            $grouped[$element['interface']['id']][] = $element;
        }

        foreach ($grouped as $ifaceID=>$ifaceData) {
            $arguments = $data->getArguments();
            unset($arguments['interface_type']);
            $response = (clone $data)
                ->setArguments(array_merge(['interface' => $ifaceID], $arguments))
                ->setData($ifaceData);
            $this->swcCache->write($response);
        }
    }

    public function getSwcCache()
    {
        return $this->swcCache;
    }
}

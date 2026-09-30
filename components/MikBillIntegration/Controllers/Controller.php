<?php


namespace WCC\MikBillIntegration\Controllers;


use GuzzleHttp\Client;
use Monolog\Logger;
use SwitcherCore\Modules\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\FdbHistory;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCC\Diagnostic\Controllers\InterfaceDiager;
use WCC\Diagnostic\Exceptions\MacAddressNotFoundInSupport;
use WCC\Diagnostic\Exceptions\UserInfoNotFoundByID;
use WCC\Links\Storage\LinkStorage;

/**
 * Class Controller
 * @package WCC\MikBillIntegration
 */
class Controller extends AbstractComponentController
{

    /**
     * @var array|null
     */
    protected $cfg = null;


    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $fdbHistoryStorage;

    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;

    /**
     * @var LinkStorage
     */
    protected $links;

    /**
     * @var ComponentInjector
     */
    protected $componentInjector;

    protected $isDebug;


    /**
     * @return mixed
     */
    public function getIsDebug()
    {
        return $this->isDebug;
    }

    /**
     * @param mixed $isDebug
     * @return Controller
     */
    public function setIsDebug($isDebug)
    {
        $this->isDebug = $isDebug;
        return $this;
    }


    public function __construct(App $app, ComponentInjector $componentInjector, Logger $logger)
    {

        $this->componentInjector = $componentInjector;
        $this->links = $app->getContainer()->get(LinkStorage::class);

        parent::__construct($componentInjector, $logger);
    }

    /**
     * @var OutputInterface
     */
    protected $_output;

    function setConsoleOutput(OutputInterface $output)
    {
        $this->_output = $output;
        return $this;
    }

    function syncAllClients($forceSync = false)
    {
        $this->log("INFO", "Start syncing...");
        $billingSearching = $this->getAllClients();
        $this->log("INFO", "Found " . count($billingSearching) . " devices from billing for sync");

        /**
         * [
         *    {
         *      "search":{
         *          "switch":"93.x.x.x",
         *          "port":4,
         *          "client_mac":"",
         *          "onu_ident":"ZTEGC69EF036"
         *      },
         *      "data":{
         *        "uid":1,
         *        "billing_link":"/#clientcard/1",
         *        "agreement":"00001",
         *        "login":"test",
         *        "address":{
         *            "full":"Рівне, Євгенія Борового вулиця 1/15",
         *            "settlement":"Рівне",
         *            "lane":"Євгенія Борового вулиця",
         *            "house":"1",
         *            "app":"15"
         *         }
         *       }
         *     }
         * ]
         */

        $topologyLinks = $this->_getIgnoreInterfacesListByTopology();
        $data = [];
        foreach ($billingSearching as $bill) {
            //Searching by switch|port
            try {
                if ($bill['search']['client_mac']) {
                    foreach ($this->fdbHistoryStorage->getByMac($bill['search']['client_mac'], true) as $fdb) {
                        $data[$fdb->getInterface()->getId()][$bill['data']['uid']] = [
                            'iface' => $fdb->getInterface(),
                            'data' => $bill['data'],
                            'search_by' => 'client_mac',
                        ];
                    }
                }

                if ($bill['search']['switch'] && $bill['search']['port']) {
                    $dev = $this->deviceStorage->getByIp($bill['search']['switch']);
                    try {
                        $iface = $this->deviceInterfaceStorage->getByDeviceAndKey($dev, $bill['search']['port']);
                        $data[$iface->getId()][$bill['data']['uid']] = [
                            'iface' => $iface,
                            'data' => $bill['data'],
                            'search_by' => 'switch_port',
                        ];
                    } catch (\Exception $e) {
                        $iface = $this->deviceInterfaceStorage->getByDeviceAndName($dev, $bill['search']['port']);
                        $data[$iface->getId()][$bill['data']['uid']] = [
                            'iface' => $iface,
                            'data' => $bill['data'],
                            'search_by' => 'switch_port',
                        ];
                    }
                }

                if ($bill['search']['onu_ident']) {
                    foreach ($this->ontIdentStorage->getByIdent($bill['search']['onu_ident']) as $ident) {
                        $data[$ident->getInterface()->getId()][$bill['data']['uid']] = [
                            'iface' => $ident->getInterface(),
                            'data' => $bill['data'],
                            'search_by' => 'onu_ident',
                        ];
                    }
                }
            } catch (\Exception $e) {
                $this->log("ERROR", "{$e->getMessage()}");
            }
        }
        $this->log("INFO", "Found " . count($data) . " interfaces by search params");
        $existedIfaceIds = [];
        $this->log("INFO", "Start update interfaces");

        $searchingField = _env('MIKBILL_GLOBAL_SEARCH_FIELD', 'agreement');
        $this->log("INFO", "Setted global search param = {$searchingField}");
        foreach ($data as $ifaceID => $clients) {
            if (count($clients) > 1) {
                continue;
            }
            foreach ($clients as $cl) {
                try {
                    /**
                     * @var $iface DeviceInterface
                     */
                    $iface = $cl['iface'];
                    $data = $cl['data'];

                    $existedIfaceIds[$ifaceID] = $iface;
                    $mustUpdate = false;
                    if (isset($iface->getParams()['billing']['mikbill'])) {
                        $old = $iface->getParams()['billing']['mikbill'];
                        if ($data['agreement'] != $old['agreement']
                            || $data['login'] != $old['login']
                            || $data['uid'] != $old['uid']
                            || $data['address']['full'] != $old['address']['full']
                        ) {
                            $this->log("NOTIFY", "Client {$data['uid']} has some changes on interface {$iface->getDevice()->getIp()}:{$iface->getName()}, updating ");
                            $mustUpdate = true;
                        } elseif ($iface->getAgreement() !== $data[$searchingField]) {
                            $this->log("NOTIFY", "Client {$data['uid']} has changes over agreement on interface {$iface->getDevice()->getIp()}:{$iface->getName()}, updating ");
                            $mustUpdate = true;
                        } elseif ($forceSync) {
                            $mustUpdate = true;
                        }
                    } else {
                        $this->log("NOTIFY", "New client found with uid={$data['uid']} on interface {$iface->getDevice()->getIp()}:{$iface->getName()}, updating ");
                        $mustUpdate = true;
                    }

                    if ($mustUpdate && !isset($topologyLinks[$ifaceID])) {
                        $this->log("INFO", "Updating interface {$iface->getDevice()->getIp()}:{$iface->getName()} with client {$data['uid']}");
                        //MIKBILL_GLOBAL_SEARCH_FIELD
                        $agreement = '';
                        switch (_env('MIKBILL_GLOBAL_SEARCH_FIELD')) {
                            case 'uid':
                                $agreement = $data['uid'];
                                break;
                            case 'agreement':
                                $agreement = $data['agreement'];
                                break;
                            case 'login':
                                $agreement = $data['login'];
                                break;
                        }
                        $billingLink = _env('MIKBILL_API_ADDR') . $data['billing_link'];
                        $params = $iface->getParams();
                        $params['billing']['mikbill'] = $data;
                        $iface = $iface->setParams($params)->setAgreement($agreement)->setBillingLink($billingLink);
                        if (_env('MIKBILL_SET_ONT_COORDINATES', false)) {
                            $iface = $this->setCoordinates($iface);
                        }
                        $this->deviceInterfaceStorage->update($iface);
                    }
                } catch (\Exception $e) {
                    $this->log("ERROR", "error when updating - {$e->getMessage()}");
                    $this->log("ERROR", $e->getTraceAsString());
                }
            }
        }
        $this->log("INFO", "Finished updating interfaces");
        $this->log("INFO", "Start clearing old interfaces...");
        foreach ($this->deviceInterfaceStorage->fetchAll(false) as $iface) {
            if (!isset($existedIfaceIds[$iface->getId()]) && isset($iface->getParams()['billing']['mikbill'])) {
                $this->log("INFO", "Removing mikbill data from interface {$iface->getId()}");
                $params = $iface->getParams();
                unset($params['billing']['mikbill']);
                $iface->setDevice(new Device($iface->getDeviceId()));
                $this->deviceInterfaceStorage->update($iface->setParams($params)->setAgreement("")->setBillingLink(""));
            }
        }


        $this->log("", "Finished working!");
    }

    function _getIgnoreInterfacesListByTopology()
    {
        $ifaces = [];
        foreach ($this->links->fetchAll() as $link) {
            if ($link->getDestIface()) {
                $ifaces[$link->getDestIface()->getId()] = $link->getDestIface();
            }
            if ($link->getSrcIface()) {
                $ifaces[$link->getSrcIface()->getId()] = $link->getSrcIface();
            }
        }
        return $ifaces;
    }

    function getAllClients()
    {
        $mikbillServer = trim(_env('MIKBILL_API_ADDR'));
        $authKey = trim(_env('MIKBILL_AUTH_KEY'));
        if (!$mikbillServer) {
            throw new \Exception("Incorrect mikbill web address");
        }
        if (!$authKey) {
            throw new \Exception("Incorrect auth key");
        }
        $client = new Client();
        $res = $client->request('GET', "{$mikbillServer}/api/index/support?key={$authKey}&request=get_user_list");
        if ($res->getStatusCode() != 200) {
            throw new \Exception("Incorrect status code from MikBill API, must be 200, returned - {$res->getStatusCode()}");
        }
        $data = json_decode($res->getBody(), true);
        if ($this->getIsDebug()) {
            print_r($data);
        }
        if (!$data) {
            throw new \Exception("Empty json data from mikbill");
        }
        return $data;
    }

    /**
     * Поиск клиента по идентификатору ОНУ.
     *
     * @param $type string Возможные типы: mac/serial
     * @param $value string Серийник или мак-адрес
     * @return mixed
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function searchClientBy(string $type, string $value)
    {
        $mikbillServer = trim(_env('MIKBILL_API_ADDR'));
        $authKey = trim(_env('MIKBILL_AUTH_KEY'));
        if (!$mikbillServer) {
            throw new \Exception("Incorrect mikbill web address");
        }
        if (!$authKey) {
            throw new \Exception("Incorrect auth key");
        }

        if (!$value) {
            throw new \Exception("Searching value can't be empty");
        }
        $client = new Client();
        $this->logger->withName('mikbill_integration')->debug("Start request {$mikbillServer}/json/index/support?key={$authKey}&request=search&param={$type}&value={$value}");
        $res = $client->request('GET', "{$mikbillServer}/json/index/support?key={$authKey}&request=search&param={$type}&value={$value}", [
            'timeout' => 5, // Response timeout
            'connect_timeout' => 5, // Connection timeout
        ]);
        if ($res->getStatusCode() != 200) {
            $this->logger->withName('mikbill_integration')->error("Incorrect status code from MikBill API, must be 200, returned - {$res->getStatusCode()}");
            throw new \Exception("Incorrect status code from MikBill API, must be 200, returned - {$res->getStatusCode()}");
        }
        $this->logger->withName('mikbill_integration')->debug("Response: {$res->getBody()}");
        $data = json_decode($res->getBody(), true);
        if ($this->getIsDebug()) {
            print_r($data);
        }
        if (!$data) {
            throw new \Exception("Empty json data from mikbill");
        }
        if (isset($data[0]) && isset($data[0][0])) {
            return $data[0][0];
        }
        return null;
    }


    /**
     * @param DeviceInterface $interface
     * @return DeviceInterface
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    function setCoordinates(DeviceInterface $interface)
    {
        if ($interface->getType() !== 'ONU') return $interface;
        if ($interface->getCoordinates()) return $interface;
        if (!isset($interface->getParams()['billing']['mikbill']['address']['full'])) {
            $this->log("ERROR", "address for interface {$interface->getDevice()->getIp()}->{$interface->getName()} not setted");
            return $interface;
        }
        try {
            $client = new Client([
                'headers' => [
                    'User-Agent' => 'Support (https://support.local)',
                    'Accept' => 'application/json',
                ]]);
            $address = $interface->getParams()['billing']['mikbill']['address']['full'];
            $address = trim(str_replace(["с.", "м.", "смт.", "г.", "д.", "пгт.", "пм."], "", $address));
            $response = $client->get("https://nominatim.openstreetmap.org/search?format=json&polygon=1&addressdetails=1&q={$address}");
            sleep(1);
            if ($response->getStatusCode() !== 200) {
                $this->log("ERROR", "error load coordinates info for interface {$interface->getDevice()->getIp()}->{$interface->getName()}");
                $this->log("ERROR", "api response - {$response->getReasonPhrase()}");
                return $interface;
            } else {
                $data = json_decode($response->getBody(), true);
                if (count($data) != 1) {
                    $this->log("ERROR", "api returned " . count($data) . " results. Saving allowed only for 1 result");
                    return $interface;
                }
                $this->log("INFO", "coordinates success setted for interface {$interface->getDevice()->getIp()}->{$interface->getName()}");
                $interface->setCoordinates($data[0]);
                return $interface;
            }
        } catch (\Exception $e) {
            $this->log("ERROR", "catched exception - {$e->getMessage()}");
            return $interface;
        }
    }


}

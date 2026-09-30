<?php


namespace WCC\NoDenyPlus\Controllers;


use Monolog\Logger;
use SwitcherCore\Modules\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Models\Pollers\FdbHistory;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCC\Diagnostic\Controllers\InterfaceDiager;
use WCC\Diagnostic\Exceptions\MacAddressNotFoundInSupport;
use WCC\Diagnostic\Exceptions\UserInfoNotFoundByID;
use WCC\Links\Storage\LinkStorage;

/**
 * Class Controller
 * @package WCC\NoDenyPlus
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
     * @var InterfaceDiager
     */
    protected $interfaceDiager;

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $fdbHistoryStorage;

    /**
     * @var LinkStorage
     */
    protected $links;

    /**
     * @var ComponentInjector
     */
    protected $componentInjector;

    const SEARCH_QUERY_FOR_LEASE_ACTIVE = "SELECT DISTINCT u.name description, m.mac
            FROM users u
            JOIN mac_uid m  on u.id = m.uid
            WHERE m.ip != '' and u.id = ?;
     ";
    const SEARCH_QUERY_FOR_NO_IP = "SELECT DISTINCT u.name description, m.mac
            FROM users u
            JOIN mac_uid m  on u.id = m.uid
            WHERE u.id = ?;
     ";
    const ALL_QUERY = "SELECT DISTINCT u.id, u.name description, contract, m.mac, fio, state
            FROM users u
            JOIN mac_uid m  on u.id = m.uid
            WHERE m.ip != '' and m.mac != '' order by m.time desc;
     ";

    public function __construct(App $app, ComponentInjector $componentInjector, Logger $logger)
    {
        $this->cfg = [
            'dsn' => _env('NODENY_DATABASE_DSN'),
            'username' => _env('NODENY_DATABASE_USERNAME'),
            'password' => _env('NODENY_DATABASE_PASSWORD'),
            'url' => _env('NODENY_URL'),
            'check_topology' => _env('NODENY_COMPARISON_CHECK_TOPOLOGY', false),
            'description_required' => _env('NODENY_COMPARISON_DESCRIPTION_REQUIRED', false) && $componentInjector->isComponentEnabled('links'),
        ];
        $this->componentInjector = $componentInjector;
        if ($this->cfg['check_topology']) {
            $this->links = $app->getContainer()->get(LinkStorage::class);
        }

        parent::__construct($componentInjector, $logger);
    }

    /**
     * @param $userId
     * @return FdbHistory[]
     * @throws UserInfoNotFoundByID
     * @throws SupportException
     */
    public function findMacAddressesInSupport($userId)
    {
        $foundMacAddresses = $this->getUserInfoFromBillingByUserID($userId);
        $macAddreses = [];
        foreach ($foundMacAddresses as $mac_address) {
            $mac = Helper::formatMac($mac_address['mac']);
            $data = $this->fdbHistoryStorage->getByMac($mac, false);
            if ($this->cfg['description_required']) {
                $data = array_filter($data, function (FdbHistory $el) use ($mac_address) {
                    return trim($mac_address['description']) === $el->getInterface()->getDescription();
                });
            }
            if ($this->cfg['check_topology']) {
                $data = array_filter($data, function (FdbHistory $el) {
                    $links = $this->links->getByDeviceInterface($el->getInterface());
                    return count($links) === 0;
                });
            }
            $macAddreses = array_merge($macAddreses, array_values($data));
        }
        if (count($macAddreses) === 0) {
            throw new MacAddressNotFoundInSupport("MAC-address not found in support. Maybe, its new mac-address");
        }
        return $macAddreses;
    }


    /**
     * @param $userId
     * @return array
     * @throws UserInfoNotFoundByID
     * @throws SupportException
     */
    public function getUserInfoFromBillingByUserID($userId)
    {
        $conn = $this->getDbConnection();
        $psth = $conn->prepare(self::SEARCH_QUERY_FOR_LEASE_ACTIVE);
        $psth->execute([$userId]);
        $data = $psth->fetchAll();
        if (count($data) === 0) {
            $psth = $conn->prepare(self::SEARCH_QUERY_FOR_NO_IP);
            $psth->execute([$userId]);
            $data = $psth->fetchAll();
            if (count($data) === 0) {
                throw new UserInfoNotFoundByID("Not found information in billing by users.id");
            }
        }
        try {
            $conn->exec("KILL CONNECTION CONNECTION_ID();");
        } catch (\Exception $e) {}
        unset($conn);
        return $data;
    }

    /**
     * @return \PDO
     * @throws SupportException
     */
    public function getDbConnection()
    {
        foreach (['dsn', 'username', 'password'] as $key) {
            if ($this->cfg[$key] === null) {
                throw new SupportException("NoDeny not configured, parameter '{$key}' is empty");
            }
        }
        $counter = 0;
        $pdo = null;
        $lastErr = null;
        while ($counter < 10) {
            $counter++;
            try {
                $pdo = new \PDO($this->cfg['dsn'], $this->cfg['username'], $this->cfg['password'], [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, //turn on errors in the form of exceptions
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, //make the default fetch be an associative array
                    \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8",
                    \PDO::ATTR_PERSISTENT => true,
                    \PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                $pdo->exec("SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));");
                $pdo->query("SELECT 1")->fetchAll();
                break;
            } catch (\Exception $e) {
                $this->logger->error("error connect to NoDeny database - {$e->getMessage()}");
                $lastErr = $e;
            }
        }
        if(!$pdo) {
            throw $lastErr;
        }
        return $pdo;
    }


    /**
     * @var OutputInterface
     */
    protected $_output;
    function setConsoleOutput(OutputInterface $output) {
        $this->_output = $output;
        return $this;
    }


    function syncAllClients()
    {
        //Filling sets
        $conn = $this->getDbConnection();
        $psth = $conn->prepare(self::ALL_QUERY);
        $psth->execute();
        $this->log('', "Fetched {$psth->rowCount()} mac addresses from billing");
        $billingSet = [];
        foreach ($psth->fetchAll() as $mac) {
            $mac['mac'] = Helper::formatMac($mac['mac']);
            if(!isset($billingSet[$mac['mac']])) {
                $billingSet[$mac['mac']] = $mac;
            } else {
                $this->log('WARN', "Duplicated MAC-address '{$mac['mac']}' found in billing");
            }
        }
        try {
            $conn->exec("KILL CONNECTION CONNECTION_ID();");
        } catch (\Exception $e) {}
        unset($conn);

        $checkedIfacesList = [];
        $topologyLinks = $this->_getIgnoreInterfacesListByTopology();
        foreach ($this->fdbHistoryStorage->fetchAllActive() as $fdb) {
            if(isset($topologyLinks[$fdb->getInterface()->getId()])) {
                $this->log('WARN', "Link {$fdb->getInterface()->getDevice()->getIp()}->{$fdb->getInterface()->getName()} marked as topology link. MAC {$fdb->getMacAddress()} will be ignored!");
                continue;
            }
            if(isset($billingSet[$fdb->getMacAddress()])) {
                if(isset($checkedIfacesList[$fdb->getInterface()->getId()])) {
                    $this->log('WARN', "Duplicate interface ({$fdb->getInterface()->getDevice()->getIp()}->{$fdb->getInterface()->getName()}) with another MAC-address - {$fdb->getMacAddress()}, first catch mac - {$checkedIfacesList[$fdb->getInterface()->getId()]}, ignoring");
                    continue;
                }
                $checkedIfacesList[$fdb->getInterface()->getId()] = $fdb->getMacAddress() ;
                $billingInfo = $billingSet[$fdb->getMacAddress()];
                $billingLink = "{$this->cfg['url']}/cgi-bin/stat.pl?a=user&uid={$billingInfo['id']}";
                $iface = $fdb->getInterface();
                $interfaceMustUpdate = false;
                if($iface->getBillingLink() !== $billingLink) {
                    $interfaceMustUpdate = true;
                    $this->log("", "Interface {$iface->getDevice()->getIp()}->{$iface->getName()} must be update in billingLink. old - '{$iface->getBillingLink()}', new - '{$billingLink}'");
                    $iface->setBillingLink($billingLink);
                }
                if($iface->getAgreement() !== $billingInfo['description']) {
                    $interfaceMustUpdate = true;
                    $this->log("", " {$iface->getDevice()->getIp()}->{$iface->getName()} must be update in contract. old - '{$iface->getAgreement()}', new - '{$billingInfo['description']}'");
                    $iface->setAgreement($billingInfo['description']);
                }
                $params = $iface->getParams();
                if(!isset($params['billing']['nodeny_plus'])) {
                    $interfaceMustUpdate = true;
                    $params['billing']['nodeny_plus'] = [
                      'id' =>   $billingInfo['id'],
                      'name' =>  $billingInfo['fio'],
                      'state' => $billingInfo['state'],
                      'link' =>  $billingLink,
                    ];
                }
                if($params['billing']['nodeny_plus']['id'] !== $billingInfo['id']) {
                    $params['billing']['nodeny_plus']['id'] = $billingInfo['id'];
                    $interfaceMustUpdate = true;
                }
                if($params['billing']['nodeny_plus']['id'] !== $billingInfo['id']) {
                    $params['billing']['nodeny_plus']['name'] = $billingInfo['fio'];
                    $interfaceMustUpdate = true;
                }
                if($params['billing']['nodeny_plus']['id'] !== $billingInfo['id']) {
                    $params['billing']['nodeny_plus']['state'] = $billingInfo['state'];
                    $interfaceMustUpdate = true;
                }
                if($params['billing']['nodeny_plus']['id'] !== $billingInfo['id']) {
                    $params['billing']['nodeny_plus']['link'] = $billingInfo['link'];
                    $interfaceMustUpdate = true;
                }

                if($interfaceMustUpdate) {
                    $iface->setParams($params);
                    $this->deviceInterfaceStorage->update($iface);
                }
            }
        }
        $this->log("", "Finished working!");
    }

    function _getIgnoreInterfacesListByTopology()
    {
        if(!$this->cfg['check_topology']) return [];
        $ifaces = [];
        foreach ($this->links->fetchAll() as $link) {
            if($link->getDestIface()) {
                $ifaces[$link->getDestIface()->getId()] = $link->getDestIface();
            }
            if($link->getSrcIface()) {
                $ifaces[$link->getSrcIface()->getId()] = $link->getSrcIface();
            }
        }
        return  $ifaces;
    }
}

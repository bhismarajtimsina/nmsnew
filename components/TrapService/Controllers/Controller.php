<?php


namespace WCC\TrapService\Controllers;


use GuzzleHttp\Client;
use Monolog\Logger;
use Predis\Response\ResponseInterface;
use SwitcherCore\Exceptions\TrapDeclarationNotFoundByObject;
use SwitcherCore\Modules\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Exceptions\SupportException;
use WCAA\Infrastructure\ComponentInjector;
use WCAA\Infrastructure\Components\AbstractComponentController;
use WCAA\Infrastructure\Paginator\DbPagination;
use WCAA\Infrastructure\Poller\ResponseToPollerWriter;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Models\Pollers\FdbHistory;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCAA\SwitcherCore\Response;
use WCAA\SwitcherCore\SwitcherCore;
use WCC\Diagnostic\Controllers\InterfaceDiager;
use WCC\Diagnostic\Exceptions\MacAddressNotFoundInSupport;
use WCC\Diagnostic\Exceptions\UserInfoNotFoundByID;
use WCC\Links\Storage\LinkStorage;
use WCC\TrapService\Models\TrapFilter;
use WCC\TrapService\Models\TrapLog;
use WCC\TrapService\Storage\TrapLogStorage;

/**
 * Class Controller
 * @package WCC\TrapService
 */
class Controller extends AbstractComponentController
{
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfaceStorage;

    /**
     * @Inject
     * @var TrapLogStorage
     */
    protected $trapLogStorage;

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
     * @var OutputInterface
     */
    protected $_output;

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

    function setConsoleOutput(OutputInterface $output)
    {
        $this->_output = $output;
        return $this;
    }

    function storeModulesDataToCache(Device $device, $modules)
    {
        foreach ($modules as $moduleName => $moduleData) {
            $resp = (new Response())
                ->setModule($moduleName)
                ->setData($moduleData)
                ->setDevice($device)
                ->setStatus(Response::STATUS_SUCCESS)
                ->setUser(App::getInstance()->getSysUser())
                ->setSource('device');
            $this->switcherCore->writeCacheWithReponsesSplittedByInterface($resp);
            $this->switcherCore->getSwcCache()->write($resp);
        }
    }

    /**
     * @param $trapData
     * @return TrapLog
     * @throws \WCAA\Storage\Exceptions\RecordNotFoundException
     * @throws TrapDeclarationNotFoundByObject
     */
    function handleTrap($trapData)
    {
        $device = $this->deviceStorage->getByIp($trapData['host']);
        if(_env('TRAP_SERVICE_CHECK_COMMUNITY', false)) {
            if($trapData['community'] !== $device->getAccess()->getPublicCommunity()
                && $trapData['community'] !== $device->getAccess()->getPrivateCommunity()) {
                throw new SupportException("Received trap with incorrect community");
            }
        }

        $response = [
            'declaration' => [
                'modules' => [],
                'name' => $trapData['object'],
                'object' => $trapData['object'],
                'description' => '',
                'is_interface' => false,
            ],
            'errors' => [],
            'modules' => [],
            'parsed' => [],
            'interface' => null,
        ];
        $excepted = null;
        $interface = null;
        try {
            $response = $this->switcherCore->getCore($device)->trap($trapData['object'], $trapData['data']);

            if ($response['modules']) {
                $this->storeModulesDataToCache($device, $response['modules']);
            }
            if ($response['interface']) {
                $interface = $this->deviceInterfaceStorage->getByDeviceAndKey($device, $response['interface']['id']);
            }
        } catch (TrapDeclarationNotFoundByObject $e) {
            if(_env('TRAP_SERVICE_IGNORE_UNKNOWN_TRAPS', true)) {
                throw $e;
            }
            $excepted = $e;
        } catch (\Exception $e) {
            $excepted = $e;
        }

        $object = $this->trapLogStorage->add(
            (new TrapLog())
                ->setDevice($device)
                ->setObject($response['declaration']['object'])
                ->setInterface($interface)
                ->setDescription($response['declaration']['description'])
                ->setName($response['declaration']['name'])
                ->setModules($response['modules'])
                ->setErrors($response['errors'])
                ->setParsed($response['parsed'])
            ->setRaw($trapData['data'])
        );

        if ($excepted) {
            throw $excepted;
        }
        return $object;
    }

    /**
     * @param TrapFilter $filter
     * @param DbPagination|null $pagination
     * @return TrapLog[]
     */
    function getFilteredActions(TrapFilter $filter, ?DbPagination $pagination)
    {
        return $this->trapLogStorage->getFilteredActions($filter, $pagination);
    }

    function getObjectNames()
    {
        return $this->trapLogStorage->getObjectNames();
    }
}

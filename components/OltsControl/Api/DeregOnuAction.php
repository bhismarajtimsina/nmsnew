<?php


namespace WCC\OltsControl\Api;

use OpenApi\Annotations as OA;


use DI\Annotation\Inject;
use WCAA\Api\Actions\PrivateAction;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Interfaces\CacheInterface;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use WCC\OltsControl\Controllers\Controller;
/**
 * @OA\PUT(
 *   path="/component/olts_control/ont/dereg/{device}/{interface}",
 *   tags={"olts_control"},
 *   security={{"XAuthKey":{}}},
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(type="object", nullable=true, additionalProperties={})
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object", nullable=true, additionalProperties={}),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties={})
 *     )
 *   )
 * )
 */

class DeregOnuAction extends PrivateAction
{
    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var SystemActionsStorage
     */
    protected $systemActionsStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    /**
     * @return Response
     */


    protected function action(): Response
    {
        $dev = $this->deviceStorage->getById($this->request->getAttribute('device'));
        $this->controller = $this->controller->setDevice($dev)->setUser($this->user);
        $interface = $this->controller->parseInterface($this->request->getAttribute('interface'));
        try {
            $data = $this->controller
                ->deregOnu($interface['id']);
            // The ONTs tree/list reads from the switcher-core cache of
            // pon_onts_* modules (a separate SNMP/console poll cache, not
            // this device's stored interface records) — dereg doesn't
            // touch that cache at all, so a from=cache read (a plain page
            // refresh included) kept showing the just-deleted ONT forever,
            // and re-running "Delete all offline" against that same stale
            // list retried dereg on ONTs already gone — confirmed live,
            // producing "Couldn't find the requested... User password:"
            // errors from hammering the console with repeat sessions for
            // nothing. Clearing it here means the next read is a genuine
            // cache miss, which self-heals via one real poll on demand
            // instead of showing stale data indefinitely.
            $this->cache->deleteByRegex('/^SWC:' . preg_quote((string)$dev->getId(), '/') . ':pon_onts_/');
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:dereg_ont',
                SystemAction::STATUS_SUCCESS,
                "Requested dereg ONU {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'interface' => $interface]
            ));
            return $this->respondWithData($data, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:dereg_ont',
                SystemAction::STATUS_FAILED,
                "Requested dereg ONU {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'error' => [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                    'trace' => $e->getTraceAsString(),
                ]]
            ));
            throw $e;
        }
    }
}
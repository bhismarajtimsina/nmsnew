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
 *   path="/component/olts_control/ont/clear-pon/{device}/{interface}",
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

class ClearPonPortAction extends PrivateAction
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
            $data = $this->controller->clearPonPort($interface['id']);
            // Unlike a single dereg, a bulk port clear doesn't tell us
            // which individual ONTs it actually removed (the device's own
            // response is a plain success count, not a per-ONT list) — so
            // there's no specific device_interfaces record to delete here
            // the way DeregOnuAction does. Invalidating this device's
            // ONT-tree cache is the correct equivalent: the next read is a
            // genuine poll that reconciles the real device state, whatever
            // it ends up being, instead of guessing.
            $this->cache->deleteByRegex('/^SWC:' . preg_quote((string)$dev->getId(), '/') . ':pon_onts_/');
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:clear_pon',
                SystemAction::STATUS_SUCCESS,
                "Requested clear PON {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
                ['device' => $dev, 'interface' => $interface, 'result' => $data]
            ));
            return $this->respondWithData($data, $this->controller->getLastMeta());
        } catch (\Exception $e) {
            $this->systemActionsStorage->add(SystemAction::init(
                $this->user,
                'olts:clear_pon',
                SystemAction::STATUS_FAILED,
                "Requested clear PON {$interface['name']} on device {$dev->getName()} ({$dev->getIp()})",
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

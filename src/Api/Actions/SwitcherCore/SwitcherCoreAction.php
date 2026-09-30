<?php


namespace WCAA\Api\Actions\SwitcherCore;


use Monolog\Logger;
use OpenApi\Annotations as OA;
use Slim\Exception\HttpForbiddenException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;
use WCAA\Models\SystemAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCAA\Storage\SystemActionsStorage;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\SwitcherCore\Request;
use WCAA\SwitcherCore\SwitcherCore;

/**
 * @OA\Get(
 *   path="/switcher-core/{storage}/{module}/{device_id}",
 *   tags={"switcher-core"},
 *   security={{"XAuthKey": {}}},
 *   summary="Execute SwitcherCore module (GET)",
 *   description="Executes a module for device from selected source: device, store or cache. For GET request arguments are passed via query params.",
 *   @OA\Parameter(name="storage", in="path", required=true, @OA\Schema(type="string", enum={"device","store","cache"}, example="device")),
 *   @OA\Parameter(name="module", in="path", required=true, @OA\Schema(type="string", example="system")),
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Response(
 *     response=200,
 *     description="Module response",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true),
 *       @OA\Property(property="meta", ref="#/components/schemas/SwitcherCoreMeta")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 * @OA\Post(
 *   path="/switcher-core/{storage}/{module}/{device_id}",
 *   tags={"switcher-core"},
 *   security={{"XAuthKey": {}}},
 *   summary="Execute SwitcherCore module (POST)",
 *   description="Executes a module for device from selected source: device, store or cache. For POST request arguments are passed in JSON body.",
 *   @OA\Parameter(name="storage", in="path", required=true, @OA\Schema(type="string", enum={"device","store","cache"}, example="device")),
 *   @OA\Parameter(name="module", in="path", required=true, @OA\Schema(type="string", example="system")),
 *   @OA\Parameter(name="device_id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Module response",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true),
 *       @OA\Property(property="meta", ref="#/components/schemas/SwitcherCoreMeta")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden")
 * )
 */
class SwitcherCoreAction extends PrivateAction
{
    /**
     * @var SwitcherCore
     */
    protected $swc;

    /**
     * @var SystemActionsStorage
     */
    protected $systemActionStorage;

    /**
     * @var DeviceStorage
     */
    protected $devStorage;

    function __construct(SystemActionsStorage $actions, SwitcherCore $core, Logger $logger, DeviceStorage $devStorage)
    {
        $this->systemActionStorage = $actions;
        $this->swc = $core;
        $this->devStorage = $devStorage;
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $module = $this->request->getAttribute('module');
        $id = $this->request->getAttribute('device_id');
        $storage = $this->request->getAttribute('storage');
        try {
            $arguments = $this->getFormData();
        } catch (\Exception $e) {
            $arguments = $this->request->getQueryParams();
        }


        if(!in_array($module, ['system', 'sys_resources']) && !$this->isAllowedByDemoRules($this->request)) {
            throw new HttpForbiddenException($this->request, "This action forbid in DEMO mode");
        }

        $device = $this->devStorage->getById($id);
        $core = $this->swc->setUser($this->user);
        $response = null;
        $request = (new Request())
            ->setDevice($device)
            ->setModule($module)
            ->setArguments($arguments);
        try {
            switch ($storage) {
                case 'device':
                    $response = $core->fromDevice([$request])->getFirstByModule($module);
                    break;
                case 'store':
                    $response = $core->fromStore([$request])->getFirstByModule($module);
                    break;
                case 'cache':
                    $response = $core->fromCache([$request])->getFirstByModule($module);
                    break;
            }
            if ($this->user->getId() > 0) {
                $this->systemActionStorage->add(
                    (new SystemAction())
                        ->setMeta([
                            'module' => $response->getModule(),
                            'hash' => $response->getHash(),
                            'source' => $response->getSource(),
                            'time' => $response->getTime(),
                        ])
                        ->setAction("swcore:{$module}")
                        ->setMessage("Called module over swcAPI for device {$device->getIp()}")
                        ->setStatus(SystemAction::STATUS_SUCCESS)
                        ->setUser($this->user)
                );
            }
        } catch (\Exception $e) {
            $this->systemActionStorage->add(
                (new SystemAction())
                    ->setMeta(['error' => [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                        'line' => $e->getLine(),
                        'file' => $e->getFile(),
                    ]])
                    ->setAction("swcore:{$module}")
                    ->setMessage("Called module over swcAPI for device {$device->getIp()}")
                    ->setStatus(SystemAction::STATUS_FAILED)
                    ->setUser($this->user)
            );
            throw $e;
        }
        if($response->getError()) {
            throw new SwitcherCoreException($response->getError()['message'], 500);
        }
         return $this->respondWithData(
            $response->getData(),
            [
                'module' => $response->getModule(),
                'hash' => $response->getHash(),
                'source' => $response->getSource(),
                'from_cache' => $response->getSource() !== 'device',
                'time' => $response->getTime(),
            ]
        );
    }
}

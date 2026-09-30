<?php

namespace WCAA\Api\Actions\Devices\Interfaces\Marks;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Models\Devices\Device;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceTagStorage;
use WCAA\Storage\PollerData\OntIdentStorage;

/**
 * @OA\Put(
 *   path="/interface-marks/tagged/list",
 *   tags={"interface-marks"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get tagged interfaces list with pagination",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"filter"},
 *       @OA\Property(property="filter", type="object", additionalProperties=true),
 *       @OA\Property(property="query", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated tagged interfaces",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GetTaggedInterfacesWithPaggingAction extends AbstractTaggedInterfacesAction
{
    /**
     * @Inject
     * @var DeviceInterfaceTagStorage
     */
    protected $deviceTagStorage;

    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $ontIdentStorage;


    protected function action(): Response
    {
        $elements = [];
        $filter = $this->getFormData()['filter'];
        if (isset($filter['devices']) && $filter['devices']) {
            $devices = array_map(function ($d) {
                return $this->deviceStorage->fill(new Device($d['id']));
            }, $filter['devices']);
            foreach ($devices as $device) {
                $elements = array_merge($elements, array_map(function ($e) use ($device) {
                   return $e->setDevice($device);
                }, $this->deviceTagStorage->getTaggedInterfacesByDevice($device)));
            }
        } else {
           $elements = $this->deviceTagStorage->getTagsByInterfaces();
        }

        $data = [];
        $deviceGroupIds = $this->getDeviceGroups();
        foreach ($elements as $tags) {
            /**
             * @var $interface DeviceInterface
             */
            $interface = $tags['interface'];
            if(!in_array($interface->getDevice()->getGroup()->getId(), $deviceGroupIds)) {
                continue;
            }
            $ident = null;
            try {
                $ident = $this->ontIdentStorage->getByInterface($interface)->getAsArray();
            } catch (\Throwable $e) {}
            $elem = $interface->getAsArray();
            $elem['ident'] = $ident;
            $elem['tags'] = $tags['tags'];
            unset($elem['ident']['interface']);
            unset($elem['device']['model']['pollers']);
            unset($elem['device']['model']['params']);
            unset($elem['device']['model']['controller']);
            unset($elem['device']['access']);
            unset($elem['device']['params']);
            unset($elem['device']['pollers']);
            if(isset($filter['value']) && trim($filter['value']) !== '' && strpos(strtolower(json_encode($elem)), strtolower($filter['value'])) === false) {
                continue;
            }
            if(isset($filter['tags']) && $filter['tags']) {
                $filtered = array_filter($elem['tags'], function ($e) use ($filter) {
                   return  in_array($e, $filter['tags']);
                });
                if(count($filtered) === 0) {
                    continue;
                }
            }
            $data[] = $elem;
        }
        $pagination = $this->paginationFromParams($data);
        return $this->respondWithData($pagination['data'], $pagination['meta']);
    }

    function getDeviceGroups()
    {
        $groupIds = [];
        foreach ($this->user->getDeviceGroups() as $group) {
            $groupIds[] = $group->getId();
        }
        return $groupIds;
    }

}

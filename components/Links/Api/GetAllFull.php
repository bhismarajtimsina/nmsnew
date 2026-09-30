<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Interfaces\CacheInterface;
use WCC\Events\Storage\EventsStorage;
use WCC\Links\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/links/view/list",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get links with utilization and filters",
 *   @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", example="15m")),
 *   @OA\Parameter(name="device_id", in="query", required=false, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="utilization", in="query", required=false, @OA\Schema(type="integer", example=85)),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated links list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", ref="#/components/schemas/ListMeta")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 *
 * @OA\Put(
 *   path="/component/links/view/list",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get links with utilization and filters (body params)",
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated links list",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", ref="#/components/schemas/ListMeta")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetAllFull extends BaseApiAction
{
    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;


    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventStorage;


    protected function action(): Response
    {
        try {
            $params = array_merge($this->getFormData(), $this->request->getQueryParams());
        } catch (\Exception $e) {
            $params = $this->request->getQueryParams();
        }
        $period = "15m";


        $highUtilizated = [];
        foreach (array_merge(
                     $this->eventStorage->getNotResolvedBy('high_link_utilization'),
                 ) as $event) {
            $highUtilizated[$event->getLabels()['link_id']] = $event->getCreatedAt();
        }

        if (isset($params['period']) && $params['period']) {
            $period = $params['period'];
        } elseif (isset($params['filter']['period']) && $params['filter']['period']) {
            $period = $params['filter']['period'];
        }


        $data = $this->controller->getAllLinksData($period);

        $data = array_map(function ($link) use ($highUtilizated) {
            if(isset($highUtilizated[$link['id']])) {
                $link['high_utilization'] = true;
                $link['high_utilization_created'] = $highUtilizated[$link['id']];
            } else {
                $link['high_utilization'] = false;
                $link['high_utilization_created'] = null;
            }
            return $link;
        }, $data);

        if (isset($params['utilization']) && $params['utilization'] > 0) {
            $data = array_filter($data, function ($lnk) use ($params) {
                return $lnk['utilization'] && $lnk['utilization'] <= $params['utilization'];
            });
        }

        if (isset($params['filter']['high_utilization']) && $params['filter']['high_utilization']) {
            $data = array_filter($data, function ($lnk) {
                return $lnk['utilization'] && $lnk['utilization'] >= _env('LINKS_UTILIZATION_MAX_PRC_FOR_ALERT', '85');
            });
        }

        if (isset($params['device_id']) && $params['device_id']) {
            $data = array_filter($data, function ($link) use ($params) {
                return $link['src_device']['id'] == $params['device_id'] || $link['dest_device']['id'] == $params['device_id'];
            });
        }

        if (isset($params['filter']['devices']) && $params['filter']['devices']) {
            $filteredDevices = array_map(function ($dv) {
                return $dv['id'];
            }, $params['filter']['devices']);
            $data = array_filter($data, function ($link) use ($filteredDevices) {
                return in_array($link['src_device']['id'], $filteredDevices) || in_array($link['dest_device']['id'], $filteredDevices);
            });
        }
        $data = array_filter($data, function ($link) {
            return isset($link['src_device']['group']['id'])
                && isset($link['dest_device']['group']['id'])
                && in_array($link['src_device']['group']['id'], $this->getDeviceGroupsIdsFromUser())
                && in_array($link['dest_device']['group']['id'], $this->getDeviceGroupsIdsFromUser());
        });


        $pagination = $this->paginationFromParams($data);
        return $this->respondWithData($pagination['data'], $pagination['meta']);
    }


}

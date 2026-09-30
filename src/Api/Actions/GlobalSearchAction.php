<?php

namespace WCAA\Api\Actions;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceTagStorage;
use WCAA\Storage\PollerData\FdbHistoryStorage;
use WCAA\Storage\PollerData\OntIdentStorage;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Get(
 *   path="/portal/search",
 *   tags={"portal"},
 *   security={{"XAuthKey": {}}},
 *   summary="Global portal search",
 *   description="Searches devices and interfaces by agreement, interface description, interface name, ONT identifier, FDB history and device fields. If query starts with # and filter is not passed, searches interfaces by tag.",
 *   @OA\Parameter(name="query", in="query", required=true, description="Search string. Prefix with # to search by interface tag.", @OA\Schema(type="string", example="00:11:22:33:44:55")),
 *   @OA\Parameter(name="filter", in="query", required=false, description="Optional search scope. When omitted, all supported scopes are used.", @OA\Schema(type="string", enum={"agreement", "description", "interface", "ont_ident", "fdb_history", "device"}, example="device")),
 *   @OA\Response(
 *     response=200,
 *     description="Search results",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(
 *           type="object",
 *           @OA\Property(property="type", type="string", enum={"agreement", "description", "interface", "ont_ident", "fdb_history", "device", "tags"}),
 *           @OA\Property(property="data", type="object", description="Matched device, interface, ONT ident, FDB history or tag payload", additionalProperties=true)
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Post(
 *   path="/portal/search",
 *   tags={"portal"},
 *   security={{"XAuthKey": {}}},
 *   summary="Global portal search",
 *   description="Same as GET /portal/search, but parameters are accepted from JSON body.",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"query"},
 *       @OA\Property(property="query", type="string", description="Search string. Prefix with # to search by interface tag.", example="sw-core-01"),
 *       @OA\Property(property="filter", type="string", nullable=true, description="Optional search scope. When omitted, all supported scopes are used.", enum={"agreement", "description", "interface", "ont_ident", "fdb_history", "device"}, example="device")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Search results",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(
 *           type="object",
 *           @OA\Property(property="type", type="string", enum={"agreement", "description", "interface", "ont_ident", "fdb_history", "device", "tags"}),
 *           @OA\Property(property="data", type="object", description="Matched device, interface, ONT ident, FDB history or tag payload", additionalProperties=true)
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class GlobalSearchAction extends PrivateAction
{

    /**
     * @Inject
     * @var FdbHistoryStorage
     */
    protected $pollerFdbHistoryStorage;
    /**
     * @Inject
     * @var OntIdentStorage
     */
    protected $pollerOntIdentStorage;
    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfacesStorage;
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceTagStorage
     */
    protected $deviceInterfaceTagStorage;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $queries = $this->request->getQueryParams();
        $post = $this->getFormData();
        if(isset($queries['query'])) {
            $query = $queries['query'];
        } elseif (isset($post['query'])) {
            $query = $post['query'];
        } else {
            throw new HttpBadRequestException($this->request, "query is required");
        }

        $filter = '';

        if(isset($queries['filter'])) {
            $filter = $queries['filter'];
        } elseif (isset($post['filter'])) {
            $filter = $post['filter'];
        }

        if(strpos($query, "#") === 0 && !$filter) {
            $response = [];
            $searchTag = str_replace("#", "", $query);
            foreach ($this->deviceInterfaceTagStorage->searchInterfacesByTagLike($searchTag) as $founded) {
                $tags = join(",", $founded['tags']);
                /**
                 * @var $iface DeviceInterface
                 */
                $iface = $founded['interface'];
                $response["IFACE:{$iface->getId()}"] = [
                    'type' => 'tags',
                    'data' => [
                        'interface' => $iface->getAsArray(),
                        'tags' => $tags,
                    ]
                ];
            }
            return  $this->respondWithData(array_values($response));
        }

        $response = [];

        //Search by agreement
        if($filter === 'agreement' || !$filter) {
            $data = $this->deviceInterfacesStorage->searchBy($query, 'agreement');
            if ($data) {
                foreach ($data as $d) {
                    if (isset($response["IFACE:{$d->getId()}"])) continue;
                    $response["IFACE:{$d->getId()}"] = [
                        'type' => 'agreement',
                        'data' => $d->getAsArray(),
                    ];
                }
            }
        }

        //Search by interface description
        if($filter === 'description' || !$filter) {
            $data = $this->deviceInterfacesStorage->searchBy($query, 'description');
            if ($data) {
                foreach ($data as $d) {
                    if (isset($response["IFACE:{$d->getId()}"])) continue;
                    $response["IFACE:{$d->getId()}"] = [
                        'type' => 'description',
                        'data' => $d->getAsArray(),
                    ];
                }
            }
        }

        //Search by interface name
        if($filter === 'interface' || !$filter) {
            $data = $this->deviceInterfacesStorage->searchBy($query, 'name');
            if ($data) {
                foreach ($data as $d) {
                    if (isset($response["IFACE:{$d->getId()}"])) continue;
                    $response["IFACE:{$d->getId()}"] = [
                        'type' => 'interface',
                        'data' => $d->getAsArray(),
                    ];
                }
            }
        }

        //Search by ONT ident (MAC address / serial)
        if($filter === 'ont_ident' || !$filter) {
            $data = $this->pollerOntIdentStorage->search($query);
            if ($data) {
                foreach ($data as $d) {
                    if (isset($response["IFACE:{$d->getInterface()->getId()}"])) continue;
                    $response["IFACE:{$d->getInterface()->getId()}"] = [
                        'type' => 'ont_ident',
                        'data' => $d->getAsArray(),
                    ];
                }
            }
        }

        //Search in FDB history
        if($filter === 'fdb_history' || !$filter) {
            $data = $this->pollerFdbHistoryStorage->search($query);
            if ($data) {
                foreach ($data as $d) {
                    if (isset($response["IFACE:{$d->getInterface()->getId()}"])) continue;
                    $response["IFACE:{$d->getInterface()->getId()}"] = [
                        'type' => 'fdb_history',
                        'data' => [
                            'interface' => $d->getInterface()->getAsArray(),
                            'mac_address' => $d->getMacAddress(),
                            'is_active' => $d->getStopAt() === null ? true : false,
                            'vlan_id' => $d->getVlanId(),
                        ]
                    ];
                }
            }
        }

        //Search in devices
        if($filter === 'device' || !$filter) {
            $data = $this->deviceStorage->getBySearchLine($query);
            if ($data) {
                foreach ($data as $d) {
                    $response["DEVICE:{$d->getId()}"] = [
                        'type' => 'device',
                        'data' => $d->getAsArray(),
                    ];
                }
            }
        }

        return  $this->respondWithData(array_values($response));
    }
}

<?php

namespace WCAA\Api\Actions;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\App;
use WCAA\Models\Devices\DeviceInterface;
use WCAA\Storage\Devices\DeviceInterfaceStorage;
use WCAA\Storage\Devices\DeviceStorage;

/**
 * @OA\Get(
 *   path="/portal/nearest-elements",
 *   tags={"portal"},
 *   security={{"XAuthKey": {}}},
 *   summary="Find nearest network elements by latitude and longitude",
 *   description="Returns devices, ONU interfaces by default, and Utels boxes within distance from the passed latitude and longitude. Response items are sorted by distance_m ascending.",
 *   @OA\Parameter(name="lat", in="query", required=true, description="Latitude", @OA\Schema(type="number", format="float", example=50.4501)),
 *   @OA\Parameter(name="lon", in="query", required=true, description="Longitude", @OA\Schema(type="number", format="float", example=30.5234)),
 *   @OA\Parameter(name="distance", in="query", required=true, description="Distance in meters", @OA\Schema(type="number", format="float", example=500)),
 *   @OA\Parameter(name="filter", in="query", required=false, description="Element filter", @OA\Schema(type="string", enum={"device", "interface", "box", "utels_box"}, example="device")),
 *   @OA\Parameter(name="interface_type", in="query", required=false, description="Device interface type, defaults to ONU", @OA\Schema(type="string", default="ONU", example="ONU")),
 *   @OA\Parameter(name="limit", in="query", required=false, description="Max response items, from 1 to 500", @OA\Schema(type="integer", default=100, example=100)),
 *   @OA\Response(
 *     response=200,
 *     description="Nearest network elements",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(
 *           type="object",
 *           @OA\Property(property="type", type="string", enum={"device", "interface", "box"}),
 *           @OA\Property(property="distance_m", type="number", format="float"),
 *           @OA\Property(property="data", type="object", description="Device, interface or box object", additionalProperties=true)
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 * @OA\Post(
 *   path="/portal/nearest-elements",
 *   tags={"portal"},
 *   security={{"XAuthKey": {}}},
 *   summary="Find nearest network elements by latitude and longitude",
 *   description="Same as GET /portal/nearest-elements, but parameters are accepted from JSON body. Response items are sorted by distance_m ascending.",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"lat", "lon", "distance"},
 *       @OA\Property(property="lat", type="number", format="float", description="Latitude", example=50.4501),
 *       @OA\Property(property="lon", type="number", format="float", description="Longitude", example=30.5234),
 *       @OA\Property(property="distance", type="number", format="float", example=500),
 *       @OA\Property(property="filter", type="string", enum={"device", "interface", "box", "utels_box"}, nullable=true, example="interface"),
 *       @OA\Property(property="interface_type", type="string", nullable=true, default="ONU", example="ONU"),
 *       @OA\Property(property="limit", type="integer", nullable=true, default=100, example=100)
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Nearest network elements",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true),
 *       @OA\Property(
 *         property="data",
 *         type="array",
 *         @OA\Items(
 *           type="object",
 *           @OA\Property(property="type", type="string", enum={"device", "interface", "box"}),
 *           @OA\Property(property="distance_m", type="number", format="float"),
 *           @OA\Property(property="data", type="object", description="Device, interface or box object", additionalProperties=true)
 *         )
 *       )
 *     )
 *   ),
 *   @OA\Response(response=400, description="Bad request"),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class NearestElementsAction extends PrivateAction
{
    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var DeviceInterfaceStorage
     */
    protected $deviceInterfacesStorage;

    protected function action(): Response
    {
        $queries = $this->request->getQueryParams();
        $post = $this->getFormData();

        $lat = $this->getInputValue('lat', $queries, $post);
        $lon = $this->getInputValue('lon', $queries, $post);

        $distance = $this->getInputValue('distance', $queries, $post);
        $filter = (string)$this->getInputValue('filter', $queries, $post, '');
        $interfaceType = $this->getInputValue('interface_type', $queries, $post, DeviceInterface::TYPE_ONU);
        $limit = (int)$this->getInputValue('limit', $queries, $post, 100);
        $limit = min(500, max(1, $limit));

        $lat = $this->validateCoordinate('lat', $lat, -90, 90);
        $lon = $this->validateCoordinate('lon', $lon, -180, 180);
        $distance = $this->validatePositiveNumber('distance', $distance);
        $deviceGroupIds = $this->getDeviceGroupsIdsFromUser();

        $found = [];
        if ($filter === '' || $filter === 'device') {
            $found = array_merge($found, $this->deviceStorage->findNearestByCoordinates($lat, $lon, $distance, $deviceGroupIds, $limit));
        }
        if ($filter === '' || $filter === 'interface') {
            $found = array_merge($found, $this->deviceInterfacesStorage->findNearestByCoordinates($lat, $lon, $distance, $deviceGroupIds, $limit, (string)$interfaceType));
        }
        if ($filter === '' || $filter === 'box' || $filter === 'utels_box') {
            $found = array_merge($found, $this->findNearestUtelsBoxes($lat, $lon, $distance, $deviceGroupIds, $limit));
        }
        usort($found, function ($a, $b) {
            return $a['distance_m'] <=> $b['distance_m'];
        });

        $response = [];
        foreach (array_slice($found, 0, $limit) as $element) {
            if ($element['type'] === 'device') {
                $device = $this->deviceStorage->getById($element['id']);
                $response[] = [
                    'type' => 'device',
                    'distance_m' => $element['distance_m'],
                    'data' => $device->getAsArray(),
                ];
                continue;
            }

            if ($element['type'] === 'box') {
                $box = $this->getUtelsBoxById($element['id']);
                if ($box === null) {
                    continue;
                }
                $response[] = [
                    'type' => 'box',
                    'distance_m' => $element['distance_m'],
                    'data' => $box->getAsArray(),
                ];
                continue;
            }

            $iface = $this->deviceInterfacesStorage->getById($element['id']);
            $response[] = [
                'type' => 'interface',
                'distance_m' => $element['distance_m'],
                'data' => $iface->getAsArray(),
            ];
        }

        return $this->respondWithData($response);
    }

    private function findNearestUtelsBoxes(float $lat, float $lon, float $distance, array $deviceGroupIds, int $limit): array
    {
        $storage = $this->getUtelsBoxStorage();
        if ($storage === null || !method_exists($storage, 'findNearestByCoordinates')) {
            return [];
        }
        try {
            return $storage->findNearestByCoordinates($lat, $lon, $distance, $deviceGroupIds, $limit);
        } catch (\Throwable $e) {
            $this->logger->error("Error find nearest utels boxes: " . $e->getMessage());
            return [];
        }
    }

    private function getUtelsBoxById(int $id)
    {
        $storage = $this->getUtelsBoxStorage();
        $boxClass = 'WCC\\UtelsIntegration\\Models\\BoxObject';
        if ($storage === null || !class_exists($boxClass)) {
            return null;
        }
        try {
            return $storage->fill(new $boxClass($id));
        } catch (\Throwable $e) {
            $this->logger->error("Error fill nearest utels box {$id}: " . $e->getMessage());
            return null;
        }
    }

    private function getUtelsBoxStorage()
    {
        $storageClass = 'WCC\\UtelsIntegration\\Storage\\BoxObjectStorage';
        if (!class_exists($storageClass)) {
            return null;
        }
        try {
            return App::getInstance()->getContainer()->get($storageClass);
        } catch (\Throwable $e) {
            $this->logger->error("Error load utels box storage: " . $e->getMessage());
            return null;
        }
    }

    private function getInputValue(string $name, array $queries, array $post, $default = null)
    {
        if (isset($queries[$name])) {
            return $queries[$name];
        }
        if (isset($post[$name])) {
            return $post[$name];
        }
        return $default;
    }

    private function validateCoordinate(string $name, $value, float $min, float $max): float
    {
        if (!is_numeric($value)) {
            throw new HttpBadRequestException($this->request, "{$name} must be numeric");
        }
        $value = (float)$value;
        if ($value < $min || $value > $max) {
            throw new HttpBadRequestException($this->request, "{$name} must be between {$min} and {$max}");
        }
        return $value;
    }

    private function validatePositiveNumber(string $name, $value): float
    {
        if (!is_numeric($value)) {
            throw new HttpBadRequestException($this->request, "{$name} must be numeric");
        }
        $value = (float)$value;
        if ($value <= 0) {
            throw new HttpBadRequestException($this->request, "{$name} must be greater than 0");
        }
        return $value;
    }
}

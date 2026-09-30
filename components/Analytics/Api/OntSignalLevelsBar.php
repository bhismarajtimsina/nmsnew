<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Analytics\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/analytics/bars/ont-levels",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT signal levels bar chart",
 *   @OA\Parameter(name="type", in="query", required=false, @OA\Schema(type="string", example="rx")),
 *   @OA\Response(
 *     response=200,
 *     description="Bar chart data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))
 *   )
 * )
 *
 * @OA\Put(
 *   path="/component/analytics/bars/ont-levels",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT signal levels bar chart (body params)",
 *   @OA\RequestBody(required=false, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Bar chart data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))
 *   )
 * )
 *
 * @OA\Post(
 *   path="/component/analytics/bars/ont-levels",
 *   tags={"analytics"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get ONT signal levels bar chart (body params)",
 *   @OA\RequestBody(required=false, @OA\JsonContent(type="object", additionalProperties=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Bar chart data",
 *     @OA\JsonContent(type="object", @OA\Property(property="data", type="object", additionalProperties=true))
 *   )
 * )
 */

class OntSignalLevelsBar extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;


    protected function action(): Response
    {
        try {
            $form = $this->getFormData();
        } catch (\Exception $e) {
            $form = [];
        }

        $signal = 'rx';
        if(isset($this->request->getQueryParams()['type'])) {
            $signal = $this->request->getQueryParams()['type'];
        } elseif(isset($form['filter']['type'])) {
            $signal = $form['filter']['type'];
        }

        $deviceIds = [];
        if (isset($form['filter']['devices']) && $form['filter']['devices']) {
            foreach ($form['filter']['devices'] as $dv) {
                $deviceIds[] = $dv['id'];
            }
        } else {
            $groups = $this->getDeviceGroupsIdsFromUser();
            foreach ($this->deviceStorage->fetchAll() as $dev) {
                if (in_array($dev->getGroup()->getId(), $groups)) {
                    $deviceIds[] = $dev->getId();
                }
            }
        }

        return $this->respondWithData($this->controller->convertToBarFormat(
            $this->controller->ontCurrentSignalLevelsForBar($deviceIds, $signal),
            [
                'label' => "Count ONTs by signal ($signal)"
            ]
        ));
    }
}

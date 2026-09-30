<?php

namespace WCC\Links\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Infrastructure\EnvParamsEditor;
use WCAA\Interfaces\CacheInterface;
use WCC\Events\Storage\EventsStorage;
use WCC\Links\Controllers\Controller;
/**
 * @OA\Get(
 *   path="/component/links/options/configuration",
 *   tags={"links"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get links configuration options",
 *   @OA\Response(
 *     response=200,
 *     description="Configuration",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/LinksOptionConfiguration")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class GetOptionConfiguration extends BaseApiAction
{


    /**
     * @Inject
     * @var EnvParamsEditor
     */
    protected $params;

    protected function action(): Response
    {

        $periods = [];
        foreach ($this->params->getParams()['links'] as $param) {
            if($param['param_name'] == 'LINKS_UTILIZATION_CALCULATE_PERIOD') {
                $periods  = $param['variants'];
            }
        };

        return $this->respondWithData([
            'max_util_for_alert' => _env('LINKS_UTILIZATION_MAX_PRC_FOR_ALERT'),
            'calc_util_period' => _env('LINKS_UTILIZATION_CALCULATE_PERIOD'),
            'periods' => $periods,
        ]);
    }


}

<?php

namespace WCC\QrGenerator\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
/**
 * @OA\Get(
 *   path="/component/qr-generator/qr-code-base64/{type}/{id}",
 *   tags={"qr-generator"},
 *   security={{"XAuthKey": {}}},
 *   summary="Generate object QR code in base64",
 *   @OA\Parameter(name="type", in="path", required=true, @OA\Schema(type="string", enum={"device","interface","pon-box","user"})),
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="with_label", in="query", required=false, @OA\Schema(type="string", example="1")),
 *   @OA\Parameter(name="with_logo", in="query", required=false, @OA\Schema(type="string", example="1")),
 *   @OA\Parameter(name="size", in="query", required=false, @OA\Schema(type="integer", example=400)),
 *   @OA\Parameter(name="label", in="query", required=false, @OA\Schema(type="string", example="Core switch")),
 *   @OA\Response(
 *     response=200,
 *     description="QR data",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="qr", type="string", example="data:image/png;base64,iVBORw0KGgoAAA..."),
 *         @OA\Property(property="label", type="string", example="Core switch"),
 *         @OA\Property(property="object_type", type="string", example="device"),
 *         @OA\Property(property="object", type="object", additionalProperties=true)
 *       )
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */

class GetQrBase64 extends AbstractGeneratorApi
{
    protected function action(): Response
    {
        $object = $this->getObjectByRequest($this->request);
        $query = $this->request->getQueryParams();
        $result = $this->controller->generateQrByObject(
            $object,
            !isset($query['with_label']) || (isset($query['with_label']) && isPositiveParameter($query['with_label'])),
            !isset($query['with_logo']) || (isset($query['with_logo']) && isPositiveParameter($query['with_logo'])),
            isset($query['size']) && $query['size'] > 60 ? $query['size'] : 400,
            isset($query['label']) && trim($query['label'])  ? $query['label'] : '',
        );
        return $this->respondWithData([
                'qr' => $this->controller->getBase64($result),
                'label' => $this->controller->getLabelByObject($object),
                'object_type' => $this->request->getAttribute('type'),
                'object' => $object->getAsArrayLite(),
            ]
        );
    }

}

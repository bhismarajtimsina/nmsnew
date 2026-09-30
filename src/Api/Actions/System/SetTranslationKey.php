<?php


namespace WCAA\Api\Actions\System;


use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\EnvParamsEditor;
use WCAA\Storage\WebTranslations;

/**
 * @OA\Put(
 *   path="/public/translations",
 *   tags={"system"},
 *   security={{"XAuthKey": {}}},
 *   summary="Create translation key",
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       required={"key"},
 *       @OA\Property(property="key", type="string", example="menu.dashboard")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Operation status",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="boolean", example=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */
class SetTranslationKey extends PrivateAction
{

    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var WebTranslations
     */
    protected $tranlations;

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $form = $this->getFormData();
        if(isset($form['key'])) {
            $this->tranlations->addLangKey($form['key']);
        }
        return $this->respondWithData(true);
    }
}

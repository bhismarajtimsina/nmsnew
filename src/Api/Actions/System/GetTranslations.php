<?php


namespace WCAA\Api\Actions\System;


use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Infrastructure\EnvParamsEditor;
use WCAA\Storage\WebTranslations;

/**
 * @OA\Get(
 *   path="/public/translations",
 *   tags={"system"},
 *   summary="Get translations for all languages",
 *   @OA\Response(
 *     response=200,
 *     description="Translations table",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 * @OA\Get(
 *   path="/public/translations/{lang}",
 *   tags={"system"},
 *   summary="Get translations by language",
 *   @OA\Parameter(name="lang", in="path", required=true, @OA\Schema(type="string", example="en")),
 *   @OA\Response(
 *     response=200,
 *     description="Language translations",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 */
class GetTranslations extends PrivateAction
{
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
        if($this->request->getAttribute('lang')) {
            return $this->respondWithData($this->tranlations->getTranslationTable($this->request->getAttribute('lang')));
        } else {
            return $this->respondWithData($this->tranlations->getAllTranslationTable());
        }
    }
}

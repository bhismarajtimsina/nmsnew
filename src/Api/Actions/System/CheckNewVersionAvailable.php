<?php

namespace WCAA\Api\Actions\System;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\Action;

/**
 * @OA\Get(
 *   path="/public/new-version",
 *   tags={"system"},
 *   summary="Check latest available release",
 *   @OA\Response(
 *     response=200,
 *     description="Version check result",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(
 *         property="data",
 *         type="object",
 *         @OA\Property(property="available", type="boolean", example=true),
 *         @OA\Property(property="current_version", type="string", example="1.2.3"),
 *         @OA\Property(property="new_version", type="string", example="1.3.0"),
 *         @OA\Property(property="detail", type="object", additionalProperties=true),
 *         @OA\Property(
 *           property="changelog",
 *           type="array",
 *           @OA\Items(
 *             type="object",
 *             @OA\Property(property="version", type="string"),
 *             @OA\Property(property="changelog", type="string")
 *           )
 *         )
 *       )
 *     )
 *   )
 * )
 */
class CheckNewVersionAvailable extends Action
{


    protected function action(): Response
    {
        return $this->respondWithData([
            'detail' => [
                'mode' => 'self_hosted',
                'version' => _env('VERSION', '0.0.0'),
            ],
            'available' => false,
            'current_version' => _env('VERSION', '0.0.0'),
            'new_version' => _env('VERSION', '0.0.0'),
            'changelog' => [],
        ]);
    }
}

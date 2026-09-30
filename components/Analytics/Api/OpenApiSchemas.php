<?php

namespace WCC\Analytics\Api;

use OpenApi\Annotations as OA;
/**
 * @OA\Schema(
 *   schema="OntAnalyticsListItem",
 *   type="object",
 *   @OA\Property(
 *     property="optical",
 *     type="object",
 *     @OA\Property(property="rx", type="number", format="float", nullable=true),
 *     @OA\Property(property="olt_rx", type="number", format="float", nullable=true),
 *     @OA\Property(property="temperature", type="number", format="float", nullable=true),
 *     @OA\Property(property="distance", type="number", format="float", nullable=true),
 *     @OA\Property(property="bad_rx", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="bad_olt_rx", type="string", format="date-time", nullable=true)
 *   ),
 *   @OA\Property(property="vendor", type="object", nullable=true, additionalProperties={}),
 *   @OA\Property(property="ident", type="object", nullable=true, additionalProperties={}),
 *   @OA\Property(property="type", type="string", nullable=true),
 *   @OA\Property(
 *     property="interface",
 *     type="object",
 *     @OA\Property(property="created", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="name", type="string", nullable=true),
 *     @OA\Property(property="status", type="string", nullable=true),
 *     @OA\Property(property="status_changed", type="string", format="date-time", nullable=true),
 *     @OA\Property(property="bind_key", type="string", nullable=true),
 *     @OA\Property(property="description", type="string", nullable=true),
 *     @OA\Property(property="params", type="object", nullable=true, additionalProperties={}),
 *     @OA\Property(property="agreement", type="object", nullable=true, additionalProperties={}),
 *     @OA\Property(
 *       property="device",
 *       type="object",
 *       @OA\Property(property="id", type="integer"),
 *       @OA\Property(property="name", type="string", nullable=true),
 *       @OA\Property(property="ip", type="string", nullable=true)
 *     )
 *   )
 * )
 */

class OpenApiSchemas
{
}

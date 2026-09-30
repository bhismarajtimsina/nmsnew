<?php

namespace WCAA\OpenApi\Schemas\System;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="SystemComponent",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=4),
 *   @OA\Property(property="name", type="string", example="Maps"),
 *   @OA\Property(property="key", type="string", example="maps"),
 *   @OA\Property(property="namespace", type="string", nullable=true, example="WCAA\\Components\\Maps"),
 *   @OA\Property(property="enabled", type="boolean", example=true),
 *   @OA\Property(property="built_in", type="boolean", example=false),
 *   @OA\Property(property="configuration", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="initialized_config", type="object", nullable=true, additionalProperties=true)
 * )
 */
final class SystemComponentSchema {}

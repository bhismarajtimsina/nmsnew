<?php

namespace WCAA\OpenApi\Schemas\SwitcherCore;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="SwitcherCoreMeta",
 *   type="object",
 *   @OA\Property(property="module", type="string", example="system"),
 *   @OA\Property(property="hash", type="string", nullable=true),
 *   @OA\Property(property="source", type="string", example="device"),
 *   @OA\Property(property="from_cache", type="boolean", example=false),
 *   @OA\Property(property="time", type="number", format="float", example=0.245)
 * )
 */
final class SwitcherCoreMetaSchema {}

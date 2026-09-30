<?php

namespace WCAA\OpenApi\Schemas\System;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="SwitcherCoreActionLog",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=71),
 *   @OA\Property(property="time", type="string", example="2026-02-25 12:00:00"),
 *   @OA\Property(property="hash", type="string", example="8c7b4a..."),
 *   @OA\Property(property="module", type="string", example="system"),
 *   @OA\Property(property="status", type="string", example="SUCCESS"),
 *   @OA\Property(property="arguments", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="data", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="meta", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="device", ref="#/components/schemas/DeviceShort"),
 *   @OA\Property(property="user", ref="#/components/schemas/UserShort")
 * )
 */
final class SwitcherCoreActionLogSchema {}

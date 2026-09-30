<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DeviceGroup",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=3),
 *   @OA\Property(property="name", type="string", example="Core"),
 *   @OA\Property(property="description", type="string", nullable=true, example="Core devices"),
 *   @OA\Property(property="created_at", type="string", example="2026-02-25 10:30:00")
 * )
 */
final class DeviceGroupSchema {}

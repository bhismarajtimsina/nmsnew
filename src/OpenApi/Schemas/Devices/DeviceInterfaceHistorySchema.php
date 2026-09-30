<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="DeviceInterfaceHistory",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=901),
 *   @OA\Property(property="up", type="string", example="2026-02-25 10:00:00"),
 *   @OA\Property(property="down", type="string", nullable=true, example="2026-02-25 10:20:00"),
 *   @OA\Property(property="down_reason", type="string", nullable=true, example="LOS"),
 *   @OA\Property(property="duration_sec", type="integer", nullable=true, example=1200),
 *   @OA\Property(property="interface", ref="#/components/schemas/DeviceInterfaceShort")
 * )
 */
final class DeviceInterfaceHistorySchema {}

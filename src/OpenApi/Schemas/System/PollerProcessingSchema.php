<?php

namespace WCAA\OpenApi\Schemas\System;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="PollerProcessing",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=45),
 *   @OA\Property(property="poller", type="string", example="interfaces_status"),
 *   @OA\Property(property="start_at", type="string", example="2026-02-25 12:00:00"),
 *   @OA\Property(property="stop_at", type="string", nullable=true, example="2026-02-25 12:00:04"),
 *   @OA\Property(property="status", type="string", example="SUCCESS"),
 *   @OA\Property(property="error", type="object", nullable=true, additionalProperties=true),
 *   @OA\Property(property="device", ref="#/components/schemas/DeviceShort")
 * )
 */
final class PollerProcessingSchema {}

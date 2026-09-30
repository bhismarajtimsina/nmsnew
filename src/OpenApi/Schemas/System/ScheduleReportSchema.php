<?php

namespace WCAA\OpenApi\Schemas\System;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="ScheduleReport",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=91),
 *   @OA\Property(property="start_at", type="string", example="2026-02-25 12:00:00"),
 *   @OA\Property(property="stop_at", type="string", nullable=true, example="2026-02-25 12:00:02"),
 *   @OA\Property(property="output", type="string", nullable=true),
 *   @OA\Property(property="error", type="string", nullable=true),
 *   @OA\Property(property="is_successful", type="boolean", example=true),
 *   @OA\Property(property="schedule", ref="#/components/schemas/Schedule")
 * )
 */
final class ScheduleReportSchema {}

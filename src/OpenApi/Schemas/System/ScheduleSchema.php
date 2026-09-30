<?php

namespace WCAA\OpenApi\Schemas\System;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="Schedule",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=8),
 *   @OA\Property(property="key", type="string", example="cleanup_logs"),
 *   @OA\Property(property="command", type="string", example="php bin/console logs:cleanup"),
 *   @OA\Property(property="crontab", type="string", example="0 0 * * *"),
 *   @OA\Property(property="state", type="string", example="enabled"),
 *   @OA\Property(property="editable", type="boolean", example=true),
 *   @OA\Property(property="latest", type="string", nullable=true, example="2026-02-25 12:00:00"),
 *   @OA\Property(property="created_at", type="string", example="2026-02-20 10:30:00"),
 *   @OA\Property(property="component", ref="#/components/schemas/SystemComponent")
 * )
 */
final class ScheduleSchema {}

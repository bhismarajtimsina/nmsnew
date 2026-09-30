<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="InterfaceMarks",
 *   type="object",
 *   @OA\Property(property="interface", ref="#/components/schemas/DeviceInterface"),
 *   @OA\Property(property="favorite", type="boolean", example=true),
 *   @OA\Property(property="tags", type="array", @OA\Items(type="string"))
 * )
 */
final class InterfaceMarksSchema {}

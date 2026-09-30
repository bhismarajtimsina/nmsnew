<?php

namespace WCAA\OpenApi\Schemas\Devices;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="InterfaceWithTags",
 *   type="object",
 *   @OA\Property(property="interface", ref="#/components/schemas/DeviceInterface"),
 *   @OA\Property(property="tags", type="array", @OA\Items(type="string"))
 * )
 */
final class InterfaceWithTagsSchema {}

<?php

namespace WCAA\OpenApi\Schemas\User;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="UserShort",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="login", type="string", example="admin"),
 *   @OA\Property(property="name", type="string", example="Administrator")
 * )
 */
final class UserShortSchema {}

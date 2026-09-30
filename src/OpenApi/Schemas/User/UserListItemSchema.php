<?php

namespace WCAA\OpenApi\Schemas\User;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *   schema="UserListItem",
 *   type="object",
 *   @OA\Property(property="id", type="integer", example=1),
 *   @OA\Property(property="name", type="string", example="Administrator")
 * )
 */
final class UserListItemSchema {}

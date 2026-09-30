<?php

namespace WCAA\OpenApi;

use OpenApi\Annotations as OA;

if (!defined('WCAA_OPENAPI_VERSION')) {
    require_once __DIR__ . '/bootstrap.php';
}

/**
 * @OA\OpenApi(
 *    @OA\Info(
 *      title="Support DMS API",
 *      version=WCAA_OPENAPI_VERSION
 *    ),
 *    @OA\Server(
 *      url="/api/v1",
 *      description="Local server"
 *    ),
 *
 *  )
 * @OA\SecurityScheme(
 * securityScheme="XAuthKey",
 * type="apiKey",
 * in="header",
 * name="x-auth-key",
 * description="Auth key in header x-auth-key"
 * )
 */
final class OpenApi {}

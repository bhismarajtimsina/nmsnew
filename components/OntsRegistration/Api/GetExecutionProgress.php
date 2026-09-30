<?php


namespace WCC\OntsRegistration\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Interfaces\CacheInterface;

/**
 * Lightweight polling endpoint for a registration execution already in
 * flight — see the Macros component's own GetExecutionProgress.php (same
 * mechanism, same cache key shape, just gated by this component's own
 * unregistered_onts permission instead of macros_execute, since a user
 * able to register an ONT isn't necessarily also able to run generic
 * macros). Read-only, always safe to call even for an execution_id that
 * was never started or has since expired from cache.
 *
 * @OA\Get(
 *   path="/component/onts_registration/execute/progress/{id}",
 *   tags={"onts-registration"},
 *   security={{"XAuthKey":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(
 *     response=200,
 *     description="OK",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="data", type="object",
 *         @OA\Property(property="commands", type="array", @OA\Items(type="object")),
 *         @OA\Property(property="done", type="integer"),
 *         @OA\Property(property="total", type="integer")
 *       )
 *     )
 *   )
 * )
 */
class GetExecutionProgress extends PrivateAction
{
    /**
     * @Inject
     * @var CacheInterface
     */
    protected $cache;

    protected function action(): Response
    {
        $id = (string)$this->request->getAttribute('id');
        $data = null;
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) {
            $data = $this->cache->get("macro_progress:{$id}");
        }
        return $this->respondWithData($data ?: ['commands' => [], 'done' => 0, 'total' => 0]);
    }
}

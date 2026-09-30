<?php


namespace WCC\Macros\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Interfaces\CacheInterface;

/**
 * Lightweight polling endpoint for a macro execution already in flight —
 * see AbstractModule::multiRawConsoleCommandRun()'s own comment for the
 * full mechanism. Read-only, always safe to call even for an execution_id
 * that was never started (returns done:0/total:0) or has since finished
 * and expired from cache (same). Gated by the same macros_execute
 * permission as the execute call itself — this doesn't reveal anything a
 * user without that permission couldn't already trigger themselves.
 *
 * @OA\Get(
 *   path="/component/macros/execute/progress/{id}",
 *   tags={"macros"},
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

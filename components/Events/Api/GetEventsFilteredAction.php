<?php

namespace WCC\Events\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\Paginator\DbPagination;
use WCAA\Infrastructure\Paginator\Paginator;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Controllers\Controller;
use WCC\Events\Models\EventFilter;
/**
 * @OA\Get(
 *   path="/component/events",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get events by filters",
 *   @OA\Parameter(name="device_id", in="query", required=false, @OA\Schema(type="integer", example=101)),
 *   @OA\Parameter(name="severity", in="query", required=false, @OA\Schema(type="string", example="warning")),
 *   @OA\Parameter(name="name", in="query", required=false, @OA\Schema(type="string", example="high_link_utilization")),
 *   @OA\Parameter(name="not_resolved", in="query", required=false, @OA\Schema(type="boolean", example=true)),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated events",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 *
 * @OA\Post(
 *   path="/component/events",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Get events by filters (request body)",
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Paginated events",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="object", additionalProperties=true)),
 *       @OA\Property(property="meta", type="object", additionalProperties=true)
 *     )
 *   )
 * )
 */

class GetEventsFilteredAction extends PrivateAction
{
        /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    protected function action(): Response
    {
       // The route answers both GET and POST. getFormData() only throws on
       // malformed JSON — on a GET, where there is no body at all, it returns
       // an empty array, so the old catch never ran and every query-string
       // filter on this endpoint was silently ignored. name, severity and
       // device_id have never worked over GET. The SPA posts a body, which is
       // why nobody noticed.
       try {
           $form = $this->getFormData();
       } catch (\Throwable $e) {
           $form = [];
       }
       if (!$form) {
           $form = $this->request->getQueryParams();
       }
       $filter = $this->createEventFilter($form);
       $paginator = new DbPagination(Paginator::initFromRequest($this->request));
       $resp = [];
       $annotations = $this->controller->getAnnotationsMap();
       foreach ($this->controller->getEventsByFilter($filter, $paginator) as $r) {
           $dt = $r->getAsArrayLite();
           if($dt['device']) {
               $dt['device'] = [
                  'id' => $dt['device']['id'],
                  'name' => $dt['device']['name'],
                  'ip' => $dt['device']['ip'],
                  'model' => [
                      'id' => $dt['device']['model']['id'],
                      'name' => $dt['device']['model']['name'],
                  ],
               ];
           }
           if($dt['creator']) {
               $dt['creator'] = [
                  'id' => $dt['creator']['id'],
                  'name' => $dt['creator']['name'],
                  'login' => $dt['creator']['login'],
               ];
           }
           $dt['annotation'] = $annotations[$dt['name']] ?? null;
           $resp[] = $dt;
       }
       return  $this->respondWithData($resp,$paginator->getPaginator()->getMeta());
    }

    protected function createEventFilter($form) {
        $filter = new EventFilter();
        $filter->setUser($this->user);
        if(isset($form['device_id']) && $form['device_id']) {
            $filter->setDevice($this->deviceStorage->getById($form['device_id']));
        }
        if(isset($form['device']['id']) && $form['device']['id']) {
            $filter->setDevice($this->deviceStorage->getById($form['device']['id']));
        }
        if(isset($form['severity'])) {
            $filter->setSeverity($form['severity']);
        }
        if(isset($form['key'])) {
            $filter->setKey($form['key']);
        }
        if(isset($form['name'])) {
            $filter->setName($form['name']);
        }
        if(isset($form['names'])) {
            if(!is_array($form['names'])) throw new HttpBadRequestException($this->request, "names must be as array");
            $filter->setNames($form['names']);
        }
        // focus_for=isp&focus=primary,secondary — the console's own view of the
        // event list. Without it every console gets the same list, and the
        // ISP's transport faults sit under hundreds of subscriber alarms.
        if (isset($form['focus_for']) && $form['focus_for']) {
            $tiers = $form['focus'] ?? 'primary';
            if (!is_array($tiers)) {
                $tiers = array_filter(array_map('trim', explode(',', (string)$tiers)));
            }
            $filter->setFocus((string)$form['focus_for'], $tiers);
        }
        if(isset($form['not_resolved']) && $form['not_resolved']) {
            $filter->setOnlyNotResolved(true);
        }
        if(isset($form['start']) && $form['start']) {
            $filter->setStart($form['start']);
        }
        if(isset($form['end']) && $form['end']) {
            $form['end'] = date('Y-m-d', strtotime($form['end'])).' 23:59:59';
            $filter->setStop($form['end']);
        }
        if(isset($form['labels'])) {
            if(!is_array($form['labels'])) {
                throw new HttpBadRequestException($this->request, "Labels must be as hash map");
            }
            $filter->setLabels($form['labels']);
        }
        return $filter;
    }
}

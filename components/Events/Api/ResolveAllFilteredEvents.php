<?php

namespace WCC\Events\Api;

use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\Events\EventObserverStorage;
use WCAA\Infrastructure\Paginator\DbPagination;
use WCAA\Infrastructure\Paginator\Paginator;
use WCAA\Storage\Devices\DeviceStorage;
use WCC\Events\Controllers\Controller;
use WCC\Events\Models\EventFilter;
use WCC\Events\Storage\EventsStorage;
/**
 * @OA\Put(
 *   path="/component/events/resolve-all",
 *   tags={"events"},
 *   security={{"XAuthKey": {}}},
 *   summary="Resolve all events by filter",
 *   @OA\RequestBody(
 *     required=false,
 *     @OA\JsonContent(type="object", additionalProperties=true)
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Resolved event IDs",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", type="array", @OA\Items(type="integer", example=1234)),
 *       @OA\Property(property="meta", type="object", additionalProperties=true)
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized")
 * )
 */

class ResolveAllFilteredEvents extends PrivateAction
{
        protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var Controller
     */
    protected $controller;

    /**
     * @Inject
     * @var EventsStorage
     */
    protected $eventStorage;

    /**
     * @Inject
     * @var DeviceStorage
     */
    protected $deviceStorage;

    /**
     * @Inject
     * @var EventObserverStorage
     */
    protected $observer;

    protected function action(): Response
    {
       try {
           $form = $this->getFormData();
       } catch (\Throwable $e) {
           $form = $this->request->getQueryParams();
       }
        $filter = $this->createEventFilter($form);
       $paginator = new DbPagination(Paginator::initFromRequest($this->request));
       $paginator->getPaginator()->setPerPage(10000);
       $paginator->getPaginator()->setPageNumber(1);
       $resp = [];
       foreach ($this->controller->getEventsByFilter($filter, $paginator) as $r) {
           if($r->getResolvedBy()) continue;
           $event = $this->eventStorage->update($r->setResolvedBy($this->user)->setResolvedAt(date("Y-m-d H:i:s")));
           $this->observer->notify("event:resolved", $event);
           $resp[] = $r->getId();
       }
       return  $this->respondWithData($resp,$paginator->getPaginator()->getMeta());
    }

    protected function createEventFilter($form) {
        $filter = new EventFilter();
        $filter->setUser($this->user);
        if(isset($form['device_id'])) {
            $filter->setDevice($this->deviceStorage->getById($form['device_id']));
        }
        if(isset($form['device']['id'])) {
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
        if(isset($form['not_resolved']) && $form['not_resolved']) {
            $filter->setOnlyNotResolved(true);
        }
        if(isset($form['start']) && $form['start']) {
            $filter->setStart($form['start']);
        }
        if(isset($form['end']) && $form['end']) {
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

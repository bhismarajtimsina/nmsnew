<?php

namespace WCAA\Api\Actions\System\Schedule;

use Cron\Job\ShellJob;
use Cron\Validator\CrontabValidator;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use WCAA\Api\Actions\PrivateAction;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\System\ScheduleStorage;

/**
 * @OA\Put(
 *   path="/system/schedule/{id}",
 *   tags={"system-schedule"},
 *   security={{"XAuthKey": {}}},
 *   summary="Update schedule",
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", example=8)),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="crontab", type="string", nullable=true, example="0 0 * * *"),
 *       @OA\Property(property="command", type="string", nullable=true),
 *       @OA\Property(property="state", type="string", nullable=true, example="enabled")
 *     )
 *   ),
 *   @OA\Response(
 *     response=200,
 *     description="Updated schedule",
 *     @OA\JsonContent(
 *       type="object",
 *       @OA\Property(property="statusCode", type="integer", example=200),
 *       @OA\Property(property="data", ref="#/components/schemas/Schedule")
 *     )
 *   ),
 *   @OA\Response(response=401, description="Unauthorized"),
 *   @OA\Response(response=403, description="Forbidden"),
 *   @OA\Response(response=404, description="Not Found")
 * )
 */
class UpdateScheduleAction extends PrivateAction
{
    protected $forbiddenInDemo = true;

    /**
     * @Inject
     * @var ScheduleStorage
     */
    protected $scheduleStorage;

    protected function action(): Response
    {
        $crontab = $this->scheduleStorage->getById($this->request->getAttribute('id'));
        if($crontab === null) {
            throw new HttpNotFoundException($this->request, "Schedule with id={$this->request->getAttribute('id')} not found");
        }
        $form = $this->getFormData();
        if(!$crontab->isEditable()) {
            throw new HttpForbiddenException($this->request, "Not allow for edit current schedule");
        }
        if(isset($form['crontab'])) {
            (new CrontabValidator())->validate($form['crontab']);
            $crontab->setCrontab($form['crontab']);
        }
        if(isset($form['command'])) {
            $crontab->setCommand($form['command']);
        }
        if(isset($form['state'])) {
            $crontab->setState($form['state']);
        }
        $this->scheduleStorage->update($crontab);
        return $this->respondWithData($crontab->getAsArray());
    }

}

<?php
declare(strict_types=1);

namespace WCAA\Api\Actions;

use Monolog\Logger;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Infrastructure\Paginator\DataPagination;
use WCAA\Infrastructure\Paginator\Paginator;
use WCAA\Storage\Exceptions\RecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

abstract class Action
{
    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var Response
     */
    protected $response;

    /**
     * @var array
     */
    protected $args;


    /**
     * @param Logger $logger
     */
    public function __construct(Logger $logger )
    {
        $this->logger = $logger;
    }

    /**
     * @param Request  $request
     * @param Response $response
     * @param array    $args
     * @return Response
     * @throws HttpNotFoundException
     * @throws HttpBadRequestException
     */
    public function __invoke(Request $request, Response $response, $args): Response
    {
        $this->request = $request;
        $this->response = $response;
        $this->args = $args;

        try {
            return $this->action();
        } catch (DomainRecordNotFoundException $e) {
            throw new HttpNotFoundException($this->request, $e->getMessage());
        } catch (RecordNotFoundException $e) {
            throw new HttpNotFoundException($this->request,$e->getMessage(), $e);
        }
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    abstract protected function action(): Response;

    /**
     * @param bool $associative
     * @return mixed
     * @throws HttpBadRequestException
     */
    protected function getFormData($associative = true)
    {
        $this->request->getBody()->rewind();
        $content = $this->request->getBody()->getContents();
        if ($content === '') {
            return $associative ? [] : (object)[];
        }
        $input = json_decode($content, $associative);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpBadRequestException($this->request, 'Malformed JSON input.');
        }
        if ($input === null) {
            return $associative ? [] : (object)[];
        }
        return $input;
    }

    /**
     * @param  string $name
     * @return mixed
     * @throws HttpBadRequestException
     */
    protected function resolveArg(string $name)
    {
        if (!isset($this->args[$name])) {
            throw new HttpBadRequestException($this->request, "Could not resolve argument `{$name}`.");
        }

        return $this->args[$name];
    }

    /**
     * @param  array|object|null $data
     * @return Response
     */
    protected function respondWithData($data = null, $meta = null): Response
    {
        $payload = new ActionPayload(200, $data, $meta);
        return $this->respond($payload);
    }

    /**
     * @param ActionPayload $payload
     * @return Response
     */
    protected function respond(ActionPayload $payload): Response
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT  | JSON_UNESCAPED_UNICODE );
        $this->response->getBody()->write($json);
        return $this->response->withHeader('Content-Type', 'application/json');
    }

    protected function replaceQueryParams(&$form, $strict = true) {
        $query = $this->request->getQueryParams();
        if($strict) {
            foreach ($form as $key=>$_) {
                if(isset($query[$key])) {
                    $form[$key] = $query[$key];
                }
            }
        } else {
            foreach ($query as $key => $value) {
                $form[$key] =  is_string($value) ? trim($value) : $value;
            }
        }
        return $this;
    }
    protected function fillDefaultKeys($defaultKeys, &$form) {
        foreach ($defaultKeys as $key => $val) {
            if(!isset($form[$key])) {
                $form[$key] = $val;
            }
        }
        return $this;
    }


    protected function paginationFromParams($array) {
        $pagination = (new DataPagination(Paginator::initFromRequest($this->request)))
            ->setData($array);
        return [
            'data' => $pagination->getPaginator()->getData(),
            'meta' => $pagination->getPaginator()->getMeta(),
            ];
    }



}

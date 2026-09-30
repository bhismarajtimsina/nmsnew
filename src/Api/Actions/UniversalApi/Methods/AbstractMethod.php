<?php
declare(strict_types=1);

namespace WCAA\Api\Actions\UniversalApi\Methods;

use Monolog\Logger;
use WCAA\Api\DomainException\DomainRecordNotFoundException;
use WCAA\Storage\Exceptions\RecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

abstract class AbstractMethod
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
     * @param Request $request
     * @param Response $response
     * @param $args
     * @return mixed
     */
    public function __invoke(Request $request, Response $response, $args)
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


    abstract protected function action();

    /**
     * @param bool $associative
     * @return mixed
     * @throws HttpBadRequestException
     */
    protected function getFormData($associative = true)
    {
        $this->request->getBody()->rewind();
        $content = $this->request->getBody()->getContents();
        $input = json_decode($content, $associative);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpBadRequestException($this->request, 'Malformed JSON input.');
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
}

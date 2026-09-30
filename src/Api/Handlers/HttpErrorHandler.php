<?php
declare(strict_types=1);

namespace WCAA\Api\Handlers;

use Monolog\Logger;
use WCAA\Api\Actions\ActionError;
use WCAA\Api\Actions\ActionPayload;
use Exception;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Throwable;
use WCAA\Exceptions\SupportException;
use WCAA\Exceptions\SwitcherCore\SnmpException as WcaaSnmpException;
use WCAA\Exceptions\SwitcherCore\SwitcherCoreException;

class HttpErrorHandler extends SlimErrorHandler
{
    /**
     * @inheritdoc
     */
    protected  $displayStackTrace = false;


    public function setDisplayStackTrace($display) {
        $this->displayStackTrace = $display;
        return $this;
    }
    public function setLogger(Logger $logger) {
        $this->logger = $logger;
        return $this;
    }

    protected function respond(): Response
    {
        $exception = $this->exception;
        $statusCode = 500;
        $error = new ActionError(
            ActionError::SERVER_ERROR,
            'An internal error has occurred while processing your request.',
            "{$exception->getFile()}:{$exception->getLine()}",
        );

        if ($exception instanceof HttpException) {
            $statusCode = $exception->getCode();
            $error->setDescription($exception->getMessage());

            if ($exception instanceof HttpNotFoundException) {
                $error->setType(ActionError::RESOURCE_NOT_FOUND);
            } elseif ($exception instanceof HttpMethodNotAllowedException) {
                $error->setType(ActionError::NOT_ALLOWED);
            } elseif ($exception instanceof HttpUnauthorizedException) {
                $error->setType(ActionError::UNAUTHENTICATED);
            } elseif ($exception instanceof HttpForbiddenException) {
                $error->setType(ActionError::INSUFFICIENT_PRIVILEGES);
            } elseif ($exception instanceof HttpBadRequestException) {
                $error->setType(ActionError::BAD_REQUEST);
            } elseif ($exception instanceof HttpNotImplementedException) {
                $error->setType(ActionError::NOT_IMPLEMENTED);
            }
        } elseif (
            $exception instanceof WcaaSnmpException
            || $exception instanceof SwitcherCoreException
            || $exception instanceof \SNMPException
        ) {
            $error->setType(
                $exception instanceof \SNMPException
                    ? 'SWC_SNMP_EXCEPTION'
                    : $exception->getType()
            );
            $error->setDescription($exception->getMessage());
            $statusCode = 502;
        } elseif ($exception instanceof SupportException) {
            $error->setType($exception->getType());
            $statusCode = $exception->getCode();
        }
        if (
            !($exception instanceof HttpException)
            && ($exception instanceof Exception || $exception instanceof Throwable)
            && $this->displayErrorDetails
        ) {
            $error->setDescription($exception->getMessage());
            if($this->displayStackTrace) $error->setStackTrace($exception->getTraceAsString());
        }

        $payload = new ActionPayload($statusCode, null, null, $error);
        $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT);
        if(!$encodedPayload) {
            $encodedPayload = '
{
    "statusCode": 500,
    "meta": null,
    "error": {
        "type": "API_CRITICAL_ERROR",
        "description": "Api critical error",
        "stackTrace": null
    }
}
';
        }
        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write($encodedPayload);

        $response = $response->withHeader('Access-Control-Allow-Origin', '*');
        $response = $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE');
        $response = $response->withHeader('Access-Control-Allow-Headers', 'x-auth-key');

        // Optional: Allow Ajax CORS requests with Authorization header
        $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        return $response->withHeader('Content-Type', 'application/json');
    }

}

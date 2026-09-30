<?php
declare(strict_types=1);

namespace WCAA\Api\Actions;

use JsonSerializable;

class ActionError implements JsonSerializable
{
    public const BAD_REQUEST = 'BAD_REQUEST';
    public const INSUFFICIENT_PRIVILEGES = 'INSUFFICIENT_PRIVILEGES';
    public const NOT_ALLOWED = 'NOT_ALLOWED';
    public const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    public const SERVER_ERROR = 'SERVER_ERROR';
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const VERIFICATION_ERROR = 'VERIFICATION_ERROR';

    /**
     * @var string
     */
    private $type;

    /**
     * @var string
     */
    private $description;

    /**
     * @var string
     */
    protected $line;

    private $stackTrace;

    /**
     * @param string        $type
     * @param string|null   $description
     */
    public function __construct(string $type, ?string $description, ?string $line)
    {
        $this->type = $type;
        $this->description = $description;
        $this->line = $line;
    }

    /**
     * @return string
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return self
     */
    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @param string $type
     * @return self
     */
    public function setStackTrace($stackTrace): self
    {
        $this->stackTrace = $stackTrace;
        return $this;
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @param string|null $description
     * @return self
     */
    public function setDescription(?string $description = null): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getLine(): ?string
    {
        return $this->line;
    }

    /**
     * @param string|null $line
     * @return ActionError
     */
    public function setLine(?string $line): ActionError
    {
        $this->line = $line;
        return $this;
    }



    /**
     * @return array
     */
    public function jsonSerialize()
    {
        return [
            'type' => $this->type,
            'line' => $this->line,
            'description' => $this->description,
            'stackTrace' => $this->stackTrace,
        ];
    }
}

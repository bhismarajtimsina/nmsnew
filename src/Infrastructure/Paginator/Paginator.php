<?php

namespace WCAA\Infrastructure\Paginator;

use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Psr7\Request;

class Paginator
{

    protected $perPage = 50;
    protected $orderBy = '';
    protected $ascending = false;
    protected $query = '';
    protected $pageNumber = 1;

    /**
     * @var ?SourceInterface
     */
    protected $source = null;

    public static function init() {
        $self = new self();

        return $self;
    }

    public function setSource(SourceInterface $source) {
        $this->source = $source;
    }
    protected static function getFormData($request)
    {
        $request->getBody()->rewind();
        $content = $request->getBody()->getContents();
        $input = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpBadRequestException($request, 'Malformed JSON input.');
        }

        return $input;
    }


    public static function initFromRequest(ServerRequestInterface $request, $readBody = true)
    {

        $params = $request->getQueryParams();
        if($readBody) {
            try {
                $params = self::getFormData($request);
            } catch (\Exception $e) {
            }
        }
        $self = new self();
        $self->pageNumber = isset($params['page']) ? $params['page'] : 1;
        $self->query = isset($params['query']) ? $params['query'] : '';
        $self->perPage = isset($params['limit']) ? $params['limit'] : 50;
        if (isset($params['orderBy']) && is_string($params['orderBy'])) {
            //Stored raw: consumers apply their own context-appropriate validation.
            //DbPagination validates it as a SQL identifier, DataPagination uses it as an array key.
            $self->orderBy = $params['orderBy'];
        }
        $self->ascending = isset($params['ascending']) && $params['ascending'];
        return $self;
    }
    public static function initFromFilterArray($data)
    {

        $params = $data;
        $self = new self();
        $self->pageNumber = isset($params['page']) ? $params['page'] : 1;
        $self->query = isset($params['query']) ? $params['query'] : '';
        $self->perPage = isset($params['limit']) ? $params['limit'] : 50;
        if (isset($params['orderBy']) && is_string($params['orderBy'])) {
            //See initFromRequest() - validation happens in the consumer, per context.
            $self->orderBy = $params['orderBy'];
        }
        $self->ascending = isset($params['ascending']) && $params['ascending'];
        return $self;
    }


    function getOffset() {
        return (int)($this->getPerPage() * ($this->getPageNumber() - 1));
    }
    function getLimit() {
        return (int)$this->getPerPage();
    }

    /**
     * @return int
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * @param int $perPage
     * @return Paginator
     */
    public function setPerPage(int $perPage): Paginator
    {
        $this->perPage = $perPage;
        return $this;
    }

    /**
     * @return string
     */
    public function getOrderBy(): string
    {
        return $this->orderBy;
    }

    /**
     * @param string $orderBy
     * @return Paginator
     */
    public function setOrderBy(string $orderBy): Paginator
    {
        $this->orderBy = $orderBy;
        return $this;
    }

    /**
     * @return bool
     */
    public function isAscending(): bool
    {
        return $this->ascending;
    }

    /**
     * @param bool $ascending
     * @return Paginator
     */
    public function setAscending(bool $ascending): Paginator
    {
        $this->ascending = $ascending;
        return $this;
    }

    /**
     * @return string|array
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * @param string|array $query
     * @return Paginator
     */
    public function setQuery($query): Paginator
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @return int
     */
    public function getPageNumber(): int
    {
        return $this->pageNumber;
    }

    /**
     * @param int $pageNumber
     * @return Paginator
     */
    public function setPageNumber(int $pageNumber): Paginator
    {
        $this->pageNumber = $pageNumber;
        return $this;
    }

    public function getData() {
        return $this->source->getRecords();
    }

    function getTotalPages() {
        $maxPages = 1;
        if($this->getTotalRecords() > 0)
            $maxPages = ceil((($this->getTotalRecords() - 1) / $this->perPage));
        return $maxPages ==  0 ? 1 : $maxPages;
    }

    public function getTotalRecords() {
        return $this->source->getTotalRecords();
    }

    public function getMeta() {
        return [
            'page' => $this->pageNumber,
            'total_pages' => $this->getTotalPages(),
            'total_records' => $this->getTotalRecords(),
            'limit' => $this->perPage,
            'query' => $this->query,
            'ascending' => $this->ascending,
            'order_by' => $this->orderBy,
        ] ;
    }
}
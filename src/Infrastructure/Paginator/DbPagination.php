<?php

namespace WCAA\Infrastructure\Paginator;

class DbPagination implements SourceInterface
{
    /**
     * @var Paginator
     */
    protected $paginator;
    protected $records;
    protected $totalRecords;


    function __construct(Paginator $pg) {
        $this->paginator = $pg;
        $this->paginator->setSource($this);
    }

    function getRecords()
    {
        return $this->records;
    }

    function getTotalRecords(): int
    {
        return $this->totalRecords;
    }


    /**
     * @param mixed $totalRecords
     * @return DbPagination
     */
    public function setTotalRecords($totalRecords)
    {
        $this->totalRecords = $totalRecords;
        return $this;
    }



    /**
     * @return Paginator
     */
    public function getPaginator(): Paginator
    {
        return $this->paginator;
    }

    /**
     * @param Paginator $paginator
     * @return DbPagination
     */
    public function setPaginator(Paginator $paginator): DbPagination
    {
        $this->paginator = $paginator;
        $this->paginator->setSource($this);
        return $this;
    }

    function getLimitsQuery() {
        $limit = (int)($this->paginator->getPerPage() * ($this->paginator->getPageNumber() - 1));
        $offset = (int)$this->paginator->getPerPage();
        return " LIMIT $limit, $offset";
    }


    function getOrderQuery() {
        //ORDER BY takes an identifier, which cannot be bound as a parameter - it is
        //validated against a strict identifier shape instead. Unrecognised input falls
        //back to the default ordering rather than reaching the query.
        $orderBy = SqlIdentifier::sanitize($this->paginator->getOrderBy());
        if($orderBy === '') {
            return "ORDER BY 1 desc ";
        }
        return " ORDER BY {$orderBy} " . ($this->paginator->isAscending() ? " ASC " : " DESC ");
    }
    function getWhereQuery() {
        if($query = $this->paginator->getQuery()) {
            return addslashes($query);
        } else {
            return  '';
        }
    }

    function setRecords(array $records) {
        $this->records = $records;
        return $this;
    }

}
<?php

namespace WCAA\Infrastructure\Paginator;

class DataPagination implements SourceInterface
{
    protected $paginator;

    protected $records = [];

    function __construct(Paginator $pg)
    {
        $this->paginator = $pg;
        $this->paginator->setSource($this);
    }

    function setData(array $data)
    {
        $this->records = $data;
        return $this;
    }


    function getRecords()
    {
        $records = $this->getFilteredRecords();
        if ($this->paginator->getOrderBy()) {
            usort($records, function ($a, $b) {
                $key = $this->paginator->getOrderBy();
                $aV = $this->getElementByKey($a, $key);
                $bV = $this->getElementByKey($b, $key);
                if ($aV == $bV) {
                    return 0;
                }
                if ($this->paginator->isAscending()) {
                    return ($aV > $bV) ? -1 : 1;
                } else {
                    return ($aV < $bV) ? -1 : 1;
                }
            });
        }
        return array_slice($records, $this->paginator->getOffset(), $this->paginator->getLimit());
    }

    function getTotalRecords(): int
    {
        $records = $this->getFilteredRecords();
        return count($records);
    }

    protected function getFilteredRecords()
    {
        $records = $this->records;
        if ($this->paginator->getQuery() && is_array($this->paginator->getQuery())) {
            $records = array_filter($records, function ($e) {
                $display = true;
                foreach ($this->paginator->getQuery() as $key => $value) {
                    $searchedElement = $this->getElementByKey($e, $key);
                    if ($searchedElement === null) {
                        continue;
                    }
                    if (strpos(
                        strtolower(json_encode($searchedElement, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES)
                        ),
                        strtolower(trim($value))) === false) {
                        $display = false;
                    }
                }
                return $display;
            });
        } elseif ($this->paginator->getQuery()) {
            $records = array_filter($records, function ($e) {
                return strpos(strtolower(json_encode($e, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK | JSON_UNESCAPED_SLASHES)), strtolower(trim($this->paginator->getQuery()))) !== false;
            });
        }
        return $records;
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
     * @return DataPagination
     */
    public function setPaginator(Paginator $paginator): DataPagination
    {
        $this->paginator = $paginator;
        return $this;
    }

    public function getElementByKey($array, $key)
    {
        $key = str_replace(["\$", ";", "'", '"', "(", ")"], "", $key);
        $elements = explode(".", $key);
        $arrayKey = join('', array_map(function ($e) {
            return "['{$e}']";
        }, $elements));
        $return = null;
        $evalArrayBlock = "if(isset(\$array{$arrayKey})) {\$return = \$array{$arrayKey}; }";
        eval($evalArrayBlock);
        return $return;
    }


}

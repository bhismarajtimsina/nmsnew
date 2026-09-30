<?php


namespace WCAA\Infrastructure\StorageMigrationSystem;


class MigrationExecutorCallbackResult
{

    protected $error = null;
    protected $query = '';
    protected $took_time = 0;
    function __construct() {

    }

    /**
     * @return \Exception|null
     */
    public function getError()
    {
        return $this->error;
    }


    /**
     * @param \Exception $error
     * @return $this
     */
    public function setError(\Exception $error)
    {
        $this->error = $error;
        return $this;
    }

    public function hasError() {
        return $this->error !== null;
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @param string $query
     * @return MigrationExecutorCallbackResult
     */
    public function setQuery(string $query): MigrationExecutorCallbackResult
    {
        $this->query = $query;
        return $this;
    }

    /**
     * @return int
     */
    public function getTookTime(): int
    {
        return round($this->took_time, 3);
    }

    /**
     * @param int $took_time
     * @return MigrationExecutorCallbackResult
     */
    public function setTookTime(int $took_time): MigrationExecutorCallbackResult
    {
        $this->took_time = $took_time;
        return $this;
    }



}
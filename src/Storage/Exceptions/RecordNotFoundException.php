<?php


namespace WCAA\Storage\Exceptions;


use Exception;

class RecordNotFoundException extends Exception
{
    protected $id;
    protected $objectName;

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param mixed $id
     * @return RecordNotFoundException
     */
    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getObjectName()
    {
        return $this->objectName;
    }

    /**
     * @param mixed $objectName
     * @return RecordNotFoundException
     */
    public function setObjectName($objectName)
    {
        $this->objectName = $objectName;
        return $this;
    }


}
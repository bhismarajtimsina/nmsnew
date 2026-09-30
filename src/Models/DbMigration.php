<?php


namespace WCAA\Models;


class DbMigration extends AbstractModel
{
    /**
     * @morm
     * @var
     */
    protected $created_at;

    /**
     * @morm
     * @var
     */
    protected $name;



    function __construct($id = null)
    {
        $this->created_at = date("Y-m-d H:i:s");
        parent::__construct($id);
    }

    /**
     * @return mixed
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param mixed $created_at
     * @return DbMigration
     */
    public function setCreatedAt($created_at)
    {
        $this->created_at = $created_at;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param mixed $name
     * @return DbMigration
     */
    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }


}
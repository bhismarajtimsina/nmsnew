<?php


namespace WCAA\Models;



use ReflectionClass;

abstract class AbstractModel implements ModelInterface
{
    /**
     * @morm.name=id
     * @var int
     */
    protected $id;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param $id
     * @return $this
     */
    public function setId($id)
    {
        $this->id = $id;
        return  $this;
    }

    function __set($key, $value) {
        $this->$key = $value;
    }
    function __get($key) {
        return isset($this->$key) ? $this->$key : null;
    }

    function __toString()
    {
        return json_encode($this->getAsArray(), JSON_PRETTY_PRINT | JSON_NUMERIC_CHECK);
    }
    private function getProperties($model)
    {
        static $cache = [];
        $class = is_object($model) ? get_class($model) : $model;
        if (isset($cache[$class])) {
            return $cache[$class];
        }
        $reflect = new ReflectionClass($model);
        $properties = $reflect->getProperties();
        $props = [];
        foreach ($properties as $property) {
            $doc = $property->getDocComment();
            $props[$property->getName()] = null;
            if ($doc && preg_match_all('/\@prop\.(.*)/', $doc, $matches)) {
                foreach ($matches[1] as $num=>$match) {
                    if(strpos($match, '=') !== false) {
                        list($key, $value) = explode("=", $match);
                        $props[$property->getName()][$key] = trim($value);
                    } else {
                        $props[$property->getName()][trim($match)] = true;
                    }
                }
            }
        }
        $cache[$class] = $props;
        return $props;
    }
    function getAsArray($object = null, $displayAllProps = false) {
        $return = [];
        if($object === null) {
            $object = $this;
        }
        foreach ($this->getProperties($object) as $propName=>$propValues) {
            if(!$displayAllProps && isset($propValues['display']) && trim($propValues['display']) === 'no') {
                continue;
            }
            $displayedName = $propName;
            if(isset($propValues['name'])) {
                $displayedName = trim($propValues['name']);
            }
            if(is_object($this->$propName) && method_exists($this->$propName, 'getAsArray') ) {
                $return[$displayedName] = $this->$propName->getAsArray();
            } elseif (is_array($this->$propName)) {
                $return[$displayedName] = array_map(function ($e) {
                    if(method_exists($e, 'getAsArray')) {
                        return $e->getAsArray();
                    }
                    return  $e;
                }, $this->$propName);
            } else {
                $return[$displayedName] = $this->$propName;
            }
        }
        return $return;
    }
    function getAsArrayLite($object = null, $notRoot=false) {
        $return = [];
        if($object === null) {
            $object = $this;
        }
        foreach ($this->getProperties($object) as $propName=>$propValues) {
            if(isset($propValues['display']) && trim($propValues['display']) === 'no') {
                continue;
            }
            if($notRoot && isset($propValues['display']) && trim($propValues['display']) === 'root') {
                continue;
            }
            $displayedName = $propName;
            if(isset($propValues['name'])) {
                $displayedName = trim($propValues['name']);
            }
            if(is_object($this->$propName) && method_exists($this->$propName, 'getAsArrayLite') ) {
                $return[$displayedName] = $this->$propName->getAsArrayLite(null, true);
            } elseif (is_array($this->$propName)) {
                $return[$displayedName] = array_map(function ($e) {
                    if(method_exists($e, 'getAsArrayLite')) {
                        return $e->getAsArrayLite(null, true);
                    }
                    return  $e;
                }, $this->$propName);
            } else {
                $return[$displayedName] = $this->$propName;
            }
        }
        return $return;
    }

    function __construct($id = null) {
        $this->id = $id;
    }
}
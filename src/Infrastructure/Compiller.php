<?php

namespace WCAA\Infrastructure;

class Compiller
{
    protected $path = null;
    protected $duration = null;

    protected static $self;

    public static function init($path, $duration = 3600) {
        self::$self = new self($path, $duration);
        return self::$self;
    }

    /**
     * @return $this
     */
    public static function getSelf() {
        return self::$self;
    }

    function __construct ( $path, $duration = 3600) {
        $this->path = $path;
        $this->duration = $duration;
    }

    function get( $id ) {
        if(_env('ENVIRONMENT') != 'PRODUCTION') {
            return  null;
        }
        $file = $this->path . $id . '.cache';
        if (file_exists($file) && time() - filemtime($file) < $this->duration) {
            return unserialize( file_get_contents($file) );
        } else {
            return null;
        }
    }

    function set($id, $obj) {
        if(_env('ENVIRONMENT') != 'PRODUCTION') {
            return;
        }
        $file = $this->path . $id . '.cache';
        file_put_contents($file, serialize($obj));
    }

    function flushAll() {
        exec("rm -f {$this->path}/*");
        return $this;
    }

    function flush($name) {
        exec("rm -f {$this->path}/{$name}.cache");
        return $this;
    }
}
<?php


namespace WCAA\Storage;


interface StorageInterface
{
    function fill($model, $fillChildObjects = true);
    function add($model);
    function delete($model);
    function update($model);
}
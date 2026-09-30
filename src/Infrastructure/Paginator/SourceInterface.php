<?php

namespace WCAA\Infrastructure\Paginator;

interface SourceInterface
{
    function __construct(Paginator $paginator);
    function getRecords();
    function getTotalRecords(): int;
}
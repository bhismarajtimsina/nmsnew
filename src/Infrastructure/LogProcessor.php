<?php


namespace WCAA\Infrastructure;


class LogProcessor
{
    /**
     * @param  array $record
     * @return array
     */
    public function __invoke(array $record)
    {
        $info = $this->findFile();
        $record['extra']['file'] = $info['file'] . ':' . $info['line'];
        return $record;
    }

    public function findFile() {
        $debug = debug_backtrace();
        return [
            'file' => $debug[3] ? str_replace("/www/src/", "", $debug[3]['file']) : '',
            'line' => $debug[3] ? $debug[3]['line'] : ''
        ];
    }
}
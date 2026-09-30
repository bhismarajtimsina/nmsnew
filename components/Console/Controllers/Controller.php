<?php


namespace WCC\Console\Controllers;


use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Infrastructure\Components\AbstractComponentController;

/**
 * Class Controller
 * @package WCC\Console
 */
class Controller extends AbstractComponentController
{


    function getConfiguration()
    {
        return [
          'web_enabled' => _env('CONSOLE_ENABLE_WEB', false),
          'open_new_at' => _env('CONSOLE_OPEN_AT', 'tab'),
        ];
    }

    /**
     * @var OutputInterface
     */
    protected $_output;

    function setConsoleOutput(OutputInterface $output)
    {
        $this->_output = $output;
        return $this;
    }

}

<?php


namespace WCAA\Infrastructure\Components;


use Monolog\Logger;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\App;
use WCAA\Infrastructure\ComponentInjector;

abstract class AbstractComponentController
{

    /**
     * @Inject
     * @var Logger
     */
    protected $logger;

    /**
     * @Inject
     * @var App
     */
    protected $app;

    /**
     * @var array
     */

    protected $moduleConfig;

    /**
     * @var ComponentInjector
     */
    protected $moduleInjector;

    function __construct(ComponentInjector $componentInjector, Logger $logger)
    {
        $this->moduleInjector = $componentInjector;
        $this->moduleConfig = $componentInjector->getEnabledComponentConfig($this->getModuleName());
        $this->logger=$logger->withName("component." . $this->moduleConfig['name']);
    }

    private function getModuleName() {
        $rc = new \ReflectionClass(get_class($this));
        if(!preg_match('#/Controllers#', dirname($rc->getFileName()))) {
            throw new \Exception("Controllers must be placed in <component_path>/<Component>/Controllers");
        }
        return (require  dirname($rc->getFileName()) . "/../config.php")['name'];
    }

    function setLogger(Logger $logger) {
        $this->logger = $logger;
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

    function log($level, $msg, $tech = null)
    {
        if ($this->_output) {
            $th = '';
            if ($tech) {
                $th = json_encode($tech, JSON_UNESCAPED_UNICODE);
            }
            $dateTime = date("Y-m-d H:i:s");
            switch ($level) {
                case 'INFO':
                    if ($this->_output->isVerbose()) {
                        $this->_output->writeln("$dateTime [INFO] $msg $th");
                    }
                    break;
                case 'DEBUG':
                    if ($this->_output->isVerbose()) {
                        $this->_output->writeln("$dateTime [DEBUG] $msg $th");
                    }
                    break;
                case 'ERR':
                case 'ERROR':
                    $this->_output->writeln("<error>$dateTime [ERR] $msg $th</error>");
                    break;
                case 'WARN':
                case 'WARNING':
                    $this->_output->writeln("<comment>$dateTime [WARN] $msg $th</comment>");
                    break;
                default:
                    $this->_output->writeln("$dateTime [INFO] $msg $th");
            }
        }
    }
}

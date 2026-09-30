<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceModelStorage;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListModelCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var DeviceModelStorage
     */
    protected $modelStorage;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    protected $question;

    /**
     * @var array
     */
    protected function configure()
    {
        $this->setName("device-model:list")
            ->setDescription("Table of device models")
            ->addOption("output", "o", InputOption::VALUE_OPTIONAL, "Output format. support: table, yaml, json", "table");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $models = $this->modelStorage->fetchAll();

        switch ($input->getOption('output')) {
            case 'table':
                $table = new Table($output);
                $table->setHeaders([
                    'Id',
                    'Key',
                    'Name',
                    'Vendor',
                    'Model',
                    'Params',
                ]);
                foreach ($models as $model) {
                    $params = '';
                    if($model->getParams()) foreach ($model->getParams() as $key => $val) {
                        $params .= "$key={$val['value']}, ";
                    }
                    $params = trim($params, ", ");
                    $table->addRow([
                        $model->getId(),
                        $model->getKey(),
                        $model->getName(),
                        $model->getVendor(),
                        $model->getModel(),
                        $params
                    ]);
                }
                $table->render();
                break;
            case 'json':
                $models = array_map(function ($e) {
                    return $e->getAsArray();
                }, $models);
                $output->writeln($this->toJson($models));
                break;
            case 'yaml':
                $models = array_map(function ($e) {
                    return $e->getAsArray();
                }, $models);
                $output->writeln($this->toYaml($models));
                break;
        }
        return self::SUCCESS;
    }
}
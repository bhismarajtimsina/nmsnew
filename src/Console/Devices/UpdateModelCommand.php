<?php


namespace WCAA\Console\Devices;

use DI\Annotation\Inject;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\Devices\DeviceModelStorage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class UpdateModelCommand extends AbstractCommand
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
        $this->setName("device-model:update")
            ->setDescription("Update device model")
            ->addArgument("id", InputArgument::REQUIRED, "Model ID for update");
    }


    function execute(InputInterface $input, OutputInterface $output)
    {
        $this->prepareForQuestions($input, $output);
        $output->writeln("Device model updating");
        $output->writeln("-------------------------------------");
        $model = $this->modelStorage->getById($input->getArgument('id'));
        $model->setVendor($this->question("Vendor (Default: {$model->getVendor()})", $model->getVendor()));
        $model->setModel($this->question("Model (Default: {$model->getModel()})", $model->getModel()));
        $model->setName($this->question("Name (Default: {$model->getName()})", $model->getName()));

        if ($this->question->ask($input, $output, new ConfirmationQuestion("Update extra properties? WARNING, empty properties will be deleted", true))) {
            $props = [];
            foreach ($model->getParams() as $key => $value) {
                $val = $this->question($value['name']);
                if (!$val) {
                    $output->writeln("Parameter $key will be deleted from model");
                    continue;
                }
                $props[$key]['name'] = $value['name'];
                $props[$key]['value'] = $val;
            }
            $model->setParams($props);
        }
        $this->modelStorage->update($model);
        $model->setIcon('<SETTED>');
        $output->writeln("-------------------------------------");
        $output->writeln($this->toJson($model->getAsArray()));
        $output->writeln("-------------------------------------");
        return self::SUCCESS;
    }
}
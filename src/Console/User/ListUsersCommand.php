<?php


namespace WCAA\Console\User;


use DI\Annotation\Inject;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\UserStorage;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListUsersCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var UserStorage
     */
    protected $storage;


    protected function configure()
    {
        $this->setName("user:list")
            ->setDescription("List users")
            ->addOption("output", "o", InputOption::VALUE_OPTIONAL, "Output format. support: table, yaml, json", "table");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $list = $this->storage->fetchAll();
        switch ($input->getOption('output')) {
            case 'table':
                $table = new Table($output);
                $table->setHeaders([
                    'ID',
                    'Login',
                    'Name',
                    'Group',
                    'Created At',
                    'Updated At',
                ]);
                foreach ($list as $l) {
                    $table->addRow([
                        $l->getId(),
                        $l->getLogin(),
                        $l->getName(),
                        $l->getRole()->getName(),
                        $l->getCreatedAt(),
                        $l->getUpdatedAt(),
                    ]);
                }
                $table->render();
                break;
            case 'json':
                $list = array_map(function ($e) {
                    return $e->getAsArray();
                }, $list);
                $output->writeln($this->toJson($list));
                break;
            case 'yaml':
                $list = array_map(function ($e) {
                    return $e->getAsArray();
                }, $list);
                $output->writeln($this->toYaml($list));
                break;
        }
        return self::SUCCESS;
    }
}
<?php


namespace WCAA\Console\User;


use DI\Annotation\Inject;
use InvalidArgumentException;
use WCAA\Console\AbstractCommand;
use WCAA\Storage\UserStorage;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteUserCommand extends AbstractCommand
{
    /**
     * @Inject
     * @var UserStorage
     */
    protected $storage;


    protected function configure()
    {
        $this->setName("user:delete")
            ->addArgument("id", InputArgument::REQUIRED, "User ID")
            ->setDescription("Delete user");
    }

    function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getArgument('id');
        if($group = $this->storage->getById($id)) {
            if($this->confirm("WARNING!!!   This will remove all relations with choosed user!
            
Are you sure to delete user {$group->getName()} with ID {$id}?")) {
                $this->storage->delete($group);
                $output->writeln("User success deleted!");
            }
        } else {
            throw new InvalidArgumentException("User with ID $id not found");
        }
        return self::SUCCESS;
    }

}
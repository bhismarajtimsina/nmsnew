<?php


namespace WCAA\Console\OpenApi;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WCAA\Console\AbstractCommand;

class OpenApiGenerationCommand extends AbstractCommand
{
    protected function configure()
    {
        $this->setName('openapi:generate')
            ->setDescription('Generate OpenAPI spec');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        passthru('cd /www && vendor/bin/openapi --bootstrap src/OpenApi/bootstrap.php --legacy src components -o var/openapi/openapi.yaml', $code);

        return $code === 0 ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace Oro\Bundle\InstallerBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;

use Oro\Bundle\InstallerBundle\CommandExecutor;

class PlatformUpdateCommand extends ContainerAwareCommand
{
    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this->setName('oro:platform:update')
            ->setDescription('Execute platform application update commands and init platform assets.')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Forces operation to be executed.'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_OPTIONAL,
                'Timeout for child command execution',
                CommandExecutor::DEFAULT_TIMEOUT
            )
            ->addOption('symlink', null, InputOption::VALUE_NONE, 'Symlinks the assets instead of copying it') ;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = $input->getOption('force');

        if ($force) {
            $assetsOptions = array(
                '--exclude' => array('OroInstallerBundle')
            );
            if ($input->hasOption('symlink') && $input->getOption('symlink')) {
                $assetsOptions['--symlink'] = true;
            }

            $commandExecutor = new CommandExecutor(
                $input->hasOption('env') ? $input->getOption('env') : null,
                $output,
                $this->getApplication(),
                $this->getContainer()->get('oro_cache.oro_data_cache_manager')
            );

            $timeout = $input->getOption('timeout');
            $commandExecutor->setDefaultTimeout($timeout);

            $commandExecutor
                ->runCommand(
                    'oro:migration:load',
                    array(
                        '--process-isolation' => true,
                        '--force'             => true,
                        '--timeout'           => $timeout
                    )
                )
                ->runCommand('oro:workflow:definitions:load', array('--process-isolation' => true))
                ->runCommand('oro:process:configuration:load', array('--process-isolation' => true))
                ->runCommand('oro:migration:data:load', array('--process-isolation' => true))
                ->runCommand('oro:navigation:init', array('--process-isolation' => true))
                ->runCommand('oro:assets:install', $assetsOptions)
                ->runCommand('assetic:dump')
                ->runCommand('router:cache:clear', array('--process-isolation' => true))
                ->runCommand(
                    'fos:js-routing:dump',
                    array('--target' => 'web/js/routes.js', '--process-isolation' => true)
                )
                ->runCommand('oro:localization:dump', array('--process-isolation' => true))
                ->runCommand('oro:translation:dump', array('--process-isolation' => true))
                ->runCommand('oro:requirejs:build', array('--ignore-errors' => true, '--process-isolation' => true));
        } else {
            $output->writeln(
                '<comment>ATTENTION</comment>: Database backup is highly recommended before executing this command.'
            );
            $output->writeln(
                '           Please make sure that application cache is up-to-date or empty before run this command.'
            );
            $output->writeln('           Use <info>cache:clear --no-optional-warmers</info> if needed.');
            $output->writeln('');
            $output->writeln('To force execution run command with <info>--force</info> option:');
            $output->writeln(sprintf('    <info>%s --force</info>', $this->getName()));
        }
    }
}

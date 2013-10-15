<?php

namespace Oro\Bundle\InstallerBundle\Process\Step;

use Sylius\Bundle\FlowBundle\Process\Context\ProcessContextInterface;

class InstallationStep extends AbstractStep
{
    public function displayAction(ProcessContextInterface $context)
    {
        set_time_limit(600);

        switch ($this->getRequest()->query->get('action')) {
            case 'schema':
                return $this->handleAjaxAction('doctrine:schema:update', array('--force' => true));

            case 'search':
                return $this->handleAjaxAction('oro:search:create-index');

            case 'navigation':
                return $this->handleAjaxAction('oro:navigation:init');

            case 'assets':
                return $this->handleAjaxAction('assets:install', array('target' => './'));

            case 'assetic':
                return $this->handleAjaxAction('assetic:dump');

            case 'assetic-oro':
                return $this->handleAjaxAction('oro:assetic:dump');

            case 'translation':
                return $this->handleAjaxAction('oro:translation:dump');

            case 'requirejs':
                return $this->handleAjaxAction('oro:requirejs:build');
        }

        return $this->render('OroInstallerBundle:Process/Step:installation.html.twig');
    }
}

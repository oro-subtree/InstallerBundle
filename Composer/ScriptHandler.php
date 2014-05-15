<?php

namespace Oro\Bundle\InstallerBundle\Composer;

use Sensio\Bundle\DistributionBundle\Composer\ScriptHandler as SensioScriptHandler;
use Symfony\Component\Filesystem\Filesystem;
use Composer\Script\CommandEvent;

class ScriptHandler extends SensioScriptHandler
{
    /**
     * Installs the assets for installer bundle
     *
     * @param CommandEvent $event A instance
     */
    public static function installAssets(CommandEvent $event)
    {
        $options = self::getOptions($event);
        $webDir  = $options['symfony-web-dir'];

        $sourceDir = __DIR__ . '/../Resources/public';
        $targetDir = $webDir . '/bundles/oroinstaller';

        $filesystem = new Filesystem();
        $filesystem->remove($targetDir);
        $filesystem->mirror($sourceDir, $targetDir);
    }

    /**
     * Set permissions for directories
     *
     * @param CommandEvent $event
     */
    public static function setPermissions(CommandEvent $event)
    {
        $directories = [
            'app/cache',
            'app/logs',
        ];

        $permissionHandler = new PermissionsHandler();
        $filesystem        = new Filesystem();

        $withoutPermissionsList = [];
        foreach ($directories as $directory) {
            if ($filesystem->exists($directory)) {
                $isPermissionSet = $permissionHandler->setPermissions($directory);

                if (!$isPermissionSet) {
                    $withoutPermissionsList[] = $directory;
                }
            }
        }

        if ($withoutPermissionsList) {
            $withoutPermissions = implode(' ', $withoutPermissionsList);
            $event->getIO()->write(
                sprintf('Permissions for "%s" directories were not set', $withoutPermissions)
            );

            $commandToRun = sprintf(PermissionsHandler::CHMOD, PermissionsHandler::USER_COMMAND, $withoutPermissions);
            $event->getIO()->write(
                sprintf('Please run "sudo %s" manually from console', $commandToRun)
            );
        }
    }
}

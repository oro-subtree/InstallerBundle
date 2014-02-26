<?php

namespace Oro\Bundle\InstallerBundle\Migrations;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;

use Oro\Bundle\InstallerBundle\Migrations\Event\MigrationEvents;
use Oro\Bundle\InstallerBundle\Migrations\Event\PostMigrationEvent;
use Oro\Bundle\InstallerBundle\Migrations\Event\PreMigrationEvent;
use Oro\Bundle\InstallerBundle\Migrations\MigrationTable\UpdateBundleVersionMigration;

/**
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class MigrationsLoader
{
    const MIGRATIONS_PATH = 'Migrations/Schema';

    /**
     * @var KernelInterface
     *
     */
    protected $kernel;

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var EventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var string An array with already loaded bundle migration versions
     *             key =   bundle name
     *             value = latest loaded version
     */
    protected $loadedVersions;

    /**
     * @var array An array with bundles we must work from
     */
    protected $bundles;

    /**
     * @var array An array with excluded bundles
     */
    protected $excludeBundles;

    /**
     * @param KernelInterface    $kernel
     * @param Connection         $connection
     * @param ContainerInterface $container
     * @param EventDispatcher    $eventDispatcher
     */
    public function __construct(
        KernelInterface $kernel,
        Connection $connection,
        ContainerInterface $container,
        EventDispatcher $eventDispatcher
    ) {
        $this->kernel          = $kernel;
        $this->connection      = $connection;
        $this->container       = $container;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param array $bundles
     */
    public function setBundles($bundles)
    {
        $this->bundles = $bundles;
    }

    /**
     * @param array $excludeBundles
     */
    public function setExcludeBundles($excludeBundles)
    {
        $this->excludeBundles = $excludeBundles;
    }

    /**
     * @return Migration[]
     */
    public function getMigrations()
    {
        // process "pre" migrations
        $preEvent = new PreMigrationEvent($this->connection);
        $this->eventDispatcher->dispatch(MigrationEvents::PRE_UP, $preEvent);
        $migrations           = $preEvent->getMigrations();
        $this->loadedVersions = $preEvent->getLoadedVersions();

        // process main migrations
        $migrationDirectories = $this->getMigrationDirectories();
        $this->filterMigrations($migrationDirectories);
        $this->createMigrationObjects(
            $migrations,
            $this->loadMigrationScripts($migrationDirectories)
        );
        $bundleVersions = $this->getLatestMigrationVersions($migrationDirectories);
        if (!empty($bundleVersions)) {
            $migrations[] = new UpdateBundleVersionMigration($bundleVersions);
        }

        // process "post" migrations
        $postEvent = new PostMigrationEvent($this->connection);
        $this->eventDispatcher->dispatch(MigrationEvents::POST_UP, $postEvent);
        $postMigrations = $postEvent->getMigrations();
        foreach ($postMigrations as $migration) {
            $migrations[] = $migration;
        }

        return $migrations;
    }

    /**
     * Gets a list of all directories contain migration scripts
     *
     * @return array
     *      key   = bundle name
     *      value = array
     *      .    key   = a migration version (actually it equals the name of migration directory)
     *      .            or empty string for root migration directory
     *      .    value = full path to a migration directory
     */
    protected function getMigrationDirectories()
    {
        $result = [];

        $bundles = $this->getBundleList();
        foreach ($bundles as $bundleName => $bundle) {
            $bundlePath          = $bundle->getPath();
            $bundleMigrationPath = str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $bundlePath . '/' . self::MIGRATIONS_PATH
            );

            if (is_dir($bundleMigrationPath)) {
                $bundleMigrationDirectories = [];

                // get directories contain versioned migration scripts
                $finder = new Finder();
                $finder->directories()->depth(0)->in($bundleMigrationPath);
                /** @var SplFileInfo $directory */
                foreach ($finder as $directory) {
                    $bundleMigrationDirectories[$directory->getRelativePathname()] = $directory->getPathname();
                }
                // add root migration directory (it may contains an installation script)
                $bundleMigrationDirectories[''] = $bundleMigrationPath;
                // sort them by version number (the oldest version first)
                if (!empty($bundleMigrationDirectories)) {
                    uksort(
                        $bundleMigrationDirectories,
                        function ($a, $b) {
                            return version_compare($a, $b);
                        }
                    );
                }

                $result[$bundleName] = $bundleMigrationDirectories;
            }
        }

        return $result;
    }

    /**
     * Finds migration files and call "include_once" for each file
     *
     * @param array $migrationDirectories
     *               key   = bundle name
     *               value = array
     *               .    key   = a migration version or empty string for root migration directory
     *               .    value = full path to a migration directory
     * @return array loaded files
     *               'migrations' => array
     *               .      key   = full file path
     *               .      value = array
     *               .            'bundleName' => bundle name
     *               .            'version'    => migration version
     *               'installers' => array
     *               .      key   = full file path
     *               .      value = bundle name
     *               'bundles'    => string[] names of bundles
     */
    protected function loadMigrationScripts(array $migrationDirectories)
    {
        $migrations = [];
        $installers = [];

        foreach ($migrationDirectories as $bundleName => $bundleMigrationDirectories) {
            foreach ($bundleMigrationDirectories as $migrationVersion => $migrationPath) {
                $fileFinder = new Finder();
                $fileFinder->depth(0)->files()->name('*.php')->in($migrationPath);
                foreach ($fileFinder as $file) {
                    /** @var SplFileInfo $file */
                    $filePath = $file->getPathname();
                    include_once $filePath;
                    if (empty($migrationVersion)) {
                        $installers[$filePath] = $bundleName;
                    } else {
                        $migrations[$filePath] = ['bundleName' => $bundleName, 'version' => $migrationVersion];
                    }
                }
            }
        }

        return [
            'migrations' => $migrations,
            'installers' => $installers,
            'bundles'    => array_keys($migrationDirectories),
        ];
    }

    /**
     * Extracts latest migration version for each bundle
     *
     * @param array $migrationDirectories
     *      key   = bundle name
     *      value = array
     *      .    key   = a migration version or empty string for root migration directory
     *      .    value = full path to a migration directory
     * @return string[]
     *      key   = bundle name
     *      value = latest migration version
     */
    protected function getLatestMigrationVersions(array $migrationDirectories)
    {
        $result = [];
        foreach ($migrationDirectories as $bundleName => $bundleMigrationDirectories) {
            $versions = array_keys($bundleMigrationDirectories);
            $version  = array_pop($versions);
            if ($version) {
                $result[$bundleName] = $version;
            }
        }

        return $result;
    }

    /**
     * Creates an instances of all classes implement migration scripts
     *
     * @param Migration[] $result
     * @param array       $files Files contain migration scripts
     *                           'migrations' => array
     *                           .      key   = full file path
     *                           .      value = array
     *                           .            'bundleName' => bundle name
     *                           .            'version'    => migration version
     *                           'installers' => array
     *                           .      key   = full file path
     *                           .      value = bundle name
     *                           'bundles'    => string[] names of bundles
     * @throws \RuntimeException if a migration script contains more than one class
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function createMigrationObjects(&$result, $files)
    {
        $migrations = [];
        $installers = [];

        // load migration objects
        $declared = get_declared_classes();
        foreach ($declared as $className) {
            $reflClass  = new \ReflectionClass($className);
            $sourceFile = $reflClass->getFileName();
            if (isset($files['migrations'][$sourceFile])) {
                $migration = new $className;
                if ($migration instanceof Migration) {
                    if (isset($migrations[$sourceFile])) {
                        throw new \RuntimeException('A migration script must contains only one class.');
                    }
                    if ($migration instanceof ContainerAwareInterface) {
                        $migration->setContainer($this->container);
                    }
                    $migrations[$sourceFile] = $migration;
                }
            } elseif (isset($files['installers'][$sourceFile])) {
                $installer = new $className;
                if ($installer instanceof Installation) {
                    if (isset($migrations[$sourceFile])) {
                        throw new \RuntimeException('An installation  script must contains only one class.');
                    }
                    if ($installer instanceof ContainerAwareInterface) {
                        $installer->setContainer($this->container);
                    }
                    $migrations[$sourceFile] = $installer;
                    $installers[$sourceFile] = [
                        'bundleName' => $files['installers'][$sourceFile],
                        'version'    => $installer->getMigrationVersion(),
                    ];
                }
            }
        }

        // remove versioned migrations covered by installers
        foreach ($installers as $installer) {
            $installerBundleName = $installer['bundleName'];
            $installerVersion    = $installer['version'];
            foreach ($files['migrations'] as $sourceFile => $migration) {
                if ($migration['bundleName'] === $installerBundleName
                    && version_compare($migration['version'], $installerVersion) < 1
                ) {
                    unset($migrations[$sourceFile]);
                }
            }
        }

        // add migration objects to result tacking into account bundles order
        foreach ($files['bundles'] as $bundleName) {
            foreach ($files['installers'] as $sourceFile => $installerBundleName) {
                if ($installerBundleName === $bundleName && isset($migrations[$sourceFile])) {
                    $result[] = $migrations[$sourceFile];
                }
            }
            foreach ($files['migrations'] as $sourceFile => $migration) {
                if ($migration['bundleName'] === $bundleName && isset($migrations[$sourceFile])) {
                    $result[] = $migrations[$sourceFile];
                }
            }
        }
    }

    /**
     * Removes already installed migrations
     *
     * @param array $migrationDirectories
     *      key   = bundle name
     *      value = array
     *      .    key   = a migration version or empty string for root migration directory
     *      .    value = full path to a migration directory
     */
    protected function filterMigrations(array &$migrationDirectories)
    {
        if (!empty($this->loadedVersions)) {
            foreach ($migrationDirectories as $bundleName => $bundleMigrationDirectories) {
                $loadedVersion = isset($this->loadedVersions[$bundleName])
                    ? $this->loadedVersions[$bundleName]
                    : null;
                if ($loadedVersion) {
                    foreach (array_keys($bundleMigrationDirectories) as $migrationVersion) {
                        if (empty($migrationVersion) || version_compare($migrationVersion, $loadedVersion) < 1) {
                            unset ($migrationDirectories[$bundleName][$migrationVersion]);
                        }
                    }
                }
            }
        }
    }

    /**
     * @return BundleInterface[] key = bundle name
     */
    protected function getBundleList()
    {
        $bundles = $this->kernel->getBundles();
        if (!empty($this->bundles)) {
            $includedBundles = [];
            foreach ($this->bundles as $bundleName) {
                if (isset($bundles[$bundleName])) {
                    $includedBundles[$bundleName] = $bundles[$bundleName];
                }
            }
            $bundles = $includedBundles;
        }
        if (!empty($this->excludeBundles)) {
            foreach ($this->excludeBundles as $excludeBundle) {
                unset($bundles[$excludeBundle]);
            }
        }

        return $bundles;
    }
}

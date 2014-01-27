<?php

namespace Oro\Bundle\InstallerBundle\Migrations;

use Doctrine\Common\DataFixtures\OrderedFixtureInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Doctrine\ORM\EntityManager;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\Common\DataFixtures\FixtureInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Doctrine\Common\DataFixtures\Loader;

use Oro\Bundle\InstallerBundle\Entity\BundleVersion;
use Oro\Bundle\InstallerBundle\Migrations\UpdateBundleVersionFixture;

class FixturesLoader extends Loader
{
    const FIXTURES_PATH           = 'DataFixtures/Migrations/ORM';
    const DEMO_DATA_FIXTURES_PATH = 'DataFixtures/Demo/Migrations/ORM';

    const FILE_EXTENSION = '.php';

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var KernelInterface
     */
    protected $kernel;

    /**
     * @var array
     */
    protected $fixturesDirs = [];

    /**
     * @var array
     */
    protected $bundleDataVersions = [];

    /**
     * @var array
     */
    protected $bundleFixtureDirs = [];

    /**
     * @var bool
     */
    protected $loadDemoData = false;

    /**
     * @param EntityManager $em
     * @param KernelInterface $kernel
     * @param ContainerInterface $container
     */
    public function __construct(EntityManager $em, KernelInterface $kernel, ContainerInterface $container)
    {
        $this->em        = $em;
        $this->kernel    = $kernel;
        $this->container = $container;
    }

    /**
     * @param bool $loadDemoData
     */
    public function isLoadDemoData($loadDemoData = false)
    {
        $this->loadDemoData = $loadDemoData;
    }

    /**
     * @inheritdoc
     */
    public function getFixtures()
    {
        if (empty($this->fixturesDirs)) {
            $this->setFixturePath();
        }
        $bundlesFixtures = [];
        foreach ($this->bundleFixtureDirs as $bundleName => $fixtureDirs) {
            $fixtures           = [];
            $parentBundlesArray = [];
            foreach ($fixtureDirs as $fixtureDir) {
                list($fixture, $parentBundles) = $this->loadFromBundleDirectory($bundleName, $fixtureDir);
                $fixtures           = array_merge($fixtures, $fixture);
                $parentBundlesArray = array_merge($parentBundlesArray, $parentBundles);
            }
            $bundleInfo                   = new \stdClass();
            $bundleInfo->bundleName       = $bundleName;
            $bundleInfo->fixtures         = $fixtures;
            $bundleInfo->parentBundles    = $parentBundlesArray;
            $bundleInfo->iterator         = 0;
            $bundlesFixtures[$bundleName] = $bundleInfo;
        }
        foreach ($bundlesFixtures as &$bundleInfo) {
            if (!empty($bundleInfo->parentBundles)) {
                foreach ($bundleInfo->parentBundles as $parentBundle) {
                    $bundleInfo->iterator--;
                    $bundlesFixtures[$parentBundle]->iterator++;
                }
            }
        }
        usort($bundlesFixtures, [$this, 'sortFixturesStd']);
        foreach ($bundlesFixtures as $bundleInfo) {
            foreach ($bundleInfo->fixtures as $fixture) {
                $this->addFixture($fixture->fixture);
            }
        }
        // add update bundle data version fixture
        if (!empty($this->bundleDataVersions)) {
            $updateFixture = new UpdateBundleVersionFixture();
            $updateFixture->setBundleVersions($this->bundleDataVersions);
            $updateFixture->setIsDemoDataUpdate($this->loadDemoData);
            $this->addFixture($updateFixture);
        }

        return parent::getFixtures();
    }

    /**
     * @param string $bundleName
     * @param string $dir
     * @return array Array of loaded fixture object instances
     * @throws \InvalidArgumentException
     */
    public function loadFromBundleDirectory($bundleName, $dir)
    {
        if (!is_dir($dir)) {
            throw new \InvalidArgumentException(sprintf('"%s" does not exist', $dir));
        }

        $fixtures      = array();
        $includedFiles = array();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (($fileName = $file->getBasename(self::FILE_EXTENSION)) == $file->getBasename()) {
                continue;
            }
            $sourceFile = realpath($file->getPathName());
            require_once $sourceFile;
            $includedFiles[] = $sourceFile;
        }

        $declared = get_declared_classes();

        foreach ($declared as $className) {
            $reflClass  = new \ReflectionClass($className);
            $sourceFile = $reflClass->getFileName();
            if (in_array($sourceFile, $includedFiles) && !$this->isTransient($className)) {
                $fixture           = new \stdClass();
                $fixture->fixture  = new $className;
                $fixture->iterator = 0;
                $fixtures[]        = $fixture;
            }
        }

        $parentBundles = [];
        $this->processDependency($fixtures, $parentBundles, $bundleName);

        usort($fixtures, [$this, 'sortFixturesStd']);

        return [$fixtures, $parentBundles];
    }

    /**
     * @inheritdoc
     */
    public function addFixture(FixtureInterface $fixture)
    {
        if ($fixture instanceof ContainerAwareInterface) {
            $fixture->setContainer($this->container);
        }

        $reflection = new \ReflectionObject($this);
        $parent     = $reflection->getParentClass();

        $fixtureClass = get_class($fixture);

        $fixturesReflection = $parent->getProperty('fixtures');
        $fixturesReflection->setAccessible(true);
        $fixtures = $fixturesReflection->getValue($this);

        if (!isset($fixtures[$fixtureClass])) {
            if ($fixture instanceof OrderedFixtureInterface) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Versioned fixtures does not support OrderedFixtureInterface.
                        Use DependentFixtureInterface for ordering. Please fix %s fixture.',
                        get_class($fixture)
                    )
                );
            }

            $fixtures[$fixtureClass] = $fixture;
        }

        $fixturesReflection->setValue($this, $fixtures);
    }

    /**
     * Set list of fixtures paths to run
     */
    public function setFixturePath()
    {
        $repo               = $this->em->getRepository('OroInstallerBundle:BundleVersion');
        $bundleDataVersions = [];
        $bundleFixtureDirs  = [];
        $bundles            = $this->kernel->getBundles();
        foreach ($bundles as $bundleName => $bundle) {
            $bundlePath         = $bundle->getPath();
            $bundleFixturesPath = str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $bundlePath . '/' . ($this->loadDemoData ? self::DEMO_DATA_FIXTURES_PATH : self::FIXTURES_PATH)
            );

            $finder            = new Finder();
            $bundleDirFixtures = [];
            $bundleDataVersion = null;
            try {
                $finder->directories()->depth(0)->in($bundleFixturesPath);
                /** @var SplFileInfo $directory */
                foreach ($finder as $directory) {
                    if ($bundleDataVersion === null) {
                        /** @var BundleVersion $versionData */
                        $versionData = $repo->findOneBy(['bundleName' => $bundleName]);
                        if ($versionData) {
                            $bundleDataVersion = $this->loadDemoData
                                ? $versionData->getDemoDataVersion()
                                : $versionData->getDataVersion();
                        } else {
                            $bundleDataVersion = false;
                        }
                    }

                    $fixtureVersion = $directory->getRelativePathname();
                    if (!$bundleDataVersion
                        || version_compare($fixtureVersion, $bundleDataVersion) > 0
                    ) {
                        $bundleDirFixtures[] = $fixtureVersion;
                    }
                }
            } catch (\Exception $e) {
                //dir doesn't exists
            }

            $this->setSortedFixtures(
                $bundleDirFixtures,
                $bundleName,
                $bundleFixturesPath,
                $bundleDataVersions,
                $bundleFixtureDirs
            );
        }

        $this->bundleDataVersions = $bundleDataVersions;
        $this->bundleFixtureDirs  = $bundleFixtureDirs;
    }

    protected function sortFixturesStd($a, $b)
    {
        if ($a->iterator > $b->iterator) {
            return -1;
        }
        if ($a->iterator < $b->iterator) {
            return 1;
        }

        return 0;
    }

    /**
     * @param string $className
     * @return bool|string
     */
    protected function getBundleNameForClass($className)
    {
        $bundleClass = substr($className, 0, strrpos($className, 'Bundle\\') + 6);
        foreach ($this->kernel->getBundles() as $bundle) {
            if (get_class($bundle) == $bundleClass . '\\' . $bundle->getName()) {

                return $bundle->getName();
            }
        }

        return false;
    }

    /**
     * @param $fixtures
     * @param $parentBundles
     * @param $bundleName
     */
    protected function processDependency(&$fixtures, &$parentBundles, $bundleName)
    {
        foreach ($fixtures as &$fixtureData) {
            if ($fixtureData->fixture instanceof DependentFixtureInterface) {
                foreach ($fixtureData->fixture->getDependencies() as $dependency) {
                    $bundle = $this->getBundleNameForClass($dependency);
                    if ($bundle == $bundleName) {
                        foreach ($fixtures as &$bundleFixture) {
                            if (get_class($bundleFixture->fixture) == $dependency) {
                                $bundleFixture->iterator++;
                            }
                        }
                        $fixtureData->iterator--;
                    } else {
                        $parentBundles[] = $bundle;
                    }
                }
            }
        }
    }

    /**
     * @param $bundleDirFixtures
     * @param $bundleName
     * @param $bundleFixturesPath
     * @param $bundleDataVersions
     * @param $bundleFixtureDirs
     * @return array
     */
    protected function setSortedFixtures(
        $bundleDirFixtures,
        $bundleName,
        $bundleFixturesPath,
        &$bundleDataVersions,
        &$bundleFixtureDirs
    ) {
        if (!empty($bundleDirFixtures)) {
            usort($bundleDirFixtures, [$this, 'sortFixtures']);
            foreach ($bundleDirFixtures as $relativePathFixture) {
                if (!isset($bundleFixtureDirs[$bundleName])) {
                    $bundleFixtureDirs[$bundleName] = [];
                }
                $bundleFixtureDirs[$bundleName][$relativePathFixture] =
                    $bundleFixturesPath . DIRECTORY_SEPARATOR . $relativePathFixture;
            }
            $bundleDataVersions[$bundleName] = array_pop($bundleDirFixtures);
        }
    }

    /**
     * Usort callback sorter for directories
     *
     * @param array $a
     * @param array $b
     * @return int
     */
    protected function sortFixtures($a, $b)
    {
        return version_compare($a, $b);
    }
}

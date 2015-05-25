<?php

namespace Oro\Bundle\InstallerBundle\Tests\Selenium\Pages;

use Oro\Bundle\TestFrameworkBundle\Pages\AbstractPage;

class OroAdministration extends AbstractPage
{
    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $companyShort;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $company;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $username;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $passwordFirst;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $passwordSecond;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $email;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $firstName;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $lastName;

    /** @var \PHPUnit_Extensions_Selenium2TestCase_Element */
    protected $loadFixtures;

    public function __construct($testCase, $redirect = true)
    {
        parent::__construct($testCase, $redirect);

        $this->organization = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_organization_name']");
        $this->username = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_username']");
        $this->passwordFirst = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_plainPassword_first']");
        $this->passwordSecond = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_plainPassword_second']");
        $this->email = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_email']");
        $this->firstName = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_firstName']");
        $this->lastName = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_lastName']");
        $this->loadFixtures = $this->test->byXpath("//*[@data-ftid='oro_installer_setup_loadFixtures']");
    }

    public function next()
    {
        $this->test->moveto($this->test->byXpath("//button[@class = 'primary button icon-settings']"));
        $this->test->byXpath("//button[@class = 'primary button icon-settings']")->click();
        $this->waitPageToLoad();
        $this->assertTitle('Installation - Oro Application installation');
        //waiting
        $s = microtime(true);
        do {
            sleep(5);
            //$this->waitPageToLoad();
            $e = microtime(true);
            $this->test->assertTrue(($e-$s) <= (int)(MAX_EXECUTION_TIME / 1000));
        } while ($this->isElementPresent("//a[@class = 'button next primary disabled']"));

        $this->test->moveto($this->test->byXpath("//a[@class = 'button next primary']"));
        $this->test->byXpath("//a[@class = 'button next primary']")->click();
        $this->waitPageToLoad();
        $this->assertTitle('Finish - Oro Application installation');
        
        return new OroFinish($this->test);
    }

    public function setPasswordFirst($value)
    {
        $this->passwordFirst->clear();
        $this->passwordFirst->value($value);
        return $this;
    }

    public function setPasswordSecond($value)
    {
        $this->passwordSecond->clear();
        $this->passwordSecond->value($value);
        return $this;
    }

    public function setUsername($value)
    {
        $this->username->clear();
        $this->username->value($value);
        return $this;
    }

    public function setFirstName($value)
    {
        $this->firstName->clear();
        $this->firstName->value($value);
        return $this;
    }

    public function setLastName($value)
    {
        $this->lastName->clear();
        $this->lastName->value($value);
        return $this;
    }

    public function setEmail($value)
    {
        $this->email->clear();
        $this->email->value($value);
        return $this;
    }

    public function setLoadFixtures()
    {
        $this->loadFixtures->click();
        return $this;
    }
}

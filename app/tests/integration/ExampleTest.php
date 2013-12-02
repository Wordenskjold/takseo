<?php
class WebTest extends PHPUnit_Extensions_Selenium2TestCase
{

    protected function setUp()
    {
        $this->setHost('172.16.202.120');
        $this->setPort(4444);
        $this->setBrowser('firefox');
        $this->setBrowserUrl('http://takseo.dev');
    }

    /**
     * @test
     */
    public function testTitle()
    {
        $this->url('/');
        $this->assertEquals('Laravel PHP Framework', $this->title());
    }
}
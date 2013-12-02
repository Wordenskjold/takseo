<?php

/** Full path to project dir on host */
isset($_SERVER['HOST_DIR']) or $_SERVER['HOST_DIR'] = realpath(__DIR__ . '/../../../');

/** Full path to vagrant directory on host */
$_SERVER['VAGRANT_DIR'] = $_SERVER['HOST_DIR'] . '/boxsetup';

/** Full path to project dir on virtual machine */
$_SERVER['GUEST_DIR'] = '/var/www';

/** Full path to phpunit binary on virtual machine */
$_SERVER['TEST_RUNNER'] = $_SERVER['GUEST_DIR'] . '/takseo.dev/vendor/bin/phpunit';


if (!in_array($_SERVER['PHP_SELF'], array('-', $_SERVER['TEST_RUNNER']))) {
    $exit = 0;

    $map = function ($string) {
        return str_replace($_SERVER['HOST_DIR'], $_SERVER['GUEST_DIR'], $string);
    };

    $arg = array_map('escapeshellarg', $_SERVER['argv']);
    $arg = array_map($map, $arg);

    array_splice($arg, 0, 1, array(
        'cd', escapeshellarg($map(__DIR__)), '&&',
        'HOST_DIR=' . escapeshellarg($_SERVER['HOST_DIR']),
        escapeshellarg($_SERVER['TEST_RUNNER']),
        '--printer', 'PhpStormVagrantPhpUnit'
    ));

    $cmd = escapeshellarg(join(' ', $arg));

    chdir($_SERVER['VAGRANT_DIR']);
    passthru("vagrant ssh -c $cmd", $exit);

    exit($exit);
}

class PhpStormVagrantPhpUnit extends PHPUnit_TextUI_ResultPrinter implements PHPUnit_Framework_TestListener
{
    private $isSummaryTestCountPrinted = false;

    /** @var PHPUnit_Util_Printer $printer */
    private $printer = false;

    /**
     * @param PHPUnit_Util_Printer $printer
     */
    function __construct($printer)
    {
        $this->printer = $printer;
    }

    protected function writeProgress($progress)
    {
        //ignore
    }

    public function printResult(PHPUnit_Framework_TestResult $result)
    {
        $this->printHeader();
        $this->printFooter($result);
    }

    /**
     * @param  string $buffer
     */
    public function write($buffer)
    {
        print str_replace($_SERVER['GUEST_DIR'], $_SERVER['HOST_DIR'], $buffer);

        if ($this->autoFlush) {
            $this->incrementalFlush();
        }
    }

    private function printEvent($eventName, $params = array())
    {
        $this->printText("\n##teamcity[$eventName");
        foreach ($params as $key => $value) {
            $this->printText(" $key='$value'");
        }
        $this->printText("]\n");
    }

    private function printText($text)
    {
        $this->write($text);
    }

    private static function getMessage(Exception $e)
    {
        $message = "";
        if (strlen(get_class($e)) != 0) {
            $message = $message . get_class($e);
        }
        if (strlen($message) != 0 && strlen($e->getMessage()) != 0) {
            $message = $message . " : ";
        }
        $message = $message . $e->getMessage();
        return self::escapeValue($message);
    }

    private static function getDetails(Exception $e)
    {
        return self::escapeValue($e->getTraceAsString());
    }

    private static function getValueAsString($value)
    {
        if (is_null($value)) {
            return "null";
        } else if (is_bool($value)) {
            return $value == true ? "true" : "false";
        } else if (is_array($value) || is_string($value)) {
            $valueAsString = print_r($value, true);
            if (strlen($valueAsString) > 10000) {
                return null;
            }
            return $valueAsString;
        } else if (is_scalar($value)) {
            return print_r($value, true);
        }
        return null;
    }

    private static function escapeValue($text)
    {
        $text = str_replace("|", "||", $text);
        $text = str_replace("'", "|'", $text);
        $text = str_replace("\n", "|n", $text);
        $text = str_replace("\r", "|r", $text);
        $text = str_replace("]", "|]", $text);
        return $text;
    }

    private static function getFileName($className)
    {
        $reflectionClass = new ReflectionClass($className);
        $fileName = $reflectionClass->getFileName();
        return $fileName;
    }

    public function addError(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->printEvent("testFailed", array(
            "name" => $test->getName(),
            "message" => self::getMessage($e),
            "details" => self::getDetails($e)
        ));
    }

    public function addFailure(PHPUnit_Framework_Test $test, PHPUnit_Framework_AssertionFailedError $e, $time)
    {
        $params = array(
            "name" => $test->getName(),
            "message" => self::getMessage($e),
            "details" => self::getDetails($e)
        );
        if ($e instanceof PHPUnit_Framework_ExpectationFailedException) {
            $comparisonFailure = $e->getComparisonFailure();
            if ($comparisonFailure instanceof PHPUnit_Framework_ComparisonFailure) {
                $actualResult = $comparisonFailure->getActual();
                $expectedResult = $comparisonFailure->getExpected();
                $actualString = self::getValueAsString($actualResult);
                $expectedString = self::getValueAsString($expectedResult);
                if (!is_null($actualString) && !is_null($expectedString)) {
                    $params['actual'] = self::escapeValue($actualString);
                    $params['expected'] = self::escapeValue($expectedString);
                }
            }
        }
        $this->printEvent("testFailed", $params);
    }

    public function addIncompleteTest(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->printEvent("testIgnored", array(
            "name" => $test->getName(),
            "message" => self::getMessage($e),
            "details" => self::getDetails($e)
        ));
    }

    public function addSkippedTest(PHPUnit_Framework_Test $test, Exception $e, $time)
    {
        $this->printEvent("testIgnored", array(
            "name" => $test->getName(),
            "message" => self::getMessage($e),
            "details" => self::getDetails($e)
        ));
    }

    public function startTest(PHPUnit_Framework_Test $test)
    {
        $testName = $test->getName();
        $params = array(
            "name" => $testName
        );
        if ($test instanceof PHPUnit_Framework_TestCase) {
            $className = get_class($test);
            $fileName = self::getFileName($className);
            $params['locationHint'] = "php_qn://$fileName::\\$className::$testName";
        }
        $this->printEvent("testStarted", $params);
    }

    public function endTest(PHPUnit_Framework_Test $test, $time)
    {
        if ($test instanceof PHPUnit_Framework_TestCase) {
            $this->numAssertions += $test->getNumAssertions();
        }
        else if ($test instanceof PHPUnit_Extensions_PhptTestCase) {
            $this->numAssertions++;
        }
        $this->printEvent("testFinished", array(
            "name" => $test->getName(),
            "duration" => (int)(round($time, 2) * 1000)
        ));
    }

    public function startTestSuite(PHPUnit_Framework_TestSuite $suite)
    {
        if (!$this->isSummaryTestCountPrinted) {
            $this->isSummaryTestCountPrinted = true;
            //print tests count
            $this->printEvent("testCount", array(
                "count" => count($suite)
            ));
        }

        $suiteName = $suite->getName();
        if (empty($suiteName)) {
            return;
        }
        $params = array(
            "name" => $suiteName,
        );
        if (class_exists($suiteName, false)) {
            $fileName = self::getFileName($suiteName);
            $params['locationHint'] = "php_qn://$fileName::\\$suiteName";
        }
        $this->printEvent("testSuiteStarted", $params);
    }

    public function endTestSuite(PHPUnit_Framework_TestSuite $suite)
    {
        $suiteName = $suite->getName();
        if (empty($suiteName)) {
            return;
        }
        $this->printEvent("testSuiteFinished",
            array(
                "name" => $suite->getName()
            ));
    }
}

<?php
namespace Koalamon\Extension;

use Codeception\Event\StepEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Extension;
use Codeception\Step;
use GuzzleHttp\Client;
use Koalamon\Client\Reporter\Event;
use Koalamon\Client\Reporter\Reporter;

class KoalamonReporter extends Extension
{

    private $testCollections = [];

    private $penultimateStep;
    private $lastStep;

    public static $events = [
        Events::TEST_FAIL => 'fail',
        Events::TEST_SUCCESS => 'success',
        Events::TEST_ERROR => 'error',
        Events::SUITE_AFTER => 'suite',
        Events::STEP_BEFORE => 'step'
    ];

    private function testCollectionToName(TestEvent $test)
    {
        return $this->stringToText(str_replace('Cest.php', '', basename($test->getTest()->getTestFileName($test->getTest()))));
    }

    private function testToName(TestEvent $test)
    {
        return $this->stringToText($test->getTest()->getName(false));
    }

    private function stringToText($string)
    {
        return ucfirst(ltrim(strtolower(preg_replace('/[A-Z]/', ' $0', $string)), ' '));
    }

    public function _initialize()
    {
        if (!array_key_exists('api_key', $this->config)) {
            throw new \RuntimeException('KoalamonReporterExtension: api_key not set');
        }
        if (!array_key_exists('system', $this->config)) {
            throw new \RuntimeException('KoalamonReporterExtension: system not set');
        }
    }

    public function step(StepEvent $stepEvent)
    {
        $this->penultimateStep = $this->lastStep;
        $this->lastStep = $stepEvent->getStep()->getPhpCode();
    }

    public function success(TestEvent $test)
    {
        $testCollectionName = $this->testCollectionToName($test);
        if (!array_key_exists($testCollectionName, $this->testCollections)) {
            $this->testCollections[$testCollectionName] = array('file' => $test->getTest()->getTestFileName($test->getTest()), 'tests' => []);
        }
    }

    public function fail(TestEvent $test)
    {
        $testCollectionName = $this->testCollectionToName($test);
        $testName = $this->testToName($test);

        if (!array_key_exists($testCollectionName, $this->testCollections)) {
            $this->testCollections[$testCollectionName] = array('file' => $test->getTest()->getTestFileName($test->getTest()), 'tests' => []);
        }

        if (strpos($this->penultimateStep, '//') === 0) {
            $testName = substr($this->penultimateStep, 3) . '<br>Test Name:' . $testName . ')';
        }

        $this->testCollections[$testCollectionName]['tests'][] = ['name' => $testName, 'lastStep' => $this->lastStep];
    }

    public function error(TestEvent $test)
    {
        $this->fail($test);
    }

    public function suite()
    {
        $koalamonServer = 'http://www.koalamon.com';
        if (array_key_exists('server', $this->config)) {
            $koalamonServer = $this->config['server'];
        }
        $reporter = new Reporter('', $this->config['api_key'], new Client(), $koalamonServer);

        $url = '';
        if (array_key_exists('url', $this->config)) {
            $url = $this->config['url'];
        }

        $tool = 'Codeception';
        if (array_key_exists('tool', $this->config)) {
            $tool = $this->config['tool'];
        }

        foreach ($this->testCollections as $testCollection => $testConfigs) {
            $failed = false;
            $message = "Failed running '" . $testCollection . "' in " . basename($testConfigs['file']) . "<ul>";
            foreach ($testConfigs['tests'] as $testName) {
                $failed = true;
                $message .= '<li>' . $testName['name'] . " <br>Step: " . $testName['lastStep'] . ').</li>';
            }
            $message .= '</ul>';

            if ($failed) {
                $status = Event::STATUS_FAILURE;
            } else {
                $status = Event::STATUS_SUCCESS;
                $message = '';
            }

            $event = new Event('Codeception_' . $testCollection, $this->config['system'], $status, $tool, $message, '', $url);
            $reporter->sendEvent($event);
        }
    }
}

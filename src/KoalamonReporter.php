<?php
namespace Koalamon\Extension;

use Codeception\Event\StepEvent;
use Codeception\Event\TestEvent;
use Codeception\Events;
use Codeception\Exception\ConfigurationException;
use Codeception\Extension;
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
        return $this->stringToText(str_replace('Cest.php', '', basename($test->getTest()->getFileName($test->getTest()))));
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
        if (!array_key_exists('api_key', $this->config) && !getenv('KOALAMON_API_KEY')) {
            throw new \RuntimeException('KoalamonReporterExtension: api_key in config or KOALAMON_API_KEY environment variable not set');
        }
        if (!array_key_exists('system', $this->config) && !getenv('KOALAMON_SYSTEM')) {
            throw new \RuntimeException('KoalamonReporterExtension: system in config or KOALAMON_SYSTEM environment variable not set.');
        }
    }

    public function step(StepEvent $stepEvent)
    {
        $this->penultimateStep = $this->lastStep;
        $this->lastStep = $stepEvent->getStep()->getPhpCode(256);
    }

    public function success(TestEvent $test)
    {
        $testCollectionName = $this->testCollectionToName($test);
        if (!array_key_exists($testCollectionName, $this->testCollections)) {
            $this->testCollections[$testCollectionName] = array('file' => $test->getTest()->getFileName($test->getTest()), 'tests' => []);
        }
    }

    public function fail(TestEvent $test)
    {
        $testCollectionName = $this->testCollectionToName($test);
        $testName = $this->testToName($test);

        if (!array_key_exists($testCollectionName, $this->testCollections)) {
            $this->testCollections[$testCollectionName] = array('file' => $test->getTest()->getFileName($test->getTest()), 'tests' => []);
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

    private function getSystem()
    {
        if (getenv('KOALAMON_SYSTEM')) {
            $system = getenv('KOALAMON_SYSTEM');
        } elseif (isset($this->config['system'])) {
            $system = $this->config['system'];
        } else {
            $message = 'Please set a koalamon system as environment variable KOALAMON_SYSTEM or in the extension config as "system_identifier"';
            throw new ConfigurationException($message);
        }

        return $system;
    }

    private function getApiKey()
    {
        if (getenv('KOALAMON_API_KEY')) {
            $apiKey = getenv('KOALAMON_API_KEY');
        } elseif (isset($this->config['api_key'])) {
            $apiKey = $this->config['api_key'];
        } else {
            $message = 'Please set a koalamon apikey as environment variable KOALAMON_API_KEY or in the extension config as "api_key"';
            throw new ConfigurationException($message);
        }

        return $apiKey;
    }

    private function getUrl()
    {
        $url = '';
        if (getenv('KOALAMON_URL')) {
            $url = getenv('KOALAMON_URL');
        } elseif (array_key_exists('url', $this->config)) {
            $url = $this->config['url'];
        }
        return $url;
    }

    public function suite()
    {
        $tool = 'Codeception';
        $koalamonServer = 'https://webhook.koalamon.com';

        if (array_key_exists('server', $this->config)) {
            $koalamonServer = $this->config['server'];
        }

        $reporter = new Reporter('', $this->getApiKey(), new Client(), $koalamonServer);

        $url = $this->getUrl();

        if (array_key_exists('tool', $this->config)) {
            $tool = $this->config['tool'];
        }

        foreach ($this->testCollections as $testCollection => $testConfigs) {
            $failed = false;
            $message = "Failed running '" . $testCollection . "' in " . basename($testConfigs['file']) . "<ul>";
            foreach ($testConfigs['tests'] as $testName) {
                $failed = true;
                $message .= '<li>' . $testName['name'] . ' <br><pre>Step: ' . $testName['lastStep'] . '.</pre></li>';
            }
            $message .= '</ul>';


            if ($failed) {
                $status = Event::STATUS_FAILURE;
            } else {
                $status = Event::STATUS_SUCCESS;
                $message = '';
            }

            $system = $this->getSystem();

            $eventIdentifier = $tool . '_' . $system . '_' . $testCollection;

            $event = new Event($eventIdentifier, $system, $status, $tool, $message, null, $url);
            $reporter->sendEvent($event);
        }
    }
}

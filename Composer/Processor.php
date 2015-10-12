<?php

namespace Symbio\OrangeGate\SearchBundle\Composer;

use Composer\IO\IOInterface;
use Symfony\Component\Yaml\Inline;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

class Processor
{
    private $io;
    private $endPointName;

    public function __construct(IOInterface $io)
    {
        $this->io = $io;
    }

    public function processFile(array $config)
    {
        $config = $this->processConfig($config);

        $realFile = $config['file'];
        $distFile = $config['dist-file'];
        $parameterKey = $config['parameter-key'];

        $exists = is_file($realFile);
        $yamlParser = new Parser();

        if (!$exists) {
            if ($this->checkPermissionToInstall()) {
                $this->io->write(sprintf('<info>%s the "%s" file</info>', 'Creating', $realFile));

                // Find the expected params
                $expectedValues = $yamlParser->parse(file_get_contents($distFile));
                if (!isset($expectedValues[$parameterKey])) {
                    throw new \InvalidArgumentException(sprintf('The top-level key %s is missing.', $parameterKey));
                }
                $expectedParams = (array) $expectedValues[$parameterKey]['endpoints'];

                // find the actual params
                $actualValues = array_merge(
                // Preserve other top-level keys than `$parameterKey` in the file
                    $expectedValues,
                    array($parameterKey => array('endpoints' => array()))
                );

                $actualValues[$parameterKey]['endpoints'] = $this->processParams($config, $expectedParams, (array) $actualValues[$parameterKey]['endpoints']);

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, 'http://'.$actualValues[$parameterKey]['endpoints'][$this->getEndPointName()]['host'].':'.$actualValues[$parameterKey]['endpoints'][$this->getEndPointName()]['port'].$actualValues[$parameterKey]['endpoints'][$this->getEndPointName()]['path'].'/admin/cores?action=CREATE&name='.$actualValues[$parameterKey]['endpoints'][$this->getEndPointName()]['core'].'&configSet=configset1');
                curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
                curl_exec($ch);
                curl_close($ch);

                $this->processWriteValuesToFile($actualValues, $realFile);
                $this->importToConfigFile($realFile);
            } else {
                $this->processWriteValuesToFile($yamlParser->parse(file_get_contents($distFile)), $realFile);
            }
        }
    }

    private function processConfig(array $config)
    {
        if (empty($config['file'])) {
            throw new \InvalidArgumentException('The extra.symbio-solr-parameters.file setting is required to use this script handler.');
        }

        if (empty($config['dist-file'])) {
            $config['dist-file'] = $config['file'].'.dist';
        }

        if (!is_file($config['dist-file'])) {
            throw new \InvalidArgumentException(sprintf('The dist file "%s" does not exist. Check your dist-file config or create it.', $config['dist-file']));
        }

        if (empty($config['parameter-key'])) {
            $config['parameter-key'] = 'fs_solr';
        }

        return $config;
    }

    private function processParams(array $config, array $expectedParams, array $actualParams)
    {
        // Grab values for parameters that were renamed
        $renameMap = empty($config['rename-map']) ? array() : (array) $config['rename-map'];
        $actualParams = array_replace($actualParams, $this->processRenamedValues($renameMap, $actualParams));

        $keepOutdatedParams = false;
        if (isset($config['keep-outdated'])) {
            $keepOutdatedParams = (boolean) $config['keep-outdated'];
        }

        if (!$keepOutdatedParams) {
            $actualParams = array_intersect_key($actualParams, $expectedParams);
        }

        $envMap = empty($config['env-map']) ? array() : (array) $config['env-map'];

        // Add the params coming from the environment values
        $actualParams = array_replace($actualParams, $this->getEnvValues($envMap));

        return $this->getParams($expectedParams, $actualParams, $config);
    }

    private function getEnvValues(array $envMap)
    {
        $params = array();
        foreach ($envMap as $param => $env) {
            $value = getenv($env);
            if ($value) {
                $params[$param] = Inline::parse($value);
            }
        }

        return $params;
    }

    private function processRenamedValues(array $renameMap, array $actualParams)
    {
        foreach ($renameMap as $param => $oldParam) {
            if (array_key_exists($param, $actualParams)) {
                continue;
            }

            if (!array_key_exists($oldParam, $actualParams)) {
                continue;
            }

            $actualParams[$param] = $actualParams[$oldParam];
        }

        return $actualParams;
    }

    private function getParams(array $expectedParams, array $actualParams, array $config)
    {
        // Simply use the expectedParams value as default for the missing params.
        if (!$this->io->isInteractive()) {
            return array_replace($expectedParams, $actualParams);
        }

        $isStarted = false;

        $endpointName = $this->io->ask("<question>Please provide name of new endpoint</question> [<comment>core1</comment>]", 'core1');
        $this->setEndpointName($endpointName);
        $actualParams[$endpointName] = array();

        foreach ($expectedParams['core1'] as $key => $message) {
            if (array_key_exists($key, $actualParams[$endpointName])) {
                continue;
            }

            if (!$isStarted) {
                $isStarted = true;
                $this->io->write('<comment>Some parameters are missing. Please provide them.</comment>');
            }

            $default = Inline::dump($message);
            if ($key === "core") {
                $this->io->write('<comment>Please provide the name of core. It should be the name of your project.</comment>');
            }
            $value = $this->io->ask(sprintf('<question>%s</question> (<comment>%s</comment>): ', $key, $default), $default);

            $actualParams[$endpointName][$key] = Inline::parse($value);
        }

        return $actualParams;
    }

    private function checkPermissionToInstall()
    {
        $value = $this->io->ask("<question>Would you like connect OrangeGate with solr ?</question> [<comment>yes</comment>] ", 'yes');

        return Inline::parse($value) === "yes" ? true : false;
    }

    private function processWriteValuesToFile(array $values, $realFile)
    {
        if (!is_dir($dir = dirname($realFile))) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($realFile, "# This file is auto-generated during the composer install\n" . Yaml::dump($values, 99));
    }

    private function importToConfigFile($importFileName)
    {
        $configFileName = "app/config/config.yml";
        $entry = '    - { resource: '.str_replace('app/config/', '', $importFileName).' }';
        $configFileValues = explode("\n", file_get_contents($configFileName), 2);
        file_put_contents($configFileName, $configFileValues[0]."\n".$entry."\n".$configFileValues[1]);

        $this->io->write(sprintf('<info>"%s" was successfully imported into "%s"</info>', $importFileName, $configFileName));
    }


    public function setEndPointName($endPointName)
    {
        $this->endPointName = $endPointName;
    }

    public function getEndPointName()
    {
        return $this->endPointName;
    }
}

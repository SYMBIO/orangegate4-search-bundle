<?php

namespace Symbio\OrangeGate\SearchBundle\Composer;

use Composer\Script\Event;

class ScriptHandler
{
    public static function configureSolr(Event $event)
    {
        $extras = $event->getComposer()->getPackage()->getExtra();

        if (!isset($extras['symbio-solr-parameters'])) {
            throw new \InvalidArgumentException('The parameter handler needs to be configured through the extra.symbio-solr-parameters setting.');
        }

        $configs = $extras['symbio-solr-parameters'];

        if (!is_array($configs)) {
            throw new \InvalidArgumentException('The extra.symbio-solr-parameters setting must be an array or a configuration object.');
        }

        if (array_keys($configs) !== range(0, count($configs) - 1)) {
            $configs = array($configs);
        }

        $processor = new Processor($event->getIO());

        foreach ($configs as $config) {
            if (!is_array($config)) {
                throw new \InvalidArgumentException('The extra.symbio-solr-parameters setting must be an array of configuration objects.');
            }

            $processor->processFile($config);
        }
    }
}

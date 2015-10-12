<?php

namespace Symbio\OrangeGate\SearchBundle\Listener;

use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\Common\Annotations\AnnotationReader;
use FS\SolrBundle\Solr;

class PostPersistListener
{
    private $solrClient;
    private $logger;

    public function postPersist(LifecycleEventArgs $args)
    {
        $reader = new AnnotationReader();
        $entity = $args->getEntity();
        $reflectionClass = new \ReflectionClass(get_class($entity));
        $classAnnotations = $reader->getClassAnnotations($reflectionClass);

        foreach ($classAnnotations as $annotation) {
            if ($annotation instanceof \FS\SolrBundle\Doctrine\Annotation\Document) {
                $this->logger->info('Synchornizing entity "'.get_class($entity).'" with id:'.$entity->getId());
                try {
                    $this->solrClient->synchronizeIndex($entity);
                } catch(\Exception $e) {
                    $this->logger->error('Error: '.$e->getCode().' - '.$e->getMessage());
                }

                $this->logger->info('Entity "'.get_class($entity).'" with id:'.$entity->getId().' was successfully synchronized.');
                break;
            }
        }
    }

    public function setSolrClient(Solr $solrClient)
    {
        $this->solrClient = $solrClient;
    }

    public function setLogger(\Symfony\Bridge\Monolog\Logger $logger)
    {
        $this->logger = $logger;
    }
}
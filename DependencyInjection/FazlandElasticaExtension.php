<?php

namespace Fazland\ElasticaBundle\DependencyInjection;

use InvalidArgumentException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class FazlandElasticaExtension extends Extension
{
    /**
     * Definition of elastica clients as configured by this extension.
     *
     * @var array
     */
    private $clients = [];

    /**
     * An array of indexes as configured by the extension.
     *
     * @var array
     */
    private $indexConfigs = [];

    /**
     * If we've encountered a type mapped to a specific persistence driver, it will be loaded
     * here.
     *
     * @var array
     */
    private $loadedDrivers = [];

    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = $this->getConfiguration($configs, $container);
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));

        if (empty($config['clients']) || empty($config['indexes'])) {
            // No Clients or indexes are defined
            return;
        }

        foreach (['config', 'index', 'persister', 'provider', 'source', 'transformer'] as $basename) {
            $loader->load(sprintf('%s.xml', $basename));
        }

        if (empty($config['default_client'])) {
            $keys = array_keys($config['clients']);
            $config['default_client'] = reset($keys);
        }

        if (empty($config['default_index'])) {
            $keys = array_keys($config['indexes']);
            $config['default_index'] = reset($keys);
        }

        if (isset($config['serializer'])) {
            $loader->load('serializer.xml');

            $this->loadSerializer($config['serializer'], $container);
        }

        $this->loadClients($config['clients'], $container);
        $container->setAlias('fazland_elastica.client', sprintf('fazland_elastica.client.%s', $config['default_client']));

        $this->loadIndexes($config['indexes'], $container);
        $container->setAlias('fazland_elastica.index', sprintf('fazland_elastica.index.%s', $config['default_index']));

        $container->getDefinition('fazland_elastica.config_source.container')->replaceArgument(0, $this->indexConfigs);

        $this->loadIndexManager($container);

        $this->createDefaultManagerAlias($config['default_manager'], $container);
    }

    /**
     * @param array            $config
     * @param ContainerBuilder $container
     *
     * @return Configuration
     */
    public function getConfiguration(array $config, ContainerBuilder $container)
    {
        return new Configuration($container->getParameter('kernel.debug'));
    }

    /**
     * Loads the configured clients.
     *
     * @param array            $clients   An array of clients configurations
     * @param ContainerBuilder $container A ContainerBuilder instance
     *
     * @return array
     */
    private function loadClients(array $clients, ContainerBuilder $container)
    {
        foreach ($clients as $name => $clientConfig) {
            $clientId = sprintf('fazland_elastica.client.%s', $name);

            $clientDef = new DefinitionDecorator('fazland_elastica.client_prototype');
            $clientDef->replaceArgument(0, $clientConfig);

            $logger = $clientConfig['connections'][0]['logger'];
            if (false !== $logger) {
                $clientDef->addMethodCall('setLogger', [new Reference($logger)]);
            }

            $clientDef->addTag('fazland_elastica.client');

            $container->setDefinition($clientId, $clientDef);

            $this->clients[$name] = [
                'id' => $clientId,
                'reference' => new Reference($clientId),
            ];
        }
    }

    /**
     * Loads the configured indexes.
     *
     * @param array            $indexes   An array of indexes configurations
     * @param ContainerBuilder $container A ContainerBuilder instance
     *
     * @throws \InvalidArgumentException
     *
     * @return array
     */
    private function loadIndexes(array $indexes, ContainerBuilder $container)
    {
        $indexableCallbacks = [];

        foreach ($indexes as $name => $index) {
            $indexId = sprintf('fazland_elastica.index.%s', $name);
            $indexName = isset($index['index_name']) ? $index['index_name'] : $name;

            $indexDef = new DefinitionDecorator('fazland_elastica.index_prototype');
            $indexDef->setFactory([new Reference('fazland_elastica.client'), 'getIndex']);
            $indexDef->replaceArgument(0, $indexName);
            $indexDef->addTag('fazland_elastica.index', [
                'name' => $name,
            ]);

            if (isset($index['client'])) {
                $client = $this->getClient($index['client']);

                $indexDef->setFactory([$client, 'getIndex']);
            }

            $container->setDefinition($indexId, $indexDef);
            $reference = new Reference($indexId);

            $this->indexConfigs[$name] = [
                'elasticsearch_name' => $indexName,
                'reference' => $reference,
                'name' => $name,
                'settings' => $index['settings'],
                'type_prototype' => isset($index['type_prototype']) ? $index['type_prototype'] : [],
                'use_alias' => $index['use_alias'],
            ];

            if ($index['finder']) {
                $this->loadIndexFinder($container, $name, $reference);
            }

            $this->loadTypes((array) $index['types'], $container, $this->indexConfigs[$name], $indexableCallbacks);
        }

        $indexable = $container->getDefinition('fazland_elastica.indexable');
        $indexable->replaceArgument(0, $indexableCallbacks);
    }

    /**
     * Loads the configured index finders.
     *
     * @param \Symfony\Component\DependencyInjection\ContainerBuilder $container
     * @param string                                                  $name      The index name
     * @param Reference                                               $index     Reference to the related index
     *
     * @return string
     */
    private function loadIndexFinder(ContainerBuilder $container, $name, Reference $index)
    {
        /* Note: transformer services may conflict with "collection.index", if
         * an index and type names were "collection" and an index, respectively.
         */
        $transformerId = sprintf('fazland_elastica.elastica_to_model_transformer.collection.%s', $name);
        $transformerDef = new DefinitionDecorator('fazland_elastica.elastica_to_model_transformer.collection');
        $container->setDefinition($transformerId, $transformerDef);

        $finderId = sprintf('fazland_elastica.finder.%s', $name);
        $finderDef = new DefinitionDecorator('fazland_elastica.finder');
        $finderDef->replaceArgument(0, $index);
        $finderDef->replaceArgument(1, new Reference($transformerId));

        $container->setDefinition($finderId, $finderDef);
    }

    /**
     * Loads the configured types.
     *
     * @param array            $types
     * @param ContainerBuilder $container
     * @param array            $indexConfig
     * @param array            $indexableCallbacks
     */
    private function loadTypes(array $types, ContainerBuilder $container, array $indexConfig, array &$indexableCallbacks)
    {
        foreach ($types as $name => $type) {
            $indexName = $indexConfig['name'];

            $typeId = sprintf('%s.%s', $indexConfig['reference'], $name);
            $typeDef = new DefinitionDecorator('fazland_elastica.type_prototype');
            $typeDef->setFactory([$indexConfig['reference'], 'getType']);
            $typeDef->replaceArgument(0, $name);

            $container->setDefinition($typeId, $typeDef);

            $typeConfig = [
                'name' => $name,
                'mapping' => [], // An array containing anything that gets sent directly to ElasticSearch
                'config' => [],
            ];

            foreach ([
                'dynamic_templates',
                'properties',
                '_all',
                '_boost',
                '_id',
                '_parent',
                '_routing',
                '_source',
                '_timestamp',
                '_ttl',
            ] as $field) {
                if (isset($type[$field])) {
                    $typeConfig['mapping'][$field] = $type[$field];
                }
            }

            foreach ([
                'persistence',
                'serializer',
                'analyzer',
                'search_analyzer',
                'dynamic',
                'date_detection',
                'dynamic_date_formats',
                'numeric_detection',
            ] as $field) {
                $typeConfig['config'][$field] = array_key_exists($field, $type) ?
                    $type[$field] :
                    null;
            }

            $this->indexConfigs[$indexName]['types'][$name] = $typeConfig;

            if (isset($type['persistence'])) {
                $this->loadTypePersistenceIntegration($type['persistence'], $container, new Reference($typeId), $indexName, $name);

                $typeConfig['persistence'] = $type['persistence'];
            }

            if (isset($type['_parent'])) {
                // _parent mapping cannot contain `property` and `identifier`, so removing them after building `persistence`
                unset($indexConfig['types'][$name]['mapping']['_parent']['property'], $indexConfig['types'][$name]['mapping']['_parent']['identifier']);
            }

            if (isset($type['indexable_callback'])) {
                $indexableCallbacks[sprintf('%s/%s', $indexName, $name)] = $type['indexable_callback'];
            }

            if ($container->hasDefinition('fazland_elastica.serializer_callback_prototype')) {
                $typeSerializerId = sprintf('%s.serializer.callback', $typeId);
                $typeSerializerDef = new DefinitionDecorator('fazland_elastica.serializer_callback_prototype');

                if (isset($type['serializer']['groups'])) {
                    $typeSerializerDef->addMethodCall('setGroups', [$type['serializer']['groups']]);
                }

                if (isset($type['serializer']['serialize_null'])) {
                    $typeSerializerDef->addMethodCall('setSerializeNull', [$type['serializer']['serialize_null']]);
                }

                if (isset($type['serializer']['version'])) {
                    $typeSerializerDef->addMethodCall('setVersion', [$type['serializer']['version']]);
                }

                $typeDef->addMethodCall('setSerializer', [[new Reference($typeSerializerId), 'serialize']]);
                $container->setDefinition($typeSerializerId, $typeSerializerDef);
            }
        }
    }

    /**
     * Loads the optional provider and finder for a type.
     *
     * @param array            $typeConfig
     * @param ContainerBuilder $container
     * @param Reference        $typeRef
     * @param string           $indexName
     * @param string           $typeName
     */
    private function loadTypePersistenceIntegration(array $typeConfig, ContainerBuilder $container, Reference $typeRef, $indexName, $typeName)
    {
        if (isset($typeConfig['driver'])) {
            $this->loadDriver($container, $typeConfig['driver']);
            $elasticaToModelTransformerId = $this->loadElasticaToModelTransformer($typeConfig, $container, $indexName, $typeName);
            $modelToElasticaTransformerId = $this->loadModelToElasticaTransformer($typeConfig, $container, $typeRef, $indexName, $typeName);
            $objectPersisterId = $this->loadObjectPersister($typeConfig, $typeRef, $container, $indexName, $typeName, $modelToElasticaTransformerId);
        } else {
            $elasticaToModelTransformerId = null;
            $objectPersisterId = null;
        }

        if (isset($typeConfig['provider'])) {
            $this->loadTypeProvider($typeConfig, $container, $objectPersisterId, $indexName, $typeName);
        }
        if (isset($typeConfig['finder'])) {
            $this->loadTypeFinder($typeConfig, $container, $elasticaToModelTransformerId, $typeRef, $indexName, $typeName);
        }
        if (isset($typeConfig['listener'])) {
            $this->loadTypeListener($typeConfig, $container, $objectPersisterId, $indexName, $typeName);
        }
    }

    /**
     * Creates and loads an ElasticaToModelTransformer.
     *
     * @param array            $typeConfig
     * @param ContainerBuilder $container
     * @param string           $indexName
     * @param string           $typeName
     *
     * @return string
     */
    private function loadElasticaToModelTransformer(array $typeConfig, ContainerBuilder $container, $indexName, $typeName)
    {
        if (isset($typeConfig['elastica_to_model_transformer']['service'])) {
            return $typeConfig['elastica_to_model_transformer']['service'];
        }

        /* Note: transformer services may conflict with "prototype.driver", if
         * the index and type names were "prototype" and a driver, respectively.
         */
        $abstractId = sprintf('fazland_elastica.elastica_to_model_transformer.prototype.%s', $typeConfig['driver']);
        $serviceId = sprintf('fazland_elastica.elastica_to_model_transformer.%s.%s', $indexName, $typeName);
        $serviceDef = new DefinitionDecorator($abstractId);
        $serviceDef->addTag('fazland_elastica.elastica_to_model_transformer', ['type' => $typeName, 'index' => $indexName]);

        // Doctrine has a mandatory service as first argument
        $argPos = ('propel' === $typeConfig['driver']) ? 0 : 1;

        $serviceDef->replaceArgument($argPos, $typeConfig['model']);
        $serviceDef->replaceArgument($argPos + 1, array_merge($typeConfig['elastica_to_model_transformer'], [
            'identifier' => $typeConfig['identifier'],
        ]));
        $container->setDefinition($serviceId, $serviceDef);

        return $serviceId;
    }

    /**
     * Creates and loads a ModelToElasticaTransformer for an index/type.
     *
     * @param array $typeConfig
     * @param ContainerBuilder $container
     * @param Reference $typeRef
     * @param string $indexName
     * @param string $typeName
     *
     * @return string
     */
    private function loadModelToElasticaTransformer(array $typeConfig, ContainerBuilder $container, Reference $typeRef, $indexName, $typeName)
    {
        if (isset($typeConfig['model_to_elastica_transformer']['service'])) {
            return $typeConfig['model_to_elastica_transformer']['service'];
        }

        $abstractId = $container->hasDefinition('fazland_elastica.serializer_callback_prototype') ?
            'fazland_elastica.model_to_elastica_identifier_transformer' :
            'fazland_elastica.model_to_elastica_transformer';

        $serviceId = sprintf('fazland_elastica.model_to_elastica_transformer.%s.%s', $indexName, $typeName);
        $serviceDef = new DefinitionDecorator($abstractId);
        $serviceDef->replaceArgument(1, [
            'identifier' => $typeConfig['identifier'],
        ]);
        $serviceDef->replaceArgument(0, $typeRef);
        $container->setDefinition($serviceId, $serviceDef);

        return $serviceId;
    }

    /**
     * Creates and loads an object persister for a type.
     *
     * @param array            $typeConfig
     * @param Reference        $typeRef
     * @param ContainerBuilder $container
     * @param string           $indexName
     * @param string           $typeName
     * @param string           $transformerId
     *
     * @return string
     */
    private function loadObjectPersister(array $typeConfig, Reference $typeRef, ContainerBuilder $container, $indexName, $typeName, $transformerId)
    {
        if (isset($typeConfig['persister']['service'])) {
            return $typeConfig['persister']['service'];
        }

        if (!isset($typeConfig['model'])) {
            return null;
        }

        $arguments = [
            $typeRef,
            new Reference($transformerId),
            $typeConfig['model'],
        ];

        if ($container->hasDefinition('fazland_elastica.serializer_callback_prototype')) {
            $abstractId = 'fazland_elastica.object_serializer_persister';
            $callbackId = sprintf('%s.%s.serializer.callback', $this->indexConfigs[$indexName]['reference'], $typeName);
            $arguments[] = [new Reference($callbackId), 'serialize'];
        } else {
            $abstractId = 'fazland_elastica.object_persister';
            $mapping = $this->indexConfigs[$indexName]['types'][$typeName]['mapping'];
            $argument = $mapping['properties'];
            if (isset($mapping['_parent'])) {
                $argument['_parent'] = $mapping['_parent'];
            }
            $arguments[] = $argument;
        }

        $serviceId = sprintf('fazland_elastica.object_persister.%s.%s', $indexName, $typeName);
        $serviceDef = new DefinitionDecorator($abstractId);
        foreach ($arguments as $i => $argument) {
            $serviceDef->replaceArgument($i, $argument);
        }

        $container->setDefinition($serviceId, $serviceDef);

        return $serviceId;
    }

    /**
     * Loads a provider for a type.
     *
     * @param array            $typeConfig
     * @param ContainerBuilder $container
     * @param string           $objectPersisterId
     * @param string           $indexName
     * @param string           $typeName
     */
    private function loadTypeProvider(array $typeConfig, ContainerBuilder $container, $objectPersisterId, $indexName, $typeName)
    {
        if (null === $objectPersisterId || isset($typeConfig['provider']['service'])) {
            return;
        }

        /* Note: provider services may conflict with "prototype.driver", if the
         * index and type names were "prototype" and a driver, respectively.
         */
        $providerId = sprintf('fazland_elastica.provider.%s.%s', $indexName, $typeName);
        $providerDef = new DefinitionDecorator('fazland_elastica.provider.prototype.'.$typeConfig['driver']);
        $providerDef->addTag('fazland_elastica.provider', ['index' => $indexName, 'type' => $typeName]);
        $providerDef->replaceArgument(0, new Reference($objectPersisterId));
        $providerDef->replaceArgument(2, $typeConfig['model']);
        // Propel provider can simply ignore Doctrine-specific options
        $providerDef->replaceArgument(3, array_merge(array_diff_key($typeConfig['provider'], ['service' => 1]), [
            'indexName' => $indexName,
            'typeName' => $typeName,
        ]));
        $container->setDefinition($providerId, $providerDef);
    }

    /**
     * Loads doctrine listeners to handle indexing of new or updated objects.
     *
     * @param array            $typeConfig
     * @param ContainerBuilder $container
     * @param string           $objectPersisterId
     * @param string           $indexName
     * @param string           $typeName
     */
    private function loadTypeListener(array $typeConfig, ContainerBuilder $container, $objectPersisterId, $indexName, $typeName)
    {
        if (null === $objectPersisterId || isset($typeConfig['listener']['service'])) {
            return;
        }

        /* Note: listener services may conflict with "prototype.driver", if the
         * index and type names were "prototype" and a driver, respectively.
         */
        $abstractListenerId = sprintf('fazland_elastica.listener.prototype.%s', $typeConfig['driver']);
        $listenerId = sprintf('fazland_elastica.listener.%s.%s', $indexName, $typeName);
        $listenerDef = new DefinitionDecorator($abstractListenerId);
        $listenerDef->replaceArgument(0, new Reference($objectPersisterId));
        $listenerDef->replaceArgument(2, [
            'identifier' => $typeConfig['identifier'],
            'indexName' => $indexName,
            'typeName' => $typeName,
        ]);
        $listenerDef->replaceArgument(3, $typeConfig['listener']['logger'] ?
            new Reference($typeConfig['listener']['logger']) :
            null
        );

        $tagName = null;
        switch ($typeConfig['driver']) {
            case 'orm':
                $tagName = 'doctrine.event_listener';
                break;
            case 'phpcr':
                $tagName = 'doctrine_phpcr.event_listener';
                break;
            case 'mongodb':
                $tagName = 'doctrine_mongodb.odm.event_listener';
                break;
        }

        if (null !== $tagName) {
            foreach ($this->getDoctrineEvents($typeConfig) as $event) {
                $listenerDef->addTag($tagName, ['event' => $event]);
            }
        }

        $container->setDefinition($listenerId, $listenerDef);
    }

    /**
     * Map Elastica to Doctrine events for the current driver.
     */
    private function getDoctrineEvents(array $typeConfig)
    {
        switch ($typeConfig['driver']) {
            case 'orm':
                $eventsClass = '\Doctrine\ORM\Events';
                break;
            case 'phpcr':
                $eventsClass = '\Doctrine\ODM\PHPCR\Event';
                break;
            case 'mongodb':
                $eventsClass = '\Doctrine\ODM\MongoDB\Events';
                break;
            default:
                throw new InvalidArgumentException(sprintf('Cannot determine events for driver "%s"', $typeConfig['driver']));
        }

        $events = [];
        $eventMapping = [
            'insert' => [constant($eventsClass.'::postPersist')],
            'update' => [constant($eventsClass.'::postUpdate')],
            'delete' => [constant($eventsClass.'::preRemove')],
            'flush' => [constant($eventsClass.'::postFlush')],
        ];

        foreach ($eventMapping as $event => $doctrineEvents) {
            if (isset($typeConfig['listener'][$event]) && $typeConfig['listener'][$event]) {
                $events = array_merge($events, $doctrineEvents);
            }
        }

        return $events;
    }

    /**
     * Loads a Type specific Finder.
     *
     * @param array            $typeConfig
     * @param ContainerBuilder $container
     * @param string           $elasticaToModelId
     * @param Reference        $typeRef
     * @param string           $indexName
     * @param string           $typeName
     *
     * @return string
     */
    private function loadTypeFinder(array $typeConfig, ContainerBuilder $container, $elasticaToModelId, Reference $typeRef, $indexName, $typeName)
    {
        if (isset($typeConfig['finder']['service'])) {
            $finderId = $typeConfig['finder']['service'];
        } else {
            $finderId = sprintf('fazland_elastica.finder.%s.%s', $indexName, $typeName);
            $finderDef = new DefinitionDecorator('fazland_elastica.finder');
            $finderDef->replaceArgument(0, $typeRef);
            $finderDef->replaceArgument(1, new Reference($elasticaToModelId));
            $container->setDefinition($finderId, $finderDef);
        }

        $indexTypeName = "$indexName/$typeName";
        $arguments = [$indexTypeName, new Reference($finderId)];
        if (isset($typeConfig['repository'])) {
            $arguments[] = $typeConfig['repository'];
        }

        $container->getDefinition('fazland_elastica.repository_manager')
            ->addMethodCall('addType', $arguments);

        if (isset($typeConfig['driver'])) {
            $managerId = sprintf('fazland_elastica.manager.%s', $typeConfig['driver']);
            $container->getDefinition($managerId)
                ->addMethodCall('addEntity', [$typeConfig['model'], $indexTypeName]);
        }

        return $finderId;
    }

    /**
     * Loads the index manager.
     *
     * @param ContainerBuilder $container
     **/
    private function loadIndexManager(ContainerBuilder $container)
    {
        $indexRefs = array_map(function ($index) {
            return $index['reference'];
        }, $this->indexConfigs);

        $managerDef = $container->getDefinition('fazland_elastica.index_manager');
        $managerDef->replaceArgument(0, $indexRefs);
    }

    /**
     * Makes sure a specific driver has been loaded.
     *
     * @param ContainerBuilder $container
     * @param string           $driver
     */
    private function loadDriver(ContainerBuilder $container, $driver)
    {
        if (in_array($driver, $this->loadedDrivers)) {
            return;
        }

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load($driver.'.xml');
        $this->loadedDrivers[] = $driver;
    }

    /**
     * Loads and configures the serializer prototype.
     *
     * @param array            $config
     * @param ContainerBuilder $container
     */
    private function loadSerializer($config, ContainerBuilder $container)
    {
        $container->setAlias('fazland_elastica.serializer', $config['serializer']);

        $serializer = $container->getDefinition('fazland_elastica.serializer_callback_prototype');
        $serializer->setClass($config['callback_class']);

        if (is_subclass_of($config['callback_class'], ContainerAwareInterface::class)) {
            $serializer->addMethodCall('setContainer', [new Reference('service_container')]);
        }

        if (isset($config['groups'])) {
            $serializer->addMethodCall('setGroups', [$config['groups']]);
        }

        if (isset($config['serialize_null'])) {
            $serializer->addMethodCall('setSerializeNull', [$config['serialize_null']]);
        }

        if (isset($config['version'])) {
            $serializer->addMethodCall('setVersion', [$config['version']]);
        }
    }

    /**
     * Creates a default manager alias for defined default manager or the first loaded driver.
     *
     * @param string           $defaultManager
     * @param ContainerBuilder $container
     */
    private function createDefaultManagerAlias($defaultManager, ContainerBuilder $container)
    {
        if (0 == count($this->loadedDrivers)) {
            return;
        }

        if (count($this->loadedDrivers) > 1 && in_array($defaultManager, $this->loadedDrivers)) {
            $defaultManagerService = $defaultManager;
        } else {
            $defaultManagerService = $this->loadedDrivers[0];
        }

        $container->setAlias('fazland_elastica.manager', sprintf('fazland_elastica.manager.%s', $defaultManagerService));
    }

    /**
     * Returns a reference to a client given its configured name.
     *
     * @param string $clientName
     *
     * @throws \InvalidArgumentException
     * @return Reference
     *
     */
    private function getClient($clientName)
    {
        if (! array_key_exists($clientName, $this->clients)) {
            throw new InvalidArgumentException(sprintf('The elastica client with name "%s" is not defined', $clientName));
        }

        return $this->clients[$clientName]['reference'];
    }
}

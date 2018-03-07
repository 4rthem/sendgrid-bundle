<?php

namespace Arthem\Bundle\SendgridBundle\DependencyInjection;

use Arthem\Bundle\SendgridBundle\Transport\SendGridTransport;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\DefinitionDecorator;
use Symfony\Component\DependencyInjection\Loader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * This is the class that loads and manages your bundle configuration.
 *
 * @see http://symfony.com/doc/current/cookbook/bundles/extension.html
 */
class ArthemSendgridExtension extends Extension
{
    /**
     * {@inheritdoc}
     */
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $childDefinition = new DefinitionDecorator('swiftmailer.transport.eventdispatcher.abstract');
        $container->setDefinition('sendgrid.swift_transport.eventdispatcher', $childDefinition);

        $definition = $container->getDefinition(SendGridTransport::class);
        $definition->setArgument('$eventDispatcher', new Reference('sendgrid.swift_transport.eventdispatcher'));

        $definition = $container->getDefinition(\SendGrid::class);
        $definition->setArgument('$apiKey', $config['api_key']);

        $container->setAlias('swiftmailer.mailer.transport.sendgrid', SendGridTransport::class);
    }
}

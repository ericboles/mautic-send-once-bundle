<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    $excludes = [
    ];

    $services->load('MauticPlugin\\MauticSendOnceBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->load('MauticPlugin\\MauticSendOnceBundle\\Entity\\', '../Entity/*Repository.php');

    // Register console commands
    $services->load('MauticPlugin\\MauticSendOnceBundle\\Command\\', '../Command/*Command.php')
        ->tag('console.command');

    // Decorate EmailType to add send_once checkbox
    // Note: If MultipleTransportBundle is installed, this will decorate their decorator
    $services->set(MauticPlugin\MauticSendOnceBundle\Form\Type\OverrideEmailType::class)
        ->decorate(Mautic\EmailBundle\Form\Type\EmailType::class)
        ->arg('$decorated', service('.inner'));
};

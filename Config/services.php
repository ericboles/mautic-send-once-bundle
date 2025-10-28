<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\MauticSendOnceBundle\Entity\SendOnceEmail;
use MauticPlugin\MauticSendOnceBundle\Entity\SendOnceEmailRepository;
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

    // Register repositories properly through EntityManager
    $services->set(SendOnceEmailRepository::class)
        ->factory([service('doctrine.orm.entity_manager'), 'getRepository'])
        ->args([SendOnceEmail::class]);

    $services->set(\MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecordRepository::class)
        ->factory([service('doctrine.orm.entity_manager'), 'getRepository'])
        ->args([\MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecord::class]);

    // Register Mautic's EmailRepository for use in command
    $services->set(\Mautic\EmailBundle\Entity\EmailRepository::class)
        ->factory([service('doctrine.orm.entity_manager'), 'getRepository'])
        ->args([\Mautic\EmailBundle\Entity\Email::class]);
};

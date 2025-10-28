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

    // Register repository properly through EntityManager
    $services->set(SendOnceEmailRepository::class)
        ->factory([service('doctrine.orm.entity_manager'), 'getRepository'])
        ->args([SendOnceEmail::class]);

    $services->load('MauticPlugin\\MauticSendOnceBundle\\Entity\\', '../Entity/EmailSendRecordRepository.php');

    // Decorate EmailType to add send_once checkbox
    $services->set(MauticPlugin\MauticSendOnceBundle\Form\Type\OverrideEmailType::class)
        ->decorate(Mautic\EmailBundle\Form\Type\EmailType::class);
};

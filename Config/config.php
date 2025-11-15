<?php

declare(strict_types=1);

return [
    'name'        => 'Send Once',
    'description' => 'Ensures segment emails can only be sent once, then automatically unpublishes',
    'version'     => '1.0.0',
    'author'      => 'Mautic Community',
    
    'services' => [
        'events' => [
            // JMS Serializer subscriber to add sendOnce field to Email API responses
            'mautic.sendonce.email_serializer_subscriber' => [
                'class'     => \MauticPlugin\MauticSendOnceBundle\EventListener\EmailSerializerSubscriber::class,
                'arguments' => [
                    'doctrine.dbal.default_connection',
                    'monolog.logger.mautic',
                ],
                'tag'       => 'jms_serializer.event_subscriber',
            ],
        ],
    ],
];

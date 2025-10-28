<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecordRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Adds the sendOnce field to email forms via POST_SET_DATA event.
 */
class EmailFormSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EmailSendRecordRepository $emailSendRecordRepository,
        private Connection $connection
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::POST_SET_DATA => 'onPostSetData',
        ];
    }

    public function onPostSetData(FormEvent $event): void
    {
        $form = $event->getForm();
        $email = $event->getData();
        
        // Only process Email entities
        if (!$email instanceof \Mautic\EmailBundle\Entity\Email) {
            return;
        }
        
        // Only add for segment emails
        if ($email->getEmailType() !== 'list') {
            return;
        }

        $alreadySent = $email->getId() ? $this->emailSendRecordRepository->hasBeenSent($email) : false;
        
        // Get current value from database
        $sendOnceValue = false;
        if ($email->getId()) {
            try {
                $result = $this->connection->fetchOne(
                    'SELECT send_once FROM emails WHERE id = ?',
                    [$email->getId()]
                );
                $sendOnceValue = (bool) $result;
            } catch (\Exception $e) {
                // Ignore errors
            }
        }

        $form->add(
            'sendOnce',
            YesNoButtonGroupType::class,
            [
                'label'      => 'mautic.email.send_once',
                'label_attr' => ['class' => 'control-label'],
                'attr'       => [
                    'class'   => 'form-control',
                    'tooltip' => 'mautic.email.send_once.tooltip',
                ],
                'required' => false,
                'mapped'   => false,
                'data'     => $sendOnceValue,
                'disabled' => $alreadySent,
            ]
        );
    }
}

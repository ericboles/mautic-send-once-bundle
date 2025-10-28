<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Saves the one_time_send value from the form to the database.
 */
class EmailPostSaveSubscriber implements EventSubscriberInterface
{
    private ?\Symfony\Component\HttpFoundation\Request $request;

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
        RequestStack $requestStack
    ) {
        $this->request = $requestStack->getCurrentRequest();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_POST_SAVE => ['onEmailPostSave', 0],
        ];
    }

    public function onEmailPostSave(EmailEvent $event): void
    {
        $email = $event->getEmail();

        if (!$this->request) {
            return;
        }

        $emailFormData = $this->request->request->all('emailform');
        if (empty($emailFormData)) {
            return;
        }

        $sendOnce = isset($emailFormData['sendOnce']) ? (int) $emailFormData['sendOnce'] : 0;

        // Update the send_once field directly in database
        $this->connection->executeStatement(
            'UPDATE emails SET send_once = ? WHERE id = ?',
            [$sendOnce, $email->getId()]
        );

        $this->logger->info('Updated send_once for email', [
            'email_id' => $email->getId(),
            'send_once' => $sendOnce,
        ]);
    }
}

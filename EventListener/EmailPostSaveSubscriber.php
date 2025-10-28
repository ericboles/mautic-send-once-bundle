<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\MauticSendOnceBundle\Entity\SendOnceEmailRepository;
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
        private SendOnceEmailRepository $sendOnceEmailRepository,
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

        $sendOnce = isset($emailFormData['sendOnce']) && (int) $emailFormData['sendOnce'] === 1;

        try {
            $this->sendOnceEmailRepository->setSendOnceForEmail($email, $sendOnce);

            $this->logger->info('Updated send_once for email', [
                'email_id' => $email->getId(),
                'send_once' => $sendOnce,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update send_once for email', [
                'email_id' => $email->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace MauticPlugin\MauticSendOnceBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use MauticPlugin\MauticSendOnceBundle\Entity\SendOnceEmailRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Twig\Environment;

class CustomContentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private SendOnceEmailRepository $sendOnceEmailRepository,
        private Environment $twig
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['onInjectCustomContent', 0],
        ];
    }

    public function onInjectCustomContent(CustomContentEvent $event): void
    {
        $context = $event->getContext();
        $vars = $event->getVars();

        try {
            // Inject Send Once field into the email form (Advanced tab, right column after headers)
            if ($event->checkContext('@MauticEmail/Email/form.html.twig', 'email.settings.advanced')) {
                $email = $vars['email'] ?? null;
                $form = $vars['form'] ?? null;
                $sendOnce = true; // Default to true for new emails
                
                if ($email && method_exists($email, 'getId') && $email->getId()) {
                    // Existing email - get actual value from database
                    $sendOnce = $this->getSendOnceValue($email->getId());
                }

                // Don't close left column, just inject after it naturally flows
                $content = $this->twig->render(
                    '@MauticSendOnce/Email/send_once_field.html.twig',
                    [
                        'email' => $email,
                        'sendOnce' => $sendOnce,
                        'isNewEmail' => !($email && $email->getId())
                    ]
                );
                $event->addContent($content);
                return;
            }
        } catch (\Exception $e) {
            // Silently continue if there's an error - don't break the UI
            error_log('MauticSendOnceBundle: Error injecting custom content: ' . $e->getMessage());
        }
    }
    
    private function getSendOnceValue(int $emailId): bool
    {
        try {
            return $this->sendOnceEmailRepository->getSendOnceForEmail($emailId);
        } catch (\Exception $e) {
            error_log('MauticSendOnceBundle: Error fetching send_once value: ' . $e->getMessage());
            return true; // Default to true for safety
        }
    }
}

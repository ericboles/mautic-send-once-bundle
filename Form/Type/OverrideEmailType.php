<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Form\Type;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\ThemeHelperInterface;
use Mautic\EmailBundle\Form\Type\EmailType;
use Mautic\EmailBundle\Helper\EmailConfigInterface;
use Mautic\StageBundle\Model\StageModel;
use MauticPlugin\MauticSendOnceBundle\Entity\EmailSendRecordRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Decorates EmailType to add the send_once checkbox field.
 */
class OverrideEmailType extends EmailType
{
    public function __construct(
        TranslatorInterface $translator,
        private EntityManagerInterface $entityManager,
        StageModel $stageModel,
        CoreParametersHelper $coreParametersHelper,
        ThemeHelperInterface $themeHelper,
        EmailConfigInterface $emailConfig,
        private EmailSendRecordRepository $emailSendRecordRepository,
        private LoggerInterface $logger
    ) {
        parent::__construct($translator, $entityManager, $stageModel, $coreParametersHelper, $themeHelper, $emailConfig);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        // Debug logging to verify this method is being called
        $this->logger->info('SendOnceBundle: buildForm called');
        $this->logger->info('SendOnceBundle: options keys = ' . implode(', ', array_keys($options)));
        
        $email = $options['data'] ?? null;
        $this->logger->info('SendOnceBundle: email is ' . ($email ? get_class($email) : 'null'));
        
        // Skip if not an Email entity
        if (!$email instanceof \Mautic\EmailBundle\Entity\Email) {
            $this->logger->info('SendOnceBundle: Not an Email entity, skipping');
            return;
        }

        $emailType = $email->getEmailType();
        $this->logger->info('SendOnceBundle: Email type = ' . ($emailType ?? 'null'));
        
        // Only add for segment emails (list)
        if ($emailType !== 'list') {
            $this->logger->info('SendOnceBundle: Not a segment email (list), skipping');
            return;
        }

        $this->logger->info('SendOnceBundle: Adding sendOnce field');

        $alreadySent = false;
        $sendOnceValue = false;
        
        try {
            // Check if already sent
            if ($email->getId()) {
                $alreadySent = $this->emailSendRecordRepository->hasBeenSent($email);
                
                // Get send_once value from database
                $connection = $this->entityManager->getConnection();
                $result = $connection->fetchOne(
                    'SELECT send_once FROM emails WHERE id = ?',
                    [$email->getId()]
                );
                $sendOnceValue = (bool) $result;
                $this->logger->info('SendOnceBundle: From DB - sendOnceValue = ' . ($sendOnceValue ? 'true' : 'false'));
            }
        } catch (\Exception $e) {
            // If there's any error, just continue with default values
            $this->logger->error('SendOnceBundle: Error getting send_once value: ' . $e->getMessage());
        }

        $builder->add(
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
        
        $this->logger->info('SendOnceBundle: sendOnce field added successfully');
    }

    public function getBlockPrefix(): string
    {
        return parent::getBlockPrefix();
    }
}

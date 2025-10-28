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
        private EmailSendRecordRepository $emailSendRecordRepository
    ) {
        parent::__construct($translator, $entityManager, $stageModel, $coreParametersHelper, $themeHelper, $emailConfig);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        parent::buildForm($builder, $options);

        // Write to file instead of logger
        file_put_contents('/tmp/sendonce-debug.log', date('Y-m-d H:i:s') . ' - buildForm called' . PHP_EOL, FILE_APPEND);
        
        $email = $options['data'] ?? null;
        
        // Skip if not an Email entity
        if (!$email instanceof \Mautic\EmailBundle\Entity\Email) {
            file_put_contents('/tmp/sendonce-debug.log', 'Not an Email entity' . PHP_EOL, FILE_APPEND);
            return;
        }

        $emailType = $email->getEmailType();
        file_put_contents('/tmp/sendonce-debug.log', 'Email type: ' . ($emailType ?? 'null') . PHP_EOL, FILE_APPEND);
        
        // Only add for segment emails (list)
        if ($emailType !== 'list') {
            file_put_contents('/tmp/sendonce-debug.log', 'Not a segment email' . PHP_EOL, FILE_APPEND);
            return;
        }

        file_put_contents('/tmp/sendonce-debug.log', 'Adding sendOnce field' . PHP_EOL, FILE_APPEND);

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
                file_put_contents('/tmp/sendonce-debug.log', 'sendOnceValue from DB: ' . ($sendOnceValue ? 'true' : 'false') . PHP_EOL, FILE_APPEND);
            }
        } catch (\Exception $e) {
            // If there's any error, just continue with default values
            file_put_contents('/tmp/sendonce-debug.log', 'Error: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
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
        
        file_put_contents('/tmp/sendonce-debug.log', 'sendOnce field added successfully!' . PHP_EOL, FILE_APPEND);
    }

    public function getBlockPrefix(): string
    {
        return parent::getBlockPrefix();
    }
}

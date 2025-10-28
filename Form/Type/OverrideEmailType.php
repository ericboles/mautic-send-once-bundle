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

        $email = $options['data'] ?? null;
        
        // Skip if not an Email entity
        if (!$email instanceof \Mautic\EmailBundle\Entity\Email) {
            return;
        }

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
            }
        } catch (\Exception $e) {
            // If there's any error, just continue with default values
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
    }

    public function getBlockPrefix(): string
    {
        return parent::getBlockPrefix();
    }
}

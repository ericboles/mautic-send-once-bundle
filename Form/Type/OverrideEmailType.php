<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Form\Type;

use Doctrine\ORM\EntityManager;
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
        private EntityManager $entityManager,
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

        $email = $options['data'];
        $alreadySent = $email && $email->getId() ? $this->emailSendRecordRepository->hasBeenSent($email) : false;
        
        // Get send_once value from database since Email entity doesn't have getter/setter
        $sendOnceValue = false;
        if ($email && $email->getId()) {
            $connection = $this->entityManager->getConnection();
            $sendOnceValue = (bool) $connection->fetchOne(
                'SELECT send_once FROM emails WHERE id = ?',
                [$email->getId()]
            );
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
                    'readonly' => $alreadySent, // Can't change after sent
                ],
                'required' => false,
                'mapped'   => false, // Not mapped to Email entity
                'data'     => $sendOnceValue,
                'disabled' => $alreadySent,
            ]
        );

        // Add warning message if already sent
        if ($alreadySent) {
            $builder->get('sendOnce')->setData(true);
        }
    }

    public function getBlockPrefix(): string
    {
        return parent::getBlockPrefix();
    }
}

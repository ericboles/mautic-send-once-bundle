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

        // Always add the field, no conditions
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
                'data'     => false,
            ]
        );
    }

    public function getBlockPrefix(): string
    {
        return parent::getBlockPrefix();
    }
}

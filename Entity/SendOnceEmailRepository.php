<?php

declare(strict_types=1);

namespace MauticPlugin\MauticSendOnceBundle\Entity;

use Doctrine\ORM\EntityRepository;
use Mautic\EmailBundle\Entity\Email;

/**
 * @extends EntityRepository<SendOnceEmail>
 */
class SendOnceEmailRepository extends EntityRepository
{
    /**
     * Get send_once status for an email.
     */
    public function getSendOnceForEmail(int $emailId): bool
    {
        $result = $this->createQueryBuilder('soe')
            ->select('soe.sendOnce')
            ->where('soe.email = :emailId')
            ->setParameter('emailId', $emailId)
            ->getQuery()
            ->getOneOrNullResult();

        return $result ? (bool) $result['sendOnce'] : false;
    }

    /**
     * Set send_once status for an email.
     */
    public function setSendOnceForEmail(Email $email, bool $sendOnce): void
    {
        $sendOnceEmail = $this->findOneBy(['email' => $email]);

        if (!$sendOnceEmail) {
            $sendOnceEmail = new SendOnceEmail();
            $sendOnceEmail->setEmail($email);
            $sendOnceEmail->setDateAdded(new \DateTime());
        }

        $sendOnceEmail->setSendOnce($sendOnce);

        $this->getEntityManager()->persist($sendOnceEmail);
        $this->getEntityManager()->flush();
    }
}

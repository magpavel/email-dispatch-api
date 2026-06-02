<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Email;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Email>
 */
class EmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Email::class);
    }

    public function save(Email $email, bool $flush = false): void
    {
        $this->getEntityManager()->persist($email);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByIdOrNull(string $id): ?Email
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->find(Uuid::fromString($id));
    }

    public function findByIdempotencyKey(string $key): ?Email
    {
        return $this->findOneBy(['idempotencyKey' => $key]);
    }
}

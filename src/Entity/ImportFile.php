<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ImportFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ImportFileRepository::class)]
#[ORM\Table(name: 'import_file')]
#[ORM\Index(columns: ['user_id'], name: 'idx_import_file_user')]
#[ORM\Index(columns: ['broker_account_id'], name: 'idx_import_file_broker_account')]
#[ORM\Index(columns: ['status'], name: 'idx_import_file_status')]
class ImportFile
{
    public const STATUSES = ['uploaded', 'previewed', 'imported', 'failed'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(targetEntity: BrokerAccount::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?BrokerAccount $brokerAccount = null;

    #[ORM\Column(length: 255)]
    private string $originalFileName = '';

    #[ORM\Column(length: 20)]
    private string $status = 'uploaded';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function setBrokerAccount(BrokerAccount $brokerAccount): self
    {
        $this->brokerAccount = $brokerAccount;

        return $this;
    }

    public function setOriginalFileName(string $originalFileName): self
    {
        $this->originalFileName = $originalFileName;

        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }
}

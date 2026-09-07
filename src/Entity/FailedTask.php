<?php

namespace Cesurapp\SwooleBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\UuidV7;

#[ORM\Entity(repositoryClass: FailedTaskRepository::class)]
class FailedTask
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private UuidV7 $id;

    #[ORM\Column(type: 'string')]
    private string $task;

    /**
     * Sütunda base64 duruyor, ham serialize() çıktısı değil.
     *
     * serialize() private/protected alanları `\0Sınıf\0alan` biçiminde kodluyor ve NUL
     * baytı text sütunundan sağ çıkmıyor: kayıt ilk NUL'da kesiliyor, dönüşte
     * unserialize() çuvallıyor, göreve dizi yerine bozuk bir dize gidiyor. Kodlama
     * getter/setter'da saydam — dışarısı yine ham payload görüyor.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $payload;

    #[ORM\Column(type: 'text')]
    private string $exception;

    #[ORM\Column(type: 'smallint')]
    protected int $attempt = 0;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    public function __construct()
    {
        $this->id = UuidV7::v7();
        $this->createdAt = new \DateTime();
    }

    public function getId(): UuidV7
    {
        return $this->id;
    }

    public function getTask(): string
    {
        return $this->task;
    }

    public function setTask(string $task): self
    {
        $this->task = $task;

        return $this;
    }

    public function getPayload(): ?string
    {
        if (null === $this->payload) {
            return null;
        }

        return base64_decode($this->payload, true) ?: null;
    }

    public function setPayload(?string $payload): self
    {
        $this->payload = null === $payload ? null : base64_encode($payload);

        return $this;
    }

    public function getException(): string
    {
        return $this->exception;
    }

    public function setException(string $exception): self
    {
        $this->exception = $exception;

        return $this;
    }

    public function getAttempt(): int
    {
        return $this->attempt;
    }

    public function setAttempt(int $attempt): self
    {
        $this->attempt = $attempt;

        return $this;
    }

    public function getCreatedAt(): \DateTime
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTime $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}

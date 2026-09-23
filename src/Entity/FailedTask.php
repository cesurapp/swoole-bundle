<?php

namespace Cesurapp\SwooleBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Cesurapp\SwooleBundle\Repository\FailedTaskRepository;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\UuidV7;

/**
 * A task that has not finished yet: one that failed and waits for a retry, or a durable task
 * (TaskHandler::dispatch(..., durable: true)) from the moment it is dispatched until it succeeds.
 *
 * Two columns tell the states apart:
 *
 *   exception   delivered_at   state
 *   ''          set            durable task running
 *   ''          null           waiting: could not be handed to a worker yet, FailedTaskCron will
 *   message     null           failed: waiting for a retry, or out of attempts — the "failed task" list
 *   message     set            a retry running
 *
 * A failed row waits for `available_at` (the task_retry delay) before FailedTaskCron takes it again.
 *
 * "No failure" is an empty string rather than NULL so the column keeps the definition it always
 * had: schema:update never has to tighten it back, even if an older release runs against rows
 * written by this one.
 */
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

    /** The last run's error; '' while the task has not failed. */
    #[ORM\Column(type: 'text')]
    private string $exception = '';

    /** Runs started so far — each run carries its number, so a stale run cannot touch a newer one. */
    #[ORM\Column(type: 'smallint')]
    protected int $attempt = 0;

    #[ORM\Column(type: 'datetime')]
    private \DateTime $createdAt;

    /** When the running attempt was handed to a worker; null while nothing runs it. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    /** The earliest the next attempt may start (the `task_retry` delay after a failure); null is now. */
    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $availableAt = null;

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

        // `?:` kullanılmıyor: geçerli ama boş/"0" bir payload'ı null'a çevirirdi.
        // false yalnızca sütunda base64 olmayan bir şey varsa (base64 öncesi kayıt).
        $decoded = base64_decode($this->payload, true);

        return false === $decoded ? null : $decoded;
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

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function setDeliveredAt(?\DateTimeImmutable $deliveredAt): self
    {
        $this->deliveredAt = $deliveredAt;

        return $this;
    }

    public function getAvailableAt(): ?\DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function setAvailableAt(?\DateTimeImmutable $availableAt): self
    {
        $this->availableAt = $availableAt;

        return $this;
    }
}

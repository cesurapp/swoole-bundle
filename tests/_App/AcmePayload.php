<?php

namespace Cesurapp\SwooleBundle\Tests\_App;

/**
 * Private/protected alanlı payload nesnesi.
 *
 * serialize() bu alanları `\0Sınıf\0alan` ve `\0*\0alan` biçiminde kodluyor, yani
 * çıktıda NUL baytı oluyor. FailedTask depolamasının bunu bozmadan saklaması gerek.
 *
 * Servis olarak taranan dizinlerin (_App/Task, _App/Cron, _App/Process) dışında
 * duruyor — bu bir veri nesnesi, servis değil.
 */
class AcmePayload
{
    public function __construct(private string $id = 'acme', protected int $count = 1)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }
}

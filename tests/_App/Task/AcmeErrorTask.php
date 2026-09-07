<?php

namespace Cesurapp\SwooleBundle\Tests\_App\Task;

use Cesurapp\SwooleBundle\Task\TaskInterface;

/**
 * \Exception değil \Error atan görev.
 *
 * TaskWorker yalnızca \Exception yakalarken böyle bir görev task worker'ını
 * öldürüyordu; artık kayıt altına alınıp geçilmesi gerekiyor.
 */
class AcmeErrorTask implements TaskInterface
{
    public function __invoke(string $data): mixed
    {
        // Kasıtlı TypeError: dizi bekleyip dize alan bir görevde olan tam olarak bu.
        return $data['missing'];
    }
}

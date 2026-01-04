# Process Worker

Process Worker, sunucu başladığında farklı bir process ile sürekli çalışan görevler oluşturmanıza olanak tanır. Redis LISTEN, Postgres LISTEN veya benzeri sürekli dinleme komutları için idealdir.

## Özellikler

- Her process ayrı bir Swoole Process olarak çalışır
- İşlem tamamlandığında otomatik yeniden başlatma desteği
- Parametrelerle yeniden başlatma gecikmesi ayarlanabilir
- Enable/Disable desteği

## Kullanım

### 1. Process Job Oluşturma

`ProcessInterface` veya `AbstractProcessJob` kullanarak bir process job oluşturun:

```php
<?php

namespace App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;

class RedisListenerProcess extends AbstractProcessJob
{
    // Process aktif mi?
    public bool $ENABLE = true;
    
    // İşlem tamamlandığında yeniden başlat
    public bool $RESTART = true;
    
    // Yeniden başlatma öncesi bekleme süresi (saniye)
    public int $RESTART_DELAY = 5;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(): void
    {
        $this->logger->info('Redis listener started');
        
        // Redis SUBSCRIBE komutu
        $this->redis->subscribe(['channel1', 'channel2'], function ($redis, $channel, $message) {
            $this->logger->info("Received message from {$channel}: {$message}");
            // İşlemi burada yapın
        });
    }
}
```

### 2. Postgres LISTEN Örneği

```php
<?php

namespace App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;
use Doctrine\DBAL\Connection;

class PostgresListenerProcess extends AbstractProcessJob
{
    public bool $ENABLE = true;
    public bool $RESTART = true;
    public int $RESTART_DELAY = 3;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(): void
    {
        $this->logger->info('Postgres listener started');
        
        // LISTEN komutu
        $this->connection->executeStatement('LISTEN my_channel');
        
        while (true) {
            // Notification bekle
            $notification = pg_get_notify($this->connection->getNativeConnection());
            
            if ($notification) {
                $this->logger->info('Received notification', [
                    'channel' => $notification['message'],
                    'payload' => $notification['payload']
                ]);
                
                // İşlemi burada yapın
            }
            
            usleep(100000); // 100ms bekle
        }
    }
}
```

### 3. Tek Seferlik İşlem (Yeniden Başlatmasız)

```php
<?php

namespace App\Process;

use Cesurapp\SwooleBundle\Process\AbstractProcessJob;

class OneTimeProcess extends AbstractProcessJob
{
    public bool $ENABLE = true;
    public bool $RESTART = false; // Yeniden başlatma kapalı

    public function __invoke(): void
    {
        // Tek seferlik işlem
        $this->doSomething();
        
        // İşlem tamamlandığında process sonlanır
    }
}
```

## Konfigürasyon

`config/packages/swoole.yaml` dosyasında process worker'ı aktif/pasif edebilirsiniz:

```yaml
swoole:
    process_worker: true  # Varsayılan: true
```

Veya environment variable ile:

```bash
SERVER_WORKER_PROCESS=1  # Aktif
SERVER_WORKER_PROCESS=0  # Pasif
```

## Process'leri Listeleme

Tüm kayıtlı process'leri görmek için ProcessWorker servisini kullanabilirsiniz:

```php
use Cesurapp\SwooleBundle\Process\ProcessWorker;

class SomeService
{
    public function __construct(private readonly ProcessWorker $processWorker)
    {
    }
    
    public function listProcesses(): void
    {
        foreach ($this->processWorker->getAll() as $process) {
            echo get_class($process) . PHP_EOL;
            echo "  ENABLE: " . ($process->ENABLE ? 'Yes' : 'No') . PHP_EOL;
            echo "  RESTART: " . ($process->RESTART ? 'Yes' : 'No') . PHP_EOL;
            echo "  RESTART_DELAY: {$process->RESTART_DELAY}s" . PHP_EOL;
        }
    }
}
```

## Notlar

- Her process ayrı bir Swoole Process olarak çalışır, bu nedenle birbirlerinden izole edilmiştir
- Process'ler sunucu başladığında otomatik olarak başlatılır
- `RESTART=true` olduğunda, process tamamlandığında `RESTART_DELAY` kadar bekledikten sonra yeniden başlatılır
- Process'ler `ProcessInterface` implement etmelidir (veya `AbstractProcessJob` extend edebilir)
- Otomatik olarak Symfony DI container'a kaydedilir ve lazy loading desteklenir

<?php

namespace Cesurapp\SwooleBundle\Runtime;

use Swoole\Client;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process as SymfonyProcess;

class SwooleProcess
{
    public function __construct(private readonly SymfonyStyle $output, private string $rootDir, private string $entrypoint)
    {
        $this->rootDir = rtrim($this->rootDir, '/');
        $this->entrypoint = '/'.ltrim($entrypoint, '/');
    }

    /**
     * Start Server.
     */
    public function start(string $phpBinary, bool $detach = false): bool
    {
        if ($this->getServer()?->isConnected()) {
            $this->output->warning('Swoole HTTP Server is Running');

            return false;
        }

        // Start
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ];
        $process = proc_open(sprintf('%s %s%s', $phpBinary, $this->rootDir, $this->entrypoint), $descriptorSpec, $pipes);
        if (is_resource($process)) {
            fclose($pipes[0]);

            if (!$detach) {
                proc_close($process);
            }
        }

        $this->output->success('Swoole HTTP Server is Started');

        return true;
    }

    /**
     * Start Watch Server.
     */
    public function watch(array $watchDir, array $watchExt): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGHUP, fn () => posix_kill(posix_getpid(), SIGINT));
        pcntl_signal(SIGTSTP, fn () => posix_kill(posix_getpid(), SIGINT));

        // Check fsWatch Plugin
        if (!$fsWatch = new ExecutableFinder()->find('fswatch')) {
            $this->output->error('fswatch plugin not found!');

            return;
        }

        // Start File Watcher
        $paths = [...array_map(fn ($path) => $this->rootDir.$path, $watchDir)];
        $watcher = new SymfonyProcess([$fsWatch, ...$watchExt, '-r', '-e', '.*~', ...$paths], null, null, null, 0);
        $watcher->setIgnoredSignals([SIGHUP, SIGTSTP]);
        $watcher->start();

        // App Server
        $server = new SymfonyProcess([new PhpExecutableFinder()->find(), $this->rootDir.$this->entrypoint], null, null, null, 0);
        $server->setTty(true)->setIgnoredSignals([SIGHUP, SIGTSTP]);
        $server->start();

        while (true) {
            if (!$server->isRunning()) {
                break;
            }

            if ($output = $watcher->getIncrementalOutput()) {
                $this->output->write('Changed -> '.str_replace($this->rootDir, '', $output));

                exec(sprintf('lsof -nP -t -iTCP:%s -sTCP:LISTEN | xargs kill -9 2>/dev/null', $_ENV['SERVER_HTTP_PORT'] ?? 80));

                usleep(100 * 1000);
                $server->start(null, ['watch' => random_int(100, 200)]);
            }

            if ($error = $watcher->getIncrementalErrorOutput()) {
                $this->output->writeln('<error>'.$error.'</error>');
            }

            usleep(100 * 1000);
        }
    }

    /**
     * Stop Server.
     */
    public function stop(?string $tcpHost = null, ?int $tcpPort = null): bool
    {
        $server = $this->getServer($tcpHost ?? '127.0.0.1', $tcpPort ?? 9502);
        if (!$server || !$server->isConnected()) {
            $this->output->error('Swoole HTTP server not found!');

            return false;
        }

        // Shutdown
        try {
            $server->send('shutdown');
            $server->close();
            exec(sprintf('lsof -nP -t -iTCP:%s -sTCP:LISTEN | xargs kill -9 2>/dev/null', $_ENV['SERVER_HTTP_PORT'] ?? 80));
        } catch (\Exception $exception) {
            $this->output->error($exception->getMessage());
        }

        $this->output->success('Swoole HTTP Server is Stopped!');

        return true;
    }

    /**
     * Get Current Process ID.
     */
    public function getServer(?string $tcpHost = null, ?int $tcpPort = null): ?Client
    {
        $tcpClient = new Client(SWOOLE_SOCK_TCP);

        try {
            @$tcpClient->connect($tcpHost ?? '127.0.0.1', $tcpPort ?? 9502, 1);
            if (!$tcpClient->isConnected()) {
                return null;
            }
        } catch (\Exception) {
            return null;
        }

        return $tcpClient;
    }
}

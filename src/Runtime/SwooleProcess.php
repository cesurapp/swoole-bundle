<?php

namespace Cesurapp\SwooleBundle\Runtime;

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
     * The file a running server keeps its master process id in. Swoole deletes it on shutdown.
     */
    public static function pidFile(string $rootDir): string
    {
        return rtrim($rootDir, '/').'/var/swoole.pid';
    }

    /**
     * The task broker's unix socket. A socket path may not be much longer than 100 bytes (104 on
     * macOS, 108 on Linux); a longer one goes to the temp directory, named after the project.
     */
    public static function taskSocket(string $rootDir): string
    {
        $path = rtrim($rootDir, '/').'/var/task-broker.sock';

        return strlen($path) <= 100 ? $path : sys_get_temp_dir().'/swoole-task-'.substr(md5($path), 0, 16).'.sock';
    }

    /**
     * Files that must outlive the container: the only part of var/ to keep on a volume. The rest of
     * var/ belongs to one image (the compiled container, caches, swoole.pid): carried over a deploy,
     * the new code would boot with the old container.
     */
    public static function durableDir(string $rootDir): string
    {
        return rtrim($rootDir, '/').'/var/durable';
    }

    /**
     * The task broker's queue: the tasks still waiting survive a restart in it.
     */
    public static function taskLog(string $rootDir): string
    {
        return self::durableDir($rootDir).'/queue.log';
    }

    /**
     * Start Server.
     */
    public function start(string $phpBinary, bool $detach = false): bool
    {
        if ($this->pid()) {
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

        // Stop a server left over from a previous session, otherwise it keeps the HTTP port.
        if ($this->pid()) {
            $this->stop();
        }

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

                $this->kill();

                usleep(100 * 1000);
                $server->start(null, ['watch' => (string) random_int(100, 200)]);
            }

            if ($error = $watcher->getIncrementalErrorOutput()) {
                $this->output->writeln('<error>'.$error.'</error>');
            }

            usleep(100 * 1000);
        }
    }

    /**
     * Stop Server.
     *
     * The server ends its running requests first, for up to max_wait_time seconds, then gives the
     * task executors up to shutdown_grace seconds to finish theirs. One that is still up well after
     * that is killed.
     */
    public function stop(): bool
    {
        if (!$pid = $this->pid()) {
            $this->output->error('Swoole HTTP server not found!');

            return false;
        }

        posix_kill($pid, SIGTERM);
        $deadline = time() + (int) ($_ENV['SERVER_HTTP_SETTINGS_MAX_WAIT_TIME'] ?? 60) + (int) ($_ENV['SERVER_TASK_SETTINGS_SHUTDOWN_GRACE'] ?? 30) + 15;
        while (posix_kill($pid, 0) && time() < $deadline) {
            pcntl_waitpid($pid, $status, WNOHANG); // collects it, when this process started it
            usleep(100 * 1000);
        }

        if (posix_kill($pid, 0)) {
            $this->kill();
            $this->output->warning('Swoole HTTP Server did not stop in time, it was killed.');
        }

        $this->output->success('Swoole HTTP Server is Stopped!');

        return true;
    }

    /**
     * The running server's master process id.
     */
    private function pid(): ?int
    {
        $pid = (int) @file_get_contents(self::pidFile($this->rootDir));

        return $pid > 0 && posix_kill($pid, 0) ? $pid : null;
    }

    /**
     * Kills the whole server at once: all of its processes hold the HTTP port.
     */
    private function kill(): void
    {
        exec(sprintf('lsof -nP -t -iTCP:%s -sTCP:LISTEN | xargs kill -9 2>/dev/null', $_ENV['SERVER_HTTP_PORT'] ?? 80));
    }
}

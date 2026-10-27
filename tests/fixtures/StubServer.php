<?php

/** Runs tests/fixtures/zemzemeh_stub.php under `php -S` on a free local port. */
final class StubServer
{
    public readonly string $url;
    public readonly string $log;
    private $process;

    public function __construct()
    {
        $this->log = getenv('ZZDLP_TEST_DIR') . '/stub-' . bin2hex(random_bytes(4)) . '.log';
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);
        $this->url = "http://127.0.0.1:{$port}";
        $this->process = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/zemzemeh_stub.php'],
            [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, null,
            ['STUB_LOG' => $this->log]);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
            usleep(100000);
        }
    }

    public function requests(): string
    {
        return is_file($this->log) ? file_get_contents($this->log) : '';
    }

    public function reset(): void
    {
        @unlink($this->log);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
    }
}

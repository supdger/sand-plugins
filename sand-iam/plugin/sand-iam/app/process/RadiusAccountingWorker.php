<?php

declare(strict_types=1);

namespace plugin\SandIam\app\process;

use plugin\SandIam\app\service\RadiusAccountingService;
use support\Log;
use Workerman\Timer;

final class RadiusAccountingWorker
{
    /** @var resource|null */
    private $socket = null;

    public function onWorkerStart(): void
    {
        $host = (string) config('plugin.sand-iam.app.radius_bind_host', '127.0.0.1');
        $port = (int) config('plugin.sand-iam.app.radius_accounting_port', 1813);
        if (filter_var($host, FILTER_VALIDATE_IP) === false || $port < 1 || $port > 65535) throw new \RuntimeException('SandIAM RADIUS accounting bind configuration invalid');
        $target = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'udp://[' . $host . ']:' . $port : 'udp://' . $host . ':' . $port;
        $socket = stream_socket_server($target, $errorCode, $errorMessage, STREAM_SERVER_BIND);
        if (!is_resource($socket)) throw new \RuntimeException('SandIAM RADIUS accounting socket unavailable: ' . $errorCode);
        stream_set_blocking($socket, false);
        $this->socket = $socket;
        Timer::add(0.01, function (): void {
            if (!is_resource($this->socket)) return;
            for ($i = 0; $i < 50; $i++) {
                $peer = '';
                $packet = stream_socket_recvfrom($this->socket, 4096, 0, $peer);
                if (!is_string($packet) || $packet === '') break;
                try {
                    $response = (new RadiusAccountingService())->handle($this->peerIp($peer), $packet);
                    if ($response !== null) stream_socket_sendto($this->socket, $response, 0, $peer);
                } catch (\Throwable $exception) {
                    Log::error('SandIAM RADIUS accounting packet handling failed', ['exception_type' => $exception::class, 'exception_file' => basename($exception->getFile()), 'exception_line' => $exception->getLine()]);
                }
            }
        });
    }

    private function peerIp(string $peer): string
    {
        if (str_starts_with($peer, '[')) { $end = strpos($peer, ']'); return $end === false ? '' : substr($peer, 1, $end - 1); }
        $position = strrpos($peer, ':');
        return $position === false ? $peer : substr($peer, 0, $position);
    }
}

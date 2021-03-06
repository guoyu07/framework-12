<?php
namespace Lawoole\Server;

use Lawoole\Console\OutputStyle;
use Lawoole\Contracts\Foundation\ApplicationInterface;
use Lawoole\Swoole\Handlers\TcpServerSocketHandlerInterface;

class TcpServerSocketHandler implements TcpServerSocketHandlerInterface
{
    /**
     * 服务容器
     *
     * @var \Lawoole\Contracts\Foundation\ApplicationInterface
     */
    protected $app;

    /**
     * 控制台输出样式
     *
     * @var \Lawoole\Console\OutputStyle
     */
    protected $outputStyle;

    /**
     * 创建 Http 服务 Socket 处理器
     *
     * @param \Lawoole\Contracts\Foundation\ApplicationInterface $app
     */
    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;

        $this->outputStyle = $app->make(OutputStyle::class);
    }

    /**
     * 在服务 Socket 绑定到服务时调用
     *
     * @param \Lawoole\Swoole\Server $server
     * @param \Lawoole\Swoole\ServerSocket $serverSocket
     */
    public function onBind($server, $serverSocket)
    {
    }

    /**
     * 在服务 Socket 即将暴露调用
     *
     * @param \Lawoole\Swoole\Server $server
     * @param \Lawoole\Swoole\ServerSocket $serverSocket
     */
    public function onExport($server, $serverSocket)
    {
        $host = $serverSocket->getHost();
        $port = $serverSocket->getPort();

        $address = $host.($port ? ':'.$port : '');

        $this->outputStyle->line("Listen to {$address}.");
    }

    /**
     * 新连接进入时调用
     *
     * @param \Lawoole\Swoole\Server $server
     * @param \Lawoole\Swoole\ServerSocket $serverSocket
     * @param int $fd
     * @param int $reactorId
     */
    public function onConnect($server, $serverSocket, $fd, $reactorId)
    {
        $this->outputStyle->info("New connection created, fd: {$fd}, reactor id: {$reactorId}.");
    }

    /**
     * 从连接中取得数据时调用
     *
     * @param \Lawoole\Swoole\Server $server
     * @param \Lawoole\Swoole\ServerSocket $serverSocket
     * @param int $fd
     * @param int $reactorId
     * @param string $data
     */
    public function onReceive($server, $serverSocket, $fd, $reactorId, $data)
    {
        $this->outputStyle->info("Receive data, fd: {$fd}, reactor id: {$reactorId}, data:");

        $this->outputStyle->line($data);
    }

    /**
     * 当连接关闭时调用
     *
     * @param \Lawoole\Swoole\Server $server
     * @param \Lawoole\Swoole\ServerSocket $serverSocket
     * @param int $fd
     * @param int $reactorId
     */
    public function onClose($server, $serverSocket, $fd, $reactorId)
    {
        $this->outputStyle->info("Connection closed, fd: {$fd}, reactor id: {$reactorId}.");
    }
}

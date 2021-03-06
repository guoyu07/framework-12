<?php
namespace Lawoole\Swoole\Handlers;

interface WebSocketServerSocketHandlerInterface extends ServerSocketHandlerInterface
{
    /**
     * 打开 WebSocket 连接时调用
     *
     * @param \Lawoole\Swoole\Server $server
     * @param \Lawoole\Swoole\ServerSocket $serverSocket
     * @param \Swoole\Http\Request $request
     */
    public function onOpen($server, $serverSocket, $request);

     /**
      * 收到 WebSocket 握手请求时调用
      *
      * @param \Lawoole\Swoole\Server $server
      * @param \Lawoole\Swoole\ServerSocket $serverSocket
      * @param \Swoole\Http\Request $request
      * @param \Swoole\Http\Response $response
      */
     public function onHandShake($server, $serverSocket, $request, $response);

    /**
     * 收到 WebSocket 消息帧时调用
     *
     * @param \Lawoole\Swoole\Server $server
     * @param \Lawoole\Swoole\ServerSocket $serverSocket
     * @param \Swoole\WebSocket\Frame $frame
     */
    public function onMessage($server, $serverSocket, $frame);
}

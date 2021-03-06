<?php
namespace Lawoole\Routing;

use RuntimeException;

class ExecuteTimeoutException extends RuntimeException
{
    /**
     * 请求管理器
     *
     * @var \Lawoole\Routing\RequestManager
     */
    protected $requestManager;

    /**
     * 处理超时
     *
     * @var float
     */
    protected $timeout;

    /**
     * 创建超时异常
     *
     * @param \Lawoole\Routing\RequestManager $requestManager
     * @param float $timeout
     */
    public function __construct($requestManager, $timeout)
    {
        parent::__construct(sprintf(
            'The request "%s" exceeded the timeout of %0.3f seconds.',
            $requestManager->getRequest(), $timeout
        ));

        $this->requestManager = $requestManager;
        $this->timeout = $timeout;
    }

    /**
     * 获得请求管理器
     *
     * @return \Lawoole\Routing\RequestManager
     */
    public function getRequestManager()
    {
        return $this->requestManager;
    }

    /**
     * 获得处理超时
     *
     * @return float
     */
    public function getTimeout()
    {
        return $this->timeout;
    }
}

<?php
namespace Lawoole\Routing;

use ArrayObject;
use BadMethodCallException;
use Closure;
use Exception;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use JsonSerializable;
use Lawoole\Support\Facades\Server;
use Swoole\Timer;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class RequestManager
{
    /**
     * 服务容器
     *
     * @var \Lawoole\Application
     */
    protected $app;

    /**
     * 管理器编号
     *
     * @var string
     */
    protected $id;

    /**
     * 请求对象实例
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * 控制调度器
     *
     * @var \Lawoole\Routing\ControllerDispatcher
     */
    protected $dispatcher;

    /**
     * 事件调度器
     *
     * @var \Illuminate\Events\Dispatcher
     */
    protected $events;

    /**
     * 响应发送者
     *
     * @var \Closure
     */
    protected $sender;

    /**
     * 所有处理请求所使用到的中间件集合
     *
     * @var array
     */
    protected $middleware = [];

    /**
     * 请求是否已经处理
     *
     * @var bool
     */
    protected $handled = false;

    /**
     * 响应是否已经发送
     *
     * @var bool
     */
    protected $responded = false;

    /**
     * 创建请求管理器
     *
     * @param \Lawoole\Application $app
     * @param \Illuminate\Http\Request $request
     * @param \Closure $sender
     */
    public function __construct($app, Request $request, Closure $sender = null)
    {
        $this->app = $app;
        $this->request = $request;
        $this->sender = $sender;

        // 生成编号
        $this->id = Str::random(16);

        $this->dispatcher = $app['router.dispatcher'];
        $this->events = $app['events'];

        // 记录到容器中
        $app->instance("http.request.manager.{$this->id}", $this);
    }

    /**
     * 获得管理器
     *
     * @param \Illuminate\Contracts\Container\Container $container
     * @param string $id
     *
     * @return \Lawoole\Routing\RequestManager
     */
    public static function getManager(Container $container, $id)
    {
        if ($container->bound($key = "http.request.manager.{$id}")) {
            return $container->make($key);
        }

        return null;
    }

    /**
     * 获得管理器编号
     *
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * 获得请求对象
     *
     * @return \Illuminate\Http\Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * 设置响应发送者
     *
     * @param \Closure $sender
     */
    public function setSender(Closure $sender)
    {
        $this->sender = $sender;
    }

    /**
     * 处理请求
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle()
    {
        if ($this->handled) {
            // 请求只能处理一次
            return null;
        }

        $this->handled = true;

        // 在请求开始处理时注入容器
        $this->app->instance(self::class, $this);
        $this->app->instance(Request::class, $this->request);

        try {
            $middleware = $this->dispatcher->getMiddleware();

            // 通过管道执行全局中间件
            $response = $this->sendThroughPipeline($middleware, function () {
                $request = $this->getRequest();

                // 请求方式和路径
                $method = $request->getMethod();
                $pathInfo = $request->getPathInfo();

                // 进行路由调度并处理调度结果
                return $this->handleFoundRoute(
                    $this->dispatcher->dispatch($method, $pathInfo)
                );
            });
        } catch (Exception $e) {
            // 处理异常
            $response = $this->handleException($this->request, $e);
        } catch (Throwable $e) {
            // 转换为异常
            $e = new FatalThrowableError($e);
            // 处理异常
            $response = $this->handleException($this->request, $e);
        }

        $this->sendResponse($response);

        // 在请求处理结束时注销容器内实例
        $this->app->forgetInstance(self::class);
        $this->app->forgetInstance(Request::class);

        return $response;
    }

    /**
     * 发送响应
     *
     * @param mixed $response
     */
    public function sendResponse($response)
    {
        if (!$response instanceof SymfonyResponse) {
            $response = $this->prepareResponse($response);
        }

        if ($this->responded) {
            return;
        }

        // 如果为异步响应不发生请求
        if ($response instanceof FutureResponse) {
            if (Server::inSwoole()) {
                $timeout = $response->getTimeout();

                // 运行在 Swoole 中，支持设置处理超时
                if ($timeout > 0) {
                    Timer::after($timeout * 1000, function () use ($timeout) {
                        // 处理超时，抛出处理超时异常
                        $response = $this->handleException(
                            $this->request, new ExecuteTimeoutException($this, $timeout)
                        );

                        $this->sendResponse($response);
                    });
                }
            }

            return;
        }

        $this->responded = true;

        try {
            // 如果设置了响应发送者，则通过响应发送者发送响应
            if ($this->sender) {
                call_user_func($this->sender, $response, $this);
            } // 如果没有设置响应发送者，则直接渲染到输出
            else {
                $response->send();
            }

            // 调用处理完成中间件。这个功能还有一定的缺陷，先不开放使用
            // $this->callTerminableMiddleware($this->request, $response);
        } catch (Throwable $e) {
            // 记录异常
            $this->handleException($this->request, $e);
        } finally {
            // 发送完成后，从容器中移除自身
            $this->app->forgetInstance("http.request.manager.{$this->id}");
        }
    }

    /**
     * 处理响应内容，准备响应
     *
     * @param mixed $response
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function prepareResponse($response)
    {
        if ($response === $this) {
            $response = new FutureResponse;
            $response->setRequestManager($this);
            $response->prepare($this->request);

            return $response;
        }

        return static::toResponse($this->request, $response);
    }

    /**
     * 转换响应
     *
     * @param \Illuminate\Http\Request $request
     * @param mixed $response
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function toResponse($request, $response)
    {
        if ($response instanceof Responsable) {
            $response = $response->toResponse($request);
        }

        if (!$response instanceof SymfonyResponse && static::isResponseArrayable($response)) {
            $response = new JsonResponse($response);
        } elseif (!$response instanceof SymfonyResponse) {
            $response = new Response($response);
        }

        if ($response->getStatusCode() === Response::HTTP_NOT_MODIFIED) {
            $response->setNotModified();
        }

        return $response->prepare($request);
    }

    /**
     * 判断响应是否可转换为数组
     *
     * @param mixed $response
     *
     * @return bool
     */
    protected static function isResponseArrayable($response)
    {
        return is_array($response)
            || $response instanceof Arrayable
            || $response instanceof Jsonable
            || $response instanceof ArrayObject
            || $response instanceof JsonSerializable;
    }

    /**
     * 通过管道发送请求处理
     *
     * @param array $middleware
     * @param \Closure $then
     *
     * @return mixed
     */
    protected function sendThroughPipeline(array $middleware, Closure $then)
    {
        if (count($middleware) > 0) {
            $pipeline = new Pipeline($this->app);

            return $pipeline->send($this->request)->through($middleware)->then($then);
        }

        return $then($this->request);
    }

    /**
     * 处理路由结果
     *
     * @param array $routeInfo
     *
     * @return mixed
     */
    protected function handleFoundRoute($routeInfo)
    {
        $action = $routeInfo['action'];

        // 如果有路由中间件，就要用管道去调用
        if (isset($action['middleware'])) {
            // 路由中间件里支持别名、参数一类的定义，这里先要解析出来
            $middleware = $this->parseRouteMiddlewareDefinitions($action['middleware']);

            // 记录到中间件使用记录中
            $this->middleware = array_merge($this->middleware, $middleware);

            // 通过管道处理路由中间件
            return $this->sendThroughPipeline($middleware, function () use ($routeInfo) {
                return $this->callActionOnArrayBasedRoute($routeInfo);
            });
        }

        return $this->prepareResponse(
            $this->callActionOnArrayBasedRoute($routeInfo)
        );
    }

    /**
     * 解析路由中间件定义
     *
     * @param string|array $middleware
     *
     * @return array
     */
    protected function parseRouteMiddlewareDefinitions($middleware)
    {
        $middleware = is_string($middleware) ? explode('|', $middleware) : (array) $middleware;

        $routeMiddleware = $this->dispatcher->getRouteMiddleware();

        return array_map(function ($name) use ($routeMiddleware) {
            list($name, $parameters) = array_pad(explode(':', $name, 2), 2, null);

            return array_get($routeMiddleware, $name, $name).($parameters ? ':'.$parameters : '');
        }, $middleware);
    }

    /**
     * 分析路由信息并调用对应操作
     *
     * @param array $routeInfo
     *
     * @return mixed
     */
    protected function callActionOnArrayBasedRoute(array $routeInfo)
    {
        $action = $routeInfo['action'];

        if (isset($action['uses'])) {
            return $this->callControllerAction($routeInfo);
        }

        foreach ($action as $value) {
            if ($value instanceof Closure) {
                $closure = $value;
                break;
            }
        }

        if (empty($closure)) {
            throw new BadMethodCallException('No action defined in the route');
        }

        $arguments = $routeInfo['action']['arguments'] ?? [];

        return $this->app->call($closure, $arguments);
    }

    /**
     * 调用控制器操作
     *
     * @param array $routeInfo
     *
     * @return mixed
     */
    protected function callControllerAction($routeInfo)
    {
        $uses = $routeInfo['action']['uses'];
        $arguments = $routeInfo['action']['arguments'] ?? [];

        if (is_string($uses) && strpos($uses, '@') === false) {
            $uses .= '@__invoke';
        }

        list($controller, $method) = explode('@', $uses);

        if (!method_exists($instance = $this->app->make($controller), $method)) {
            throw new NotFoundHttpException;
        }

        return $this->callActionCallable([$instance, $method], $arguments);
    }

    /**
     * 调用控制器获得响应
     *
     * @param callable $callable
     * @param array $parameters
     *
     * @return Response
     */
    protected function callActionCallable(callable $callable, array $parameters = [])
    {
        try {
            $response = $this->app->call($callable, $parameters);
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
        }

        return $response;
    }

    /**
     * 处理异常
     *
     * @param \Illuminate\Http\Request $request
     * @param \Exception $e
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function handleException($request, Exception $e)
    {
        try {
            $handler = $this->app->make(ExceptionHandler::class);

            // 报告异常
            $handler->report($e);

            // 返回渲染结果
            return $handler->render($request, $e);
        } catch (Exception $ex) {
            // 报告异常
            $this->reportException($ex);
        } catch (Throwable $ex) {
            $ex = new FatalThrowableError($ex);
            // 报告异常
            $this->reportException($ex);
        }

        // 处理异常时发生错误，响应空内容
        return null;
    }

    /**
     * 向异常处理器报告异常
     *
     * @param \Exception $e
     */
    protected function reportException(Exception $e)
    {
        try {
            $handler = $this->app->make(ExceptionHandler::class);

            // 报告异常
            $handler->report($e);
        } catch (Throwable $e) {
            // 遇到异常不处理
        }
    }

    /**
     * 调用处理完成中间件
     *
     * @param \Illuminate\Http\Request $request
     * @param \Symfony\Component\HttpFoundation\Response $response
     */
    public function callTerminableMiddleware($request, $response)
    {
        // 逐一调用已记录的处理中间件
        array_walk($this->middleware, function ($middleware) use ($request, $response) {
            if (!is_string($middleware)) {
                return;
            }

            $instance = $this->app->make(explode(':', $middleware)[0]);

            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        });
    }
}

### example

```php
<?php declare(strict_types=1);

use Cclilshy\WorkerCoroutine\Coroutine\CoroutineMap;
use Cclilshy\WorkerCoroutine\Output;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;

include __DIR__ . '/vendor/autoload.php';

$tcpService                = new \Workerman\Worker('tcp://127.0.0.1:8001');
$tcpService->onWorkerStart = fn() => install();
$tcpService->onMessage     = function (TcpConnection $tcpConnection, string $message) {
    /**
     * Demo1: 不堵塞延时5秒后向客户端发送数据
     */
    Co\async(function () use ($tcpConnection, $message) {
        \Co\sleep(3);
        $tcpConnection->send('sleep 3,you say:' . $message);
    });

    /**
     *
     * Demo2: 不堵塞延时5秒后向客户端发送数据
     */
    Co\async(function () use ($tcpConnection, $message) {
        \Co\sleep(5);
        $tcpConnection->send('sleep 5,you say:' . $message);

//        JsonRpcClient::call([TcpWorker::class,'test'],$message);
    })
        ->defer(function () {
            // 协程结束时运行,用于回收内建资源
        })
        ->timeout(function () {
            // 超时触发
        }, 5)
        ->except(function (Throwable $throwable) {
            // 异常收敛于此
        });
};

// 上述两个协程同一进程内调用栈独立,若使用引用传递,可以互相共享变量

try {
    $tcpService->run();
} catch (Exception $exception) {
    Output::printException($exception);
}
\Workerman\Worker::runAll();


/**
 * 安装协程辅助
 * @return void
 */
function install(): void
{
    CoroutineMap::initialize();
    \Cclilshy\WorkerCoroutine\Timer::initialize();

    // 计时器心跳
    Timer::add(1, fn() => \Cclilshy\WorkerCoroutine\Timer::heartbeat());

    // 高频心跳消费
    Timer::add(1, fn() => CoroutineMap::consumption());
    // 低频心跳清理
    Timer::add(1, fn() => CoroutineMap::gc());
}
```

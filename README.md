### install

```shell
composer require cclilshy/worker-coroutine
```

### example

```php
<?php declare(strict_types=1);

use Cclilshy\WorkerCoroutine\Support\Loader;
use Cclilshy\WorkerCoroutine\Support\Output;
use Workerman\Connection\TcpConnection;
use Workerman\Worker;

include __DIR__ . '/../vendor/autoload.php';

$tcpService = new Worker('tcp://127.0.0.1:8001');

$tcpService->onWorkerStart = function () {
    Loader::install();
};

$tcpService->onMessage = function (TcpConnection $tcpConnection, string $message) {
    /**
     * Demo1: 不堵塞延时3秒后向客户端发送数据
     */
    Co\async(function () use ($tcpConnection, $message) {
        Co\sleep(3);
        $tcpConnection->send('sleep 3,you say:' . $message);
    });

    /**
     * Demo2: 不堵塞延时5秒后向客户端发送数据
     */
    Co\async(function () use ($tcpConnection, $message) {
        Co\sleep(5);
        $tcpConnection->send('sleep 5,you say:' . $message);
    })
        ->defer(function () {
            //TODO: 协程结束时运行,用于回收内建资源
        })
        ->timeout(function () {
            //TODO: 超时触发
        }, 10)
        ->except(function (Throwable $throwable) {
            //TODO: 异常收敛于此
        });

    /**
     * 上述两个协程同一进程内调用栈独立,若使用引用传递,可以互相共享变量
     */
};


try {
    $tcpService->run();
    Worker::runAll();
} catch (Exception $exception) {
    Output::printException($exception);
}

```

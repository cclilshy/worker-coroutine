<?php

namespace Cclilshy\WorkerCoroutine\Support;

use Cclilshy\WorkerCoroutine\Coroutine\CoroutineMap;
use Cclilshy\WorkerCoroutine\Support\Timer as CoroutineTimer;
use Workerman\Timer;

class Loader
{
    /**
     * @return void
     */
    public static function install(): void
    {
        CoroutineMap::initialize();
        CoroutineTimer::initialize();
        // 计时器心跳
        Timer::add(1, fn() => CoroutineTimer::heartbeat());
        // 高频心跳消费
        Timer::add(1, fn() => CoroutineMap::consumption());
        // 低频心跳清理
        Timer::add(1, fn() => CoroutineMap::gc());
    }
}

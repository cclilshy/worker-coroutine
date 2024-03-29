<?php declare(strict_types=1);
/*
 * Copyright (c) 2023 cclilshy
 * Contact Information:
 * Email: jingnigg@gmail.com
 * Website: https://cc.cloudtay.com/
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 版权所有 (c) 2023 cclilshy
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */


namespace Cclilshy\WorkerCoroutine\Coroutine;

use Cclilshy\WorkerCoroutine\Coroutine\Exception\TimeoutException;
use Cclilshy\WorkerCoroutine\Standard\MapInterface;
use Cclilshy\WorkerCoroutine\Support\Output;
use Fiber;
use SplPriorityQueue;
use Throwable;
use function spl_object_hash;

/**
 * @Class CoroutineMap
 */
final class CoroutineMap implements MapInterface
{
    /**
     * @var Coroutine[] $coroutineMap
     */
    public static array             $coroutineMap = [];
    private static SplPriorityQueue $timeoutCollector;

    /**
     * @var SplPriorityQueue<Coroutine>
     */
    private static SplPriorityQueue $queue;
    private static int              $count = 0;

    /**
     * 根据Hash获得某个协程对象
     * @param string $hash
     * @return Coroutine|null
     */
    public static function get(string $hash): Coroutine|null
    {
        return CoroutineMap::$coroutineMap[$hash] ?? null;
    }

    /**
     * 获得当前栈所处协程对象
     * @return Coroutine|null
     */
    public static function this(): Coroutine|null
    {
        if ($fiber = Fiber::getCurrent()) {
            return CoroutineMap::$coroutineMap[spl_object_hash($fiber)] ?? null;
        }
        return null;
    }

    /**
     * 建立一个协程索引
     * @param Coroutine $coroutine
     * @return void
     */
    public static function insert(Coroutine $coroutine): void
    {
        CoroutineMap::$coroutineMap[$coroutine->hash] = $coroutine;
    }

    /**
     * 移除一个协程
     * @param Coroutine $coroutine
     * @return void
     */
    public static function remove(Coroutine $coroutine): void
    {
        unset(CoroutineMap::$coroutineMap[spl_object_hash($coroutine->fiber)]);
    }

    /**
     * 恢复协程执行
     * @param string      $hash
     * @param mixed       $data
     * @param string|null $flag
     * @return mixed
     * @throws Throwable
     */
    public static function resume(string $hash, mixed $data, string|null $flag = null): mixed
    {
        if ($coroutine = CoroutineMap::get($hash)) {
            if ($flag) {
                $coroutine->erase($flag);
            }
            return $coroutine->resume($data);
        }
        return null;
    }

    /**
     * 外部向协程抛入一个异常
     * @param string $hash
     * @param mixed  $exception
     * @return void
     */
    public static function throw(string $hash, Throwable $exception): void
    {
        CoroutineMap::get($hash)?->throw($exception);
    }

    /**
     * 生产协程
     * @param Coroutine $coroutine
     * @return void
     */
    public static function production(Coroutine $coroutine): void
    {
        CoroutineMap::$queue->insert($coroutine, CoroutineMap::$count--);
    }

    /**
     * 消费协程
     * @return void
     */
    public static function consumption(): void
    {
        while (!CoroutineMap::$queue->isEmpty()) {
            $coroutine = CoroutineMap::$queue->extract();
            try {
                $coroutine->execute();
            } catch (Throwable $exception) {
                Output::printException($exception);
            }
        }
    }

    /**
     * 监听协程超时
     * @param string $hash
     * @param int    $timeout
     * @return void
     */
    public static function timer(string $hash, int $timeout): void
    {
        CoroutineMap::$timeoutCollector->insert($hash, (time() + $timeout * -1));
    }

    /**
     * 清理器
     * @return void
     */
    public static function gc(): void
    {
        foreach (CoroutineMap::$coroutineMap as $coroutine) {
            if ($coroutine->terminated()) {
                Output::info("warning: discover a coroutine that has ended: '{$coroutine->hash}");
            } elseif (count($coroutine->flags) === 0) {
                Output::info("warning: A process with a count of  was found: {$coroutine->hash}");
            }
        }
        $baseTime = time();
        while (!CoroutineMap::$timeoutCollector->isEmpty()) {
            $hash = CoroutineMap::$timeoutCollector->top();
            if (!$coroutine = CoroutineMap::get($hash)) {
                CoroutineMap::$timeoutCollector->extract();
            } elseif ($coroutine->timeout <= $baseTime || count($coroutine->flags) === 0) {
                CoroutineMap::$timeoutCollector->extract();
                if ($coroutine->terminated()) {
                    $coroutine->destroy();
                } else {
                    try {
                        $coroutine->throw(new TimeoutException('The run time exceeds the maximum limit'));
                    } catch (Throwable $exception) {
                        Output::printException($exception);
                    }
                }
            } else {
                break;
            }
        }
    }

    /**
     * 进程被fork后应执行
     * @return void
     */
    public static function forkPassive(): void
    {
        CoroutineMap::$coroutineMap = [];
        while (!CoroutineMap::$timeoutCollector->isEmpty()) {
            CoroutineMap::$timeoutCollector->extract();
        }
    }

    /**
     * 初始化队列对象
     * @return void
     */
    public static function initialize(): void
    {
        CoroutineMap::$timeoutCollector = new SplPriorityQueue();
        CoroutineMap::$queue            = new SplPriorityQueue();
    }
}

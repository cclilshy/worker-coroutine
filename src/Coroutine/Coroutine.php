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

use Cclilshy\Container\Container;
use Cclilshy\WorkerCoroutine\Coroutine\Exception\Exception;
use Cclilshy\WorkerCoroutine\Coroutine\Exception\TimeoutException;
use Cclilshy\WorkerCoroutine\Event\Event;
use Cclilshy\WorkerCoroutine\Support\Output;
use Closure;
use Fiber;
use Throwable;
use function call_user_func_array;

/**
 * 任务标准
 */
class Coroutine extends Container
{
    /**
     * 内置事件常量
     */
    public const string  EVENT_TIMEOUT   = 'system.coroutine.timeout';
    public const string  EVENT_EXCEPTION = 'system.coroutine.exception';
    public const string  EVENT_RESUME    = 'system.coroutine.resume';
    public const string  EVENT_END       = 'system.coroutine.end';
    public const string  EVENT_SUSPEND   = 'system.coroutine.suspend';
    private const string FLAG_MAIN       = 'system.coroutine.flag.main';

    /**
     * 任务唯一标识
     * @var string $hash
     */
    public string $hash;

    /**
     * 协程实例
     * @var Fiber $fiber
     */
    public Fiber $fiber;

    /**
     * 异步事件订阅列表
     * @var Closure[] $asyncHandlers
     */
    public array $asyncHandlers = [];

    /**
     * 最终执行
     * @var Closure[] $defers
     */
    public array $defers = [];

    /**
     * 超时执行
     * @var int $timeout
     */
    public int $timeout;

    /**
     * 标记
     * @var array $flags
     */
    public array $flags = [];

    /**
     * 装配入口函数
     * @param Closure $callable
     * @return static
     */
    public function setup(Closure $callable): Coroutine
    {
        $this->fiber = new Fiber(fn() => $this->entrance($callable));
        $this->hash  = spl_object_hash($this->fiber);
        $this->flag(Coroutine::FLAG_MAIN);
        CoroutineMap::insert($this);
        $this->inject(Coroutine::class, $this);
        return $this;
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function entrance(Closure $closure): void
    {
        try {
            $this->callUserFunction($closure);
        } catch (Throwable $exception) {
            $this->processException($exception);
        } finally {
            $this->erase(Coroutine::FLAG_MAIN);
        }
        $this->finalize();
    }

    /**
     * 结束处理
     * @return void
     */
    private function finalize(): void
    {
        $this->runDefers();
        $this->suspendIfNeeded();
        $this->destroy();
    }

    /**
     * 执行最终回调
     * @return void
     */
    private function runDefers(): void
    {
        foreach ($this->defers as $defer) {
            try {
                $this->callUserFunction($defer);
            } catch (Throwable $exception) {
                $this->processException($exception);
            }
        }
    }

    /**
     * 如有必要，挂起协程
     * @return void
     */
    private function suspendIfNeeded(): void
    {
        if (count($this->flags) > 0) {
            try {
                $this->suspend();
            } catch (Throwable $exception) {
                $this->processException($exception);
            }
        }
    }

    /**
     * 处理异常
     * @param Throwable $exception
     * @return void
     */
    private function processException(Throwable $exception): void
    {
        $this->flag(Coroutine::EVENT_EXCEPTION);
        $this->handleEvent(Event::new(
            $exception instanceof TimeoutException
                ? Coroutine::EVENT_TIMEOUT
                : Coroutine::EVENT_EXCEPTION,
            $exception,
            $this->hash)
        );
        $this->erase(Coroutine::EVENT_EXCEPTION);
    }

    /**
     * Synchronous emitting an event,
     * Captured by the last caller, usually not required,
     * Because the caller logs the event
     * @return mixed
     * @throws Throwable
     */
    public function suspend(): mixed
    {
        wait:
        if (!$event = Fiber::suspend(Event::new(Coroutine::EVENT_SUSPEND, null, $this->hash))) {
            throw new Exception('This should never happen');
        } elseif (!$event instanceof Event) {
            throw new Exception('This should never happen');
        } elseif ($event->name === Coroutine::EVENT_RESUME) {
            return $event->data;
        }
        $this->handleEvent($event);
        if (count($this->flags) === 0) {
            $this->destroy();
            Fiber::suspend(Event::new(Coroutine::EVENT_END, null, $this->hash));
        } else {
            goto wait;
        }
        return false;
    }

    /**
     * 处理事件
     * @param Event $event
     * @return void
     */
    public function handleEvent(Event $event): void
    {
        if ($handler = $this->asyncHandlers[$event->name] ?? null) {
            try {
                call_user_func_array($handler, [$event->data, $event, $this]) !== false;
            } catch (Throwable $exception) {
                $this->processException($exception);
            }
        } elseif ($event->data instanceof Throwable) {
            Output::printException($event->data);
        }
    }

    /**
     * 立即执行协程
     * @return mixed
     * @throws Exception|Throwable
     */
    public function execute(): mixed
    {
        if (!isset($this->fiber)) {
            throw new Exception('Coroutine not setup');
        }
        return $this->fiber->start($this);
    }

    /**
     * 协程抛入队列而非立即执行
     * @throws Exception
     */
    public function queue(): void
    {
        if (!isset($this->fiber)) {
            throw new Exception('Coroutine not setup');
        }
        CoroutineMap::production($this);
    }

    /**
     * 恢复协程执行
     * @param mixed|null $value
     * @return mixed
     * @throws Throwable
     */
    public function resume(mixed $value = null): mixed
    {
        return $this->fiber->resume($value);
    }

    /**
     * 向协程抛出一个异常,由挂起处获取
     * @param Throwable $exception
     * @return void
     */
    public function throw(Throwable $exception): void
    {
        try {
            $this->fiber->throw($exception);
        } catch (Throwable $exception) {
            Output::printException($exception);
        }
    }

    /**
     * 设置超时处理
     * @param Closure $closure
     * @param int     $time
     * @return $this
     */
    public function timeout(Closure $closure, int $time): static
    {
        $this->timeout = time() + $time;
        CoroutineMap::timer($this->hash, $time);
        $this->on(Coroutine::EVENT_TIMEOUT, $closure);
        return $this;
    }

    /**
     * 设置错误处理器
     * @param Closure $closure
     * @return $this
     */
    public function except(Closure $closure): static
    {
        $this->on(Coroutine::EVENT_EXCEPTION, $closure);
        return $this;
    }

    /**
     * 设置最终执行
     * @param Closure $closure
     * @return Coroutine
     */
    public function defer(Closure $closure): Coroutine
    {
        $this->defers[] = $closure;
        return $this;
    }

    /**
     * 订阅异步事件
     * @param string  $eventName
     * @param Closure $callable
     * @return void
     */
    public function on(string $eventName, Closure $callable): void
    {
        $this->asyncHandlers[$eventName] = $callable;
    }

    /**
     * @param string $key
     * @return void
     */
    public function flag(string $key): void
    {
        if (isset($this->flags[$key])) {
            $this->flags[$key]++;
        } else {
            $this->flags[$key] = 1;
        }
    }

    /**
     * @param string $key
     * @return void
     */
    public function erase(string $key): void
    {
        if (isset($this->flags[$key])) {
            $this->flags[$key]--;
            if ($this->flags[$key] === 0) {
                unset($this->flags[$key]);
            }
        }
    }

    /**
     * 验证协程是否已终止
     * @return bool
     */
    public function terminated(): bool
    {
        return $this->fiber->isTerminated();
    }

    /**
     * 销毁自身
     * @return true
     */
    public function destroy(): true
    {
        CoroutineMap::remove($this);
        return true;
    }
}

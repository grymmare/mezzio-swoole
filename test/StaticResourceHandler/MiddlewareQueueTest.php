<?php

/**
 * @see       https://github.com/mezzio/mezzio-swoole for the canonical source repository
 * @copyright https://github.com/mezzio/mezzio-swoole/blob/master/COPYRIGHT.md
 * @license   https://github.com/mezzio/mezzio-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace MezzioTest\Swoole\StaticResourceHandler;

use Mezzio\Swoole\StaticResourceHandler\MiddlewareInterface;
use Mezzio\Swoole\StaticResourceHandler\MiddlewareQueue;
use Mezzio\Swoole\StaticResourceHandler\StaticResourceResponse;
use MezzioTest\Swoole\AssertResponseTrait;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request;

class MiddlewareQueueTest extends TestCase
{
    use AssertResponseTrait;

    protected function setUp(): void
    {
        $this->request = $this->createMock(Request::class);
    }

    public function testEmptyMiddlewareQueueReturnsSuccessfulResponseValue()
    {
        $queue = new MiddlewareQueue([]);

        $response = $queue($this->request, 'some/filename.txt');

        $this->assertInstanceOf(StaticResourceResponse::class, $response);
        $this->assertStatus(200, $response);
        $this->assertHeadersEmpty($response);
        $this->assertShouldSendContent($response);
    }

    public function testReturnsResponseGeneratedByMiddleware()
    {
        $response = $this->createMock(StaticResourceResponse::class);

        $middleware = $this->createMock(MiddlewareInterface::class);
        $middleware
            ->method('__invoke')
            ->with($this->request, 'some/filename.txt', $this->isInstanceOf(MiddlewareQueue::class))
            ->willReturn($response);

        $queue = new MiddlewareQueue([$middleware]);

        $result = $queue($this->request, 'some/filename.txt');

        $this->assertSame($response, $result);
    }

    public function testEachMiddlewareReceivesSameQueueInstance()
    {
        $second = $this->createMock(MiddlewareInterface::class);

        $first = $this->createMock(MiddlewareInterface::class);
        $first
            ->method('__invoke')
            ->with($this->request, 'some/filename.txt', $this->isInstanceOf(MiddlewareQueue::class))
            ->will($this->returnCallback(
                function (Request $request, string $filename, callable $middlewareQueue) use ($second) {
                    $second
                        ->method('__invoke')
                        ->with($request, $filename, $middlewareQueue)
                        ->will($this->returnCallback(
                            function (Request $request, string $filename, callable $middlewareQueue) {
                                $response = $middlewareQueue($request, $filename);
                                $response->setStatus(304);
                                $response->addHeader('X-Hit', 'second');
                                $response->disableContent();
                                return $response;
                            }
                        ));

                    return $middlewareQueue($request, $filename);
                }
            ));

        $queue = new MiddlewareQueue([
            $first,
            $second,
        ]);

        $response = $queue($this->request, 'some/filename.txt');

        $this->assertInstanceOf(StaticResourceResponse::class, $response);
        $this->assertStatus(304, $response);
        $this->assertHeaderExists('X-Hit', $response);
        $this->assertHeaderSame('second', 'X-Hit', $response);
        $this->assertShouldNotSendContent($response);
    }
}

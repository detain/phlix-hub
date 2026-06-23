<?php

declare(strict_types=1);

namespace Phlix\Hub\Tests\Unit\Relay;

use Phlix\Hub\Common\Logger\StructuredLogger;
use Phlix\Hub\Relay\RelayProxyBridge;
use Phlix\Hub\Relay\RelayProxyProtocol;
use PHPUnit\Framework\TestCase;

use function base64_decode;

/**
 * @covers \Phlix\Hub\Relay\RelayProxyBridge
 */
final class RelayProxyBridgeTest extends TestCase
{
    public function test_reply_event_is_unique_per_instance(): void
    {
        $a = new RelayProxyBridge($this->createMock(StructuredLogger::class));
        $b = new RelayProxyBridge($this->createMock(StructuredLogger::class));

        $this->assertStringStartsWith('phlix.relay.proxy.reply.', $a->replyEvent());
        $this->assertNotSame($a->replyEvent(), $b->replyEvent());
    }

    public function test_request_publishes_envelope_and_returns_reply(): void
    {
        /** @var array<string, mixed>|null $publishedData */
        $publishedData = null;
        $publishedEvent = null;

        // The publisher stands in for the relay worker: echo a reply straight
        // back through onReply so the blocked request resolves synchronously.
        $bridge = null;
        $publisher = function (string $event, array $data) use (&$bridge, &$publishedData, &$publishedEvent): void {
            $publishedEvent = $event;
            $publishedData = $data;
            /** @var RelayProxyBridge $bridge */
            $bridge->onReply([
                'request_id' => $data['request_id'],
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json'],
                'body_b64' => base64_encode('{"ok":true}'),
            ]);
        };

        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class), $publisher);

        $result = $bridge->request(
            'srv-1',
            'GET',
            '/api/v1/libraries',
            'a=1',
            ['Accept' => 'application/json'],
            '',
            5.0,
        );

        $this->assertSame(RelayProxyProtocol::REQUEST_EVENT, $publishedEvent);
        $this->assertIsArray($publishedData);
        $this->assertSame('srv-1', $publishedData['server_id']);
        $this->assertSame('GET', $publishedData['method']);
        $this->assertSame('/api/v1/libraries', $publishedData['path']);
        $this->assertSame('a=1', $publishedData['query']);
        $this->assertSame($bridge->replyEvent(), $publishedData['reply_event']);

        $this->assertIsArray($result);
        $this->assertSame(200, $result['status']);
        $this->assertSame('{"ok":true}', base64_decode((string) $result['body_b64'], true));
    }

    public function test_request_returns_null_when_no_reply_arrives(): void
    {
        // Publisher does nothing → the channel stays empty → pop returns false.
        $bridge = new RelayProxyBridge(
            $this->createMock(StructuredLogger::class),
            static function (string $event, array $data): void {
                // no reply
            },
        );

        $result = $bridge->request('srv-1', 'GET', '/x', '', [], '', 0.01);
        $this->assertNull($result);
    }

    public function test_on_reply_for_unknown_request_is_noop(): void
    {
        $bridge = new RelayProxyBridge($this->createMock(StructuredLogger::class));
        // Should not throw.
        $bridge->onReply(['request_id' => 'nope', 'status' => 200]);
        $this->assertTrue(true);
    }
}

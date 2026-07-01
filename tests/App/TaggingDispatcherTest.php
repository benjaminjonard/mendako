<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Entity\StagedUpload;
use App\Message\GenerateSuggestionsMessage;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\TaggingDispatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

class TaggingDispatcherTest extends TestCase
{
    private function provider(bool $enabled): AutoTagConfigProvider
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);

        return $provider;
    }

    public function test_dispatches_post_message_when_enabled(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $m): bool => $m instanceof GenerateSuggestionsMessage && $m->targetType === 'post'))
            ->willReturn(new Envelope(new \stdClass()));

        $post = new Post();
        $post->setPath('uploads/boards/1/x.png');

        (new TaggingDispatcher($bus, $this->provider(true)))->dispatch($post);
    }

    public function test_dispatches_staged_message_when_enabled(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn (object $m): bool => $m instanceof GenerateSuggestionsMessage && $m->targetType === 'staged'))
            ->willReturn(new Envelope(new \stdClass()));

        $staged = new StagedUpload();
        $staged->setPath('uploads/staging/y.png');

        (new TaggingDispatcher($bus, $this->provider(true)))->dispatch($staged);
    }

    public function test_does_not_dispatch_when_disabled(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        (new TaggingDispatcher($bus, $this->provider(false)))->dispatch(new Post());
    }

    public function test_dispatch_batch_routes_to_autotag_batch_transport(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->with(
            $this->callback(static fn (object $m): bool => $m instanceof GenerateSuggestionsMessage && $m->targetType === 'post'),
            $this->callback(static function (array $stamps): bool {
                foreach ($stamps as $stamp) {
                    if ($stamp instanceof TransportNamesStamp && $stamp->getTransportNames() === ['autotag_batch']) {
                        return true;
                    }
                }

                return false;
            }),
        )->willReturn(new Envelope(new \stdClass()));

        $post = new Post();
        $post->setPath('uploads/boards/1/x.png');

        (new TaggingDispatcher($bus, $this->provider(true)))->dispatchBatch($post);
    }

    public function test_dispatch_batch_no_op_when_disabled(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        (new TaggingDispatcher($bus, $this->provider(false)))->dispatchBatch(new Post());
    }
}

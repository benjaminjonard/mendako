<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\TaggingDispatcher;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

class TaggingDispatcherRoutingTest extends KernelTestCase
{
    private function dispatcher(): TaggingDispatcher
    {
        self::bootKernel();
        $autoTagConfig = $this->createStub(AutoTagConfigProvider::class);
        $autoTagConfig->method('isEnabled')->willReturn(true);
        $autoTagConfig->method('isBoardEnabled')->willReturn(true);
        self::getContainer()->set(AutoTagConfigProvider::class, $autoTagConfig);

        return self::getContainer()->get(TaggingDispatcher::class);
    }

    private function transport(string $name): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.'.$name);
    }

    private function post(): Post
    {
        $post = new Post();
        $post->setPath('uploads/boards/1/x.png');

        return $post;
    }

    public function test_interactive_dispatch_routes_to_autotag_interactive(): void
    {
        $this->dispatcher()->dispatch($this->post());

        $this->assertCount(1, $this->transport('autotag_interactive')->getSent());
        $this->assertCount(0, $this->transport('autotag_batch')->getSent());
    }

    public function test_batch_dispatch_routes_to_autotag_batch(): void
    {
        $this->dispatcher()->dispatchBatch($this->post());

        $this->assertCount(1, $this->transport('autotag_batch')->getSent());
        $this->assertCount(0, $this->transport('autotag_interactive')->getSent());
    }
}

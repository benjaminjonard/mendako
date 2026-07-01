<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Message\EnqueueBacklogMessage;
use App\MessageHandler\EnqueueBacklogHandler;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\BacklogEnqueuer;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class EnqueueBacklogHandlerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function handler(bool $enabled): EnqueueBacklogHandler
    {
        self::bootKernel();
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('isEnabled')->willReturn($enabled);
        // Stub the container's provider too, so the TaggingDispatcher inside BacklogEnqueuer
        // (which gates dispatchBatch) is enabled as well.
        self::getContainer()->set(AutoTagConfigProvider::class, $provider);

        return new EnqueueBacklogHandler(
            $provider,
            self::getContainer()->get(BacklogEnqueuer::class),
        );
    }

    private function batchTransport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.autotag_batch');
    }

    private function createPost(): \App\Entity\Post
    {
        return PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne()]);
    }

    private function addSuggestion(string $postId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new TagSuggestion())->setTargetType('post')->setTargetId($postId)->setTagName('cat')->setScore(0.9)->setSource(TagSuggestion::SOURCE_WD));
        $em->flush();
    }

    public function test_fans_out_only_posts_without_suggestions(): void
    {
        $handler = $this->handler(true);
        $this->createPost();
        $this->createPost();
        $processed = $this->createPost();
        $this->addSuggestion($processed->getId());

        $handler(new EnqueueBacklogMessage('post', false));

        $this->assertCount(2, $this->batchTransport()->getSent()); // suggested post skipped
    }

    public function test_all_fans_out_every_post(): void
    {
        $handler = $this->handler(true);
        $this->createPost();
        $processed = $this->createPost();
        $this->addSuggestion($processed->getId());

        $handler(new EnqueueBacklogMessage('post', true));

        $this->assertCount(2, $this->batchTransport()->getSent());
    }

    public function test_no_op_when_disabled(): void
    {
        $handler = $this->handler(false);
        $this->createPost();

        $handler(new EnqueueBacklogMessage('post', false));

        $this->assertCount(0, $this->batchTransport()->getSent());
    }
}

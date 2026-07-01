<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TagBacklogCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function commandTester(bool $autoTagEnabled): CommandTester
    {
        $kernel = self::bootKernel();
        $autoTagConfig = $this->createStub(AutoTagConfigProvider::class);
        $autoTagConfig->method('isEnabled')->willReturn($autoTagEnabled);
        self::getContainer()->set(AutoTagConfigProvider::class, $autoTagConfig);

        $application = new Application($kernel);

        return new CommandTester($application->find('app:autotag:tag-backlog'));
    }

    private function batchTransport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.autotag_batch');
    }

    private function createPost(): \App\Entity\Post
    {
        return PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne()]);
    }

    private function addSuggestion(string $targetId, string $status = TagSuggestion::STATUS_PENDING, string $targetType = 'post'): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new TagSuggestion())
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setTagName('existing')
            ->setScore(0.5)
            ->setSource(TagSuggestion::SOURCE_WD)
            ->setStatus($status));
        $em->flush();
    }

    private function createStaged(): \App\Entity\StagedUpload
    {
        return \App\Tests\Factory\StagedUploadFactory::createOne();
    }

    public function test_enqueues_only_posts_without_suggestions(): void
    {
        $tester = $this->commandTester(true);
        $this->createPost();
        $this->createPost();
        $withSuggestion = $this->createPost();
        $this->addSuggestion($withSuggestion->getId());

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(2, $this->batchTransport()->getSent()); // the suggested post is skipped
    }

    public function test_all_flag_enqueues_every_post(): void
    {
        $tester = $this->commandTester(true);
        $this->createPost();
        $withSuggestion = $this->createPost();
        $this->addSuggestion($withSuggestion->getId());

        $tester->execute(['--all' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(2, $this->batchTransport()->getSent());
    }

    public function test_excludes_post_with_only_a_dismissed_suggestion(): void
    {
        // "Without suggestions" means "never automatic tagging-processed": a post whose only suggestion
        // was dismissed has been processed and must not be re-enqueued by default.
        $tester = $this->commandTester(true);
        $this->createPost(); // fresh → enqueued
        $dismissed = $this->createPost();
        $this->addSuggestion($dismissed->getId(), TagSuggestion::STATUS_DISMISSED);

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(1, $this->batchTransport()->getSent()); // only the fresh post
    }

    public function test_staged_flag_enqueues_only_staged_without_suggestions(): void
    {
        $tester = $this->commandTester(true);
        $this->createStaged();
        $withSuggestion = $this->createStaged();
        $this->addSuggestion($withSuggestion->getId(), targetType: 'staged');
        $this->createPost(); // a post must NOT be enqueued by a --staged run

        $tester->execute(['--staged' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(1, $this->batchTransport()->getSent()); // only the fresh staged item
    }

    public function test_staged_all_enqueues_every_staged_item(): void
    {
        $tester = $this->commandTester(true);
        $this->createStaged();
        $withSuggestion = $this->createStaged();
        $this->addSuggestion($withSuggestion->getId(), targetType: 'staged');

        $tester->execute(['--staged' => true, '--all' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(2, $this->batchTransport()->getSent());
    }

    public function test_post_run_does_not_enqueue_staged_items(): void
    {
        $tester = $this->commandTester(true);
        $this->createStaged(); // staging present but not targeted

        $tester->execute([]); // default = posts

        $tester->assertCommandIsSuccessful();
        $this->assertCount(0, $this->batchTransport()->getSent());
    }

    public function test_no_op_when_ai_disabled(): void
    {
        $tester = $this->commandTester(false);
        $this->createPost();

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertCount(0, $this->batchTransport()->getSent());
        $this->assertStringContainsString('disabled', $tester->getDisplay());
    }
}

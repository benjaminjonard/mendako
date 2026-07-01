<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Post;
use App\Entity\TagSuggestion;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AutoTagBatchStatusCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:autotag:batch-status'));
    }

    private function createPost(): Post
    {
        return PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne()]);
    }

    private function addSuggestion(string $postId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new TagSuggestion())
            ->setTargetType('post')
            ->setTargetId($postId)
            ->setTagName('cat')
            ->setScore(0.9)
            ->setSource(TagSuggestion::SOURCE_WD));
        $em->flush();
    }

    public function test_reports_processed_total_and_in_progress(): void
    {
        $tester = $this->tester();
        $this->createPost(); // unprocessed
        $this->createPost(); // unprocessed
        $processed = $this->createPost();
        $this->addSuggestion($processed->getId());

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('1 / 3', $display); // 1 of 3 posts processed
        $this->assertStringContainsString('in progress', $display);
    }

    public function test_reports_complete_when_all_processed(): void
    {
        $tester = $this->tester();
        $post = $this->createPost();
        $this->addSuggestion($post->getId());

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Backlog complete', $tester->getDisplay());
    }

    public function test_empty_library_is_complete(): void
    {
        $tester = $this->tester();

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('0 / 0', $tester->getDisplay());
        $this->assertStringContainsString('Backlog complete', $tester->getDisplay());
    }

    public function test_batch_suggestions_are_never_written_as_confirmed_tags(): void
    {
        // AC2: a processed item's suggestions stay pending TagSuggestions; men_post_tag untouched.
        self::bootKernel();
        $post = $this->createPost();
        $this->addSuggestion($post->getId());

        $connection = self::getContainer()->get('doctrine')->getConnection();
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM men_post_tag WHERE post_id = ?', [$post->getId()]));
        $this->assertSame('pending', $connection->fetchOne('SELECT status FROM men_tag_suggestion WHERE target_id = ?', [$post->getId()]));
    }
}

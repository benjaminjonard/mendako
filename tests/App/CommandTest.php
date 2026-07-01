<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Enum\DatumTypeEnum;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\CollectionFactory;
use App\Tests\Factory\DatumFactory;
use App\Tests\Factory\ItemFactory;
use App\Tests\Factory\LogFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class CommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function test_command_regenerate_signature_words_is_successful(): void
    {
        // Arrange
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $command = $application->find('app:regenerate-vectors');
        $commandTester = new CommandTester($command);

        $user = UserFactory::createOne();
        $board = BoardFactory::createOne();
        $post = PostFactory::createOne(['board' => $board, 'uploadedBy' => $user]);

        $filesystem = new Filesystem();
        $uniqId = uniqid();

        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.avif', "/tmp/{$uniqId}.avif");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.avif", "{$uniqId}.avif", null, null, true);
        $post->setFile($uploadedFile);
        \Zenstruck\Foundry\Persistence\save($post);

        // Act
        $commandTester->execute([]);

        // Assert
        $commandTester->assertCommandIsSuccessful();
    }

    public function test_command_reindex_clip_embeddings_purges_and_preserves_tags(): void
    {
        // Arrange
        $kernel = self::bootKernel();
        $container = self::getContainer();
        // Enable the automatic tagging feature so the command re-dispatches the pipeline.
        $autoTagConfig = $this->createStub(\App\Service\AutoTag\AutoTagConfigProvider::class);
        $autoTagConfig->method('isEnabled')->willReturn(true);
        $container->set(\App\Service\AutoTag\AutoTagConfigProvider::class, $autoTagConfig);

        $user = UserFactory::createOne();
        $board = BoardFactory::createOne();
        $tag = \App\Tests\Factory\TagFactory::createOne();
        $post = PostFactory::createOne(['board' => $board, 'uploadedBy' => $user, 'tags' => [$tag]]);
        $post->setClipModelId('old-clip-model');
        \Zenstruck\Foundry\Persistence\save($post);
        $postId = $post->getId();

        $application = new Application($kernel);
        $commandTester = new CommandTester($application->find('app:autotag:reindex-clip-embeddings'));

        // Act
        $commandTester->execute(['dimension' => '1152']);

        // Assert
        $commandTester->assertCommandIsSuccessful();
        $connection = $container->get('doctrine')->getConnection();
        // Embedding binding purged...
        $this->assertNull($connection->fetchOne('SELECT clip_model_id FROM men_post WHERE id = ?', [$postId]));
        // ...but the confirmed tag survives (additive / no data loss).
        $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM men_post_tag WHERE post_id = ?', [$postId]));
        $this->assertStringContainsString('Re-dispatched', $commandTester->getDisplay());
    }
}

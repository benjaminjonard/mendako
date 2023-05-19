<?php

declare(strict_types=1);

namespace App;

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
        $command = $application->find('app:regenerate-signature-words');
        $commandTester = new CommandTester($command);

        $user = UserFactory::createOne()->object();
        $board = BoardFactory::createOne();
        $post = PostFactory::createOne(['board' => $board, 'uploadedBy' => $user]);

        $filesystem = new Filesystem();
        $uniqId = uniqid();

        $filesystem->copy(__DIR__.'/../../assets/fixtures/nyancat.avif', "/tmp/{$uniqId}.avif");
        $uploadedFile = new UploadedFile("/tmp/{$uniqId}.avif", "{$uniqId}.avif", null, null, true);
        $post->object()->setFile($uploadedFile);
        $post->save();

        // Act
        $commandTester->execute([]);

        // Assert
        $commandTester->assertCommandIsSuccessful();
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Tag;
use App\Repository\PostRepository;
use App\Repository\TagRepository;
use App\Service\TagMerger;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class TagMergeTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private TagMerger $merger;
    private TagRepository $tagRepository;
    private PostRepository $postRepository;
    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->merger = static::getContainer()->get(TagMerger::class);
        $this->tagRepository = static::getContainer()->get(TagRepository::class);
        $this->postRepository = static::getContainer()->get(PostRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    private function reloadedPostTagNames(string $postId): array
    {
        $this->entityManager->clear();
        $post = $this->postRepository->find($postId);

        return array_map(static fn (Tag $tag): string => (string) $tag->getName(), $post->getTags()->toArray());
    }

    public function testMergeTransfersPostsAndDeletesSources(): void
    {
        $target = TagFactory::createOne(['name' => 'cat']);
        $sourceA = TagFactory::createOne(['name' => 'kitten']);
        $sourceB = TagFactory::createOne(['name' => 'feline']);

        $postA = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne(), 'tags' => [$sourceA]]);
        $postB = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne(), 'tags' => [$sourceB]]);

        $merged = $this->merger->merge($target, [$sourceA, $sourceB]);

        self::assertSame(2, $merged);
        self::assertNull($this->tagRepository->findOneBy(['name' => 'kitten']));
        self::assertNull($this->tagRepository->findOneBy(['name' => 'feline']));
        self::assertSame(['cat'], $this->reloadedPostTagNames($postA->getId()));
        self::assertSame(['cat'], $this->reloadedPostTagNames($postB->getId()));
    }

    public function testTargetPostsArePreservedWithoutDuplicates(): void
    {
        $target = TagFactory::createOne(['name' => 'cat']);
        $source = TagFactory::createOne(['name' => 'kitten']);

        // A post carrying both the target and the source must end up with the target exactly once.
        $post = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne(), 'tags' => [$target, $source]]);

        $this->merger->merge($target, [$source]);

        self::assertSame(['cat'], $this->reloadedPostTagNames($post->getId()));
        self::assertNull($this->tagRepository->findOneBy(['name' => 'kitten']));
    }

    public function testTargetItselfAndDuplicatesAreIgnored(): void
    {
        $target = TagFactory::createOne(['name' => 'cat']);
        $source = TagFactory::createOne(['name' => 'kitten']);

        $post = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne(), 'tags' => [$source]]);

        // Target passed as a source is skipped; the duplicate source is only merged once.
        $merged = $this->merger->merge($target, [$target, $source, $source]);

        self::assertSame(1, $merged);
        self::assertNotNull($this->tagRepository->findOneBy(['name' => 'cat']));
        self::assertSame(['cat'], $this->reloadedPostTagNames($post->getId()));
    }
}

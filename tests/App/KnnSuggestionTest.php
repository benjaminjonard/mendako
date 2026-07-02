<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Embedding;
use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Repository\TagSuggestionRepository;
use App\Service\AutoTag\KnnSuggestionService;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class KnnSuggestionTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private KnnSuggestionService $service;
    private TagSuggestionRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = static::getContainer()->get(KnnSuggestionService::class);
        $this->repository = static::getContainer()->get(TagSuggestionRepository::class);
    }

    /** A 1024-dim one-hot vector literal. */
    private function oneHot(int $index): string
    {
        $values = array_fill(0, 1024, '0');
        $values[$index] = '1';

        return '['.implode(',', $values).']';
    }

    /** A 1024-dim vector literal from a leading head (rest zero-padded). */
    private function vec(array $head): string
    {
        $values = array_pad($head, 1024, 0);

        return '['.implode(',', array_map(static fn ($v): string => (string) $v, $values)).']';
    }

    private function addEmbedding(string $targetType, string $targetId, string $vector, string $modelId, int $ordinal = 0): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist((new Embedding())
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setOrdinal($ordinal)
            ->setEmbeddingModelId($modelId)
            ->setEmbeddingVector($vector));
        $em->flush();
    }

    private function taggedPost(string $tagName, string $vector, string $modelId): string
    {
        $post = PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => [TagFactory::createOne(['name' => $tagName])],
        ]);
        $this->addEmbedding('post', (string) $post->getId(), $vector, $modelId);

        return (string) $post->getId();
    }

    public function test_propagates_nearest_same_model_tags(): void
    {
        $this->taggedPost('cat', $this->oneHot(0), 'm1');          // nearest to the query
        $this->taggedPost('dog', $this->oneHot(1), 'm1');          // orthogonal → below similarity floor
        $this->taggedPost('other_model', $this->oneHot(0), 'm2');  // same vector, different model → excluded
        // An untagged post with the same vector + model is excluded by the EXISTS(tag) filter.
        $untagged = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne()]);
        $this->addEmbedding('post', (string) $untagged->getId(), $this->oneHot(0), 'm1');

        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, [$this->oneHot(0)], 'm1');

        $suggestions = $this->repository->findForTarget('post', $targetId);
        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $suggestions);

        $this->assertContains('cat', $names);
        $this->assertNotContains('dog', $names);          // below the 0.5 similarity floor
        $this->assertNotContains('other_model', $names);  // different encoder model
        foreach ($suggestions as $suggestion) {
            $this->assertSame(TagSuggestion::SOURCE_KNN, $suggestion->getSource());
        }
    }

    public function test_cold_start_produces_no_suggestions(): void
    {
        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, [$this->oneHot(0)], 'm1');

        $this->assertSame([], $this->repository->findForTarget('post', $targetId));
    }

    public function test_aggregates_neighbours_keeping_max_similarity(): void
    {
        // Two neighbours share the 'cat' tag at different similarities to the query.
        $cat = TagFactory::createOne(['name' => 'cat', 'category' => TagCategory::GENERAL]);
        foreach ([[1, 1], [1, 0]] as $head) { // [1,1] == query (sim 1.0); [1,0] ~0.707
            $post = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne(), 'tags' => [$cat]]);
            $this->addEmbedding('post', (string) $post->getId(), $this->vec($head), 'm1');
        }

        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, [$this->vec([1, 1])], 'm1');

        $suggestions = $this->repository->findForTarget('post', $targetId);
        $this->assertCount(1, $suggestions); // deduped by name
        $this->assertSame('cat', $suggestions[0]->getTagName());
        $this->assertSame(TagCategory::GENERAL, $suggestions[0]->getCategory());
        $this->assertEqualsWithDelta(1.0, $suggestions[0]->getScore(), 1e-4); // max neighbour similarity
    }

    public function test_matches_neighbour_on_any_query_frame(): void
    {
        // A neighbour tagged 'cat' resembles only the SECOND of the query's two frames.
        $this->taggedPost('cat', $this->vec([1, 0]), 'm1');

        $targetId = Uuid::v7()->toRfc4122();
        // First frame is far (orthogonal), second frame matches — the tag must still propagate.
        $this->service->propagate('post', $targetId, [$this->oneHot(9), $this->vec([1, 0])], 'm1');

        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $this->repository->findForTarget('post', $targetId));
        $this->assertContains('cat', $names);
    }

    public function test_excludes_meta_tags_from_propagation(): void
    {
        $post = PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => [
                TagFactory::createOne(['name' => 'cat', 'category' => TagCategory::GENERAL]),
                TagFactory::createOne(['name' => 'meta_only', 'category' => TagCategory::META]),
            ],
        ]);
        $this->addEmbedding('post', (string) $post->getId(), $this->oneHot(0), 'm1');

        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, [$this->oneHot(0)], 'm1');

        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $this->repository->findForTarget('post', $targetId));
        $this->assertContains('cat', $names);
        $this->assertNotContains('meta_only', $names); // meta tags don't propagate by visual similarity
    }

    public function test_bulk_upload_target_gets_post_neighbour_tags(): void
    {
        $this->taggedPost('cat', $this->oneHot(0), 'm1');

        $bulkUploadId = Uuid::v7()->toRfc4122();
        $this->service->propagate('bulk', $bulkUploadId, [$this->oneHot(0)], 'm1');

        $suggestions = $this->repository->findForTarget('bulk', $bulkUploadId);
        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $suggestions);
        $this->assertContains('cat', $names);
        $this->assertSame(TagSuggestion::SOURCE_KNN, $suggestions[0]->getSource());
    }

    public function test_post_target_excludes_itself(): void
    {
        $self = PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => [TagFactory::createOne(['name' => 'self_tag'])],
        ]);
        $this->addEmbedding('post', (string) $self->getId(), $this->oneHot(3), 'm1');

        // Propagate for the very same post → it must not echo its own tags.
        $this->service->propagate('post', (string) $self->getId(), [$this->oneHot(3)], 'm1');

        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $this->repository->findForTarget('post', (string) $self->getId()));
        $this->assertNotContains('self_tag', $names);
    }
}

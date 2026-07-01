<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Enum\TagCategory;
use App\Repository\TagSuggestionRepository;
use App\Service\AutoTag\KnnSuggestionService;
use App\Tests\Factory\BoardFactory;
use App\Tests\Factory\PostFactory;
use App\Tests\Factory\TagFactory;
use App\Tests\Factory\UserFactory;
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

    /** A 1152-dim one-hot vector literal. */
    private function oneHot(int $index): string
    {
        $values = array_fill(0, 1152, '0');
        $values[$index] = '1';

        return '['.implode(',', $values).']';
    }

    /** A 1152-dim vector literal from a leading head (rest zero-padded). */
    private function vec(array $head): string
    {
        $values = array_pad($head, 1152, 0);

        return '['.implode(',', array_map(static fn ($v): string => (string) $v, $values)).']';
    }

    private function taggedPost(string $tagName, string $vector, string $modelId): void
    {
        $post = PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => [TagFactory::createOne(['name' => $tagName])],
        ]);
        $post->setClipVector($vector)->setClipModelId($modelId);
        \Zenstruck\Foundry\Persistence\save($post);
    }

    public function test_propagates_nearest_same_model_tags(): void
    {
        $this->taggedPost('cat', $this->oneHot(0), 'm1');          // nearest to the query
        $this->taggedPost('dog', $this->oneHot(1), 'm1');          // orthogonal → below similarity floor
        $this->taggedPost('other_model', $this->oneHot(0), 'm2');  // same vector, different model → excluded
        // An untagged post with the same vector + model is excluded by the EXISTS(tag) filter.
        $untagged = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne()]);
        $untagged->setClipVector($this->oneHot(0))->setClipModelId('m1');
        \Zenstruck\Foundry\Persistence\save($untagged);

        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, $this->oneHot(0), 'm1');

        $suggestions = $this->repository->findForTarget('post', $targetId);
        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $suggestions);

        $this->assertContains('cat', $names);
        $this->assertNotContains('dog', $names);          // below the 0.5 similarity floor
        $this->assertNotContains('other_model', $names);  // different CLIP model
        foreach ($suggestions as $suggestion) {
            $this->assertSame(TagSuggestion::SOURCE_KNN, $suggestion->getSource());
        }
    }

    public function test_cold_start_produces_no_suggestions(): void
    {
        // No items in the collection at all.
        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, $this->oneHot(0), 'm1');

        $this->assertSame([], $this->repository->findForTarget('post', $targetId));
    }

    public function test_aggregates_neighbours_keeping_max_similarity(): void
    {
        // Two neighbours share the 'cat' tag at different similarities to the query.
        $cat = TagFactory::createOne(['name' => 'cat', 'category' => TagCategory::GENERAL]);
        foreach ([[1, 1], [1, 0]] as $head) { // [1,1] == query (sim 1.0); [1,0] ~0.707
            $post = PostFactory::createOne(['board' => BoardFactory::createOne(), 'uploadedBy' => UserFactory::createOne(), 'tags' => [$cat]]);
            $post->setClipVector($this->vec($head))->setClipModelId('m1');
            \Zenstruck\Foundry\Persistence\save($post);
        }

        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, $this->vec([1, 1]), 'm1');

        $suggestions = $this->repository->findForTarget('post', $targetId);
        $this->assertCount(1, $suggestions); // deduped by name
        $this->assertSame('cat', $suggestions[0]->getTagName());
        $this->assertSame(TagCategory::GENERAL, $suggestions[0]->getCategory());
        $this->assertEqualsWithDelta(1.0, $suggestions[0]->getScore(), 1e-4); // max neighbour similarity
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
        $post->setClipVector($this->oneHot(0))->setClipModelId('m1');
        \Zenstruck\Foundry\Persistence\save($post);

        $targetId = Uuid::v7()->toRfc4122();
        $this->service->propagate('post', $targetId, $this->oneHot(0), 'm1');

        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $this->repository->findForTarget('post', $targetId));
        $this->assertContains('cat', $names);
        $this->assertNotContains('meta_only', $names); // meta tags don't propagate by visual similarity
    }

    public function test_staged_target_gets_post_neighbour_tags(): void
    {
        $post = PostFactory::createOne([
            'board' => BoardFactory::createOne(),
            'uploadedBy' => UserFactory::createOne(),
            'tags' => [TagFactory::createOne(['name' => 'cat'])],
        ]);
        $post->setClipVector($this->oneHot(0))->setClipModelId('m1');
        \Zenstruck\Foundry\Persistence\save($post);

        $stagedId = Uuid::v7()->toRfc4122();
        $this->service->propagate('staged', $stagedId, $this->oneHot(0), 'm1');

        $suggestions = $this->repository->findForTarget('staged', $stagedId);
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
        $self->setClipVector($this->oneHot(3))->setClipModelId('m1');
        \Zenstruck\Foundry\Persistence\save($self);

        // Propagate for the very same post → it must not echo its own tags.
        $this->service->propagate('post', $self->getId(), $this->oneHot(3), 'm1');

        $names = array_map(static fn (TagSuggestion $s) => $s->getTagName(), $this->repository->findForTarget('post', $self->getId()));
        $this->assertNotContains('self_tag', $names);
    }
}

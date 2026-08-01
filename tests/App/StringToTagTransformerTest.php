<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\Tag;
use App\Entity\TagSuggestion;
use App\Form\DataTransformer\StringToTagTransformer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class StringToTagTransformerTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private StringToTagTransformer $transformer;
    private EntityManagerInterface $entityManager;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->transformer = static::getContainer()->get(StringToTagTransformer::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function test_new_tag_from_wd_known_name_is_wd_others_stay_custom(): void
    {
        // A prior wd suggestion proves the model produces 'wd_name'.
        $this->entityManager->persist((new TagSuggestion())
            ->setTargetType('post')
            ->setTargetId(Uuid::v7()->toRfc4122())
            ->setTagName('wd_name')
            ->setScore(0.5)
            ->setSource(TagSuggestion::SOURCE_WD));
        $this->entityManager->flush();

        $tags = $this->transformer->reverseTransform('wd_name hand_typed');
        $byName = [];
        foreach ($tags as $tag) {
            $byName[$tag->getName()] = $tag;
        }

        $this->assertSame(Tag::SOURCE_WD, $byName['wd_name']->getSource());
        $this->assertSame(Tag::SOURCE_CUSTOM, $byName['hand_typed']->getSource());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\App;

use App\Entity\TagSuggestion;
use App\Service\AutoTag\AutoTagConfigProvider;
use App\Service\AutoTag\SuggestionSplitter;
use PHPUnit\Framework\TestCase;

class SuggestionSplitterTest extends TestCase
{
    private function splitter(float $wd = 0.85, float $other = 0.85): SuggestionSplitter
    {
        $provider = $this->createStub(AutoTagConfigProvider::class);
        $provider->method('getAutoValidateThreshold')->willReturnMap([['wd', $wd], ['other', $other]]);

        return new SuggestionSplitter($provider);
    }

    private function suggestion(string $name, float $score, string $source, string $status = TagSuggestion::STATUS_PENDING): TagSuggestion
    {
        return (new TagSuggestion())
            ->setTargetType('post')
            ->setTargetId('id')
            ->setTagName($name)
            ->setScore($score)
            ->setSource($source)
            ->setStatus($status);
    }

    public function test_each_model_is_judged_against_its_own_threshold(): void
    {
        $splitter = $this->splitter(wd: 0.30, other: 0.90);

        [$confident, $chips] = $splitter->split([
            $this->suggestion('1girl', 0.50, TagSuggestion::SOURCE_WD),
            $this->suggestion('beach', 0.50, 'other'),
        ]);

        // Same score, opposite verdicts: that is the whole point of splitting the thresholds.
        $this->assertSame(['1girl'], array_column($confident, 'name'));
        $this->assertSame(['beach'], array_column($chips, 'name'));
    }

    public function test_a_name_proposed_by_both_models_appears_once(): void
    {
        [$confident, $chips] = $this->splitter()->split([
            $this->suggestion('cat', 0.95, TagSuggestion::SOURCE_WD),
            $this->suggestion('cat', 0.10, 'other'),
        ]);

        $this->assertSame(['cat'], array_column($confident, 'name'));
        $this->assertSame([], $chips);
    }

    public function test_already_decided_suggestions_are_ignored(): void
    {
        [$confident, $chips] = $this->splitter()->split([
            $this->suggestion('accepted', 0.99, TagSuggestion::SOURCE_WD, TagSuggestion::STATUS_ACCEPTED),
            $this->suggestion('dismissed', 0.10, TagSuggestion::SOURCE_WD, TagSuggestion::STATUS_DISMISSED),
        ]);

        $this->assertSame([], $confident);
        $this->assertSame([], $chips);
    }

    public function test_entries_carry_what_the_ui_renders(): void
    {
        [, $chips] = $this->splitter()->split([$this->suggestion('beach', 0.42, 'other')]);

        $this->assertSame(
            ['name' => 'beach', 'category' => 'general', 'score' => 0.42, 'source' => 'other'],
            $chips[0],
        );
    }
}

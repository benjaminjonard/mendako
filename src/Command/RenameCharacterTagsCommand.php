<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Tag;
use App\Enum\TagCategory;
use App\Repository\TagSuggestionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tag:rename-characters',
    description: 'Rename character tags to character_(copyright) using each tag\'s majority single-copyright posts',
)]
class RenameCharacterTagsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagSuggestionRepository $tagSuggestionRepository,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be renamed without persisting any change');
        $this->setHelp(<<<'HELP'
            Renames <comment>character</comment> tags to the <info>character_(copyright)</info> form.

            A character tag's copyright is derived only from the posts that carry
            <comment>exactly one</comment> copyright tag. The copyright appearing on the most such
            posts wins; a strict tie is skipped. Tags already in the <info>_(...)</info> form and
            renames colliding with an existing tag name are skipped.

            Use <info>--dry-run</info> to preview the renames without writing anything.
            HELP);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $repository = $this->entityManager->getRepository(Tag::class);
        $characterTags = $repository->findBy(['category' => TagCategory::CHARACTER]);

        $renamed = [];
        $skippedTie = 0;
        $skippedCollision = 0;
        $skippedAlreadyQualified = 0;
        $skippedNoCopyright = 0;

        foreach ($characterTags as $characterTag) {
            $currentName = (string) $characterTag->getName();

            // Idempotency: leave tags that already look like `name_(copyright)` alone.
            if (preg_match('/_\(.+\)$/', $currentName) === 1) {
                ++$skippedAlreadyQualified;
                continue;
            }

            // Tally copyright names across posts carrying exactly one copyright tag.
            $copyrightCounts = [];
            foreach ($characterTag->getPosts() as $post) {
                $copyrightNames = [];
                foreach ($post->getTags() as $tag) {
                    if ($tag->getCategory() === TagCategory::COPYRIGHT) {
                        $copyrightNames[] = (string) $tag->getName();
                    }
                }

                if (count($copyrightNames) === 1) {
                    $name = $copyrightNames[0];
                    $copyrightCounts[$name] = ($copyrightCounts[$name] ?? 0) + 1;
                }
            }

            if ($copyrightCounts === []) {
                ++$skippedNoCopyright;
                continue;
            }

            // Majority copyright; a strict tie for the top spot is ambiguous -> skip.
            arsort($copyrightCounts);
            $topCount = reset($copyrightCounts);
            $winners = array_keys($copyrightCounts, $topCount, true);
            if (count($winners) > 1) {
                ++$skippedTie;
                $io->warning(sprintf('Tie for "%s" between: %s — skipped.', $currentName, implode(', ', $winners)));
                continue;
            }

            $targetName = $currentName.'_('.$winners[0].')';

            // Collision: another tag already owns the target name -> skip.
            $existing = $repository->findOneBy(['name' => $targetName]);
            if ($existing !== null && $existing->getId() !== $characterTag->getId()) {
                ++$skippedCollision;
                $io->warning(sprintf('Target "%s" already exists — "%s" skipped.', $targetName, $currentName));
                continue;
            }

            if (!$dryRun) {
                $characterTag->setName($targetName);
            }

            $renamed[] = [$currentName, $targetName];
        }

        if (!$dryRun && $renamed !== []) {
            $this->entityManager->flush();
        }

        // Keep auto-tagging suggestions (men_tag_suggestion) in sync: their tag_name is a free
        // string, so a rename would otherwise leave stale suggestions recreating the old tag.
        $suggestionsSynced = 0;
        foreach ($renamed as [$from, $to]) {
            $suggestionsSynced += $dryRun
                ? $this->tagSuggestionRepository->countByTagName($from)
                : $this->tagSuggestionRepository->renameTagName($from, $to);
        }

        if ($renamed !== []) {
            $io->table(['From', 'To'], $renamed);
        }

        $io->success(sprintf(
            '%s %d character tag(s); %s %d suggestion(s). Skipped: %d already qualified, %d no single copyright, %d tie, %d collision.',
            $dryRun ? 'Would rename' : 'Renamed',
            count($renamed),
            $dryRun ? 'would sync' : 'synced',
            $suggestionsSynced,
            $skippedAlreadyQualified,
            $skippedNoCopyright,
            $skippedTie,
            $skippedCollision,
        ));

        return Command::SUCCESS;
    }
}

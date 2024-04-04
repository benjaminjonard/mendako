<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Post;
use App\Entity\PostSignatureWord;
use App\Service\SimilarityChecker;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'app:regenerate-signature-words',
    description: 'Regenerate signature words for similarity checking'
)]
class RegenerateSignatureWordsCommand extends Command
{
    public function __construct(
        private readonly SimilarityChecker $similarityChecker,
        private readonly ManagerRegistry $managerRegistry,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->managerRegistry->getConnection();

        $output->writeln('Clearing existing signature words...');
        $sql = "TRUNCATE men_post_signature_word;";
        $connection->prepare($sql)->execute();

        $output->writeln('Getting posts...');
        $results = $this->managerRegistry->getRepository(Post::class)->createQueryBuilder('p')
            ->select('p.id, p.path')
            ->getQuery()
            ->getArrayResult()
        ;

        $output->writeln('Starting to regenerate signature words...');
        $progressBar = new ProgressBar($output, \count($results));
        foreach ($results as $result) {
            $progressBar->advance();

            $path = $this->publicPath . '/' . $result['path'];
            if (!file_exists($path)) {
                continue;
            }

            $post = new Post();
            $post->setFile(new File($path));
            $this->similarityChecker->generateSignature($post);

            $postId = $result['id'];
            $signature = $post->getSignature();
            if ($signature === null) {
                continue;
            }

            $sql = "UPDATE men_post SET signature = '{$signature}' WHERE id = '{$postId}';";
            $connection->prepare($sql)->execute();

            foreach ($post->getSignatureWords() as $word) {
                $id = Uuid::v7()->toRfc4122();
                $word = $word->getWord();
                $sql = "INSERT INTO men_post_signature_word (id, post_id, word) VALUES ('{$id}', '{$postId}', '{$word}')";
                $connection->prepare($sql)->execute();
            }
        }

        $output->writeln('Done!');

        return Command::SUCCESS;
    }
}

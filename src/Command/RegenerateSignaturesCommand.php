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
    name: 'app:regenerate-signatures',
    description: 'Regenerate signatures for similarity checking'
)]
class RegenerateSignaturesCommand extends Command
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

        $output->writeln('Getting posts...');
        $results = $this->managerRegistry->getRepository(Post::class)->createQueryBuilder('p')
            ->select('p.id, p.path')
            ->getQuery()
            ->getArrayResult()
        ;

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

            $sql = "UPDATE men_post SET hash0 = '{$post->getHash0()}', hash1 = '{$post->getHash1()}', hash2 = '{$post->getHash2()}' , hash3 = '{$post->getHash3()}' WHERE id = '{$result['id']}';";
            $connection->prepare($sql)->execute();
        }

        $output->writeln('Done!');

        return Command::SUCCESS;
    }
}

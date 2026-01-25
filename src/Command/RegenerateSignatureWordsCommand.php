<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Post;
use App\Service\PostVectorService;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\File;

#[AsCommand(
    name: 'app:regenerate-vectors',
    description: 'Regenerate signature words for similarity checking'
)]
class RegenerateSignatureWordsCommand extends Command
{
    public function __construct(
        private readonly PostVectorService $postVectorService,
        private readonly ManagerRegistry $managerRegistry,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $publicPath
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->managerRegistry->getConnection();

        $output->writeln('Getting posts...');
        $results = $this->managerRegistry->getRepository(Post::class)->createQueryBuilder('p')
            ->select('p.id, p.path')
            ->getQuery()
            ->getArrayResult()
        ;

        $output->writeln('Starting to regenerate vectors...');
        $progressBar = new ProgressBar($output, \count($results));
        foreach ($results as $result) {
            $progressBar->advance();

            $path = $this->publicPath . '/' . $result['path'];
            if (!file_exists($path)) {
                continue;
            }

            $post = new Post();
            $post->setFile(new File($path));
            $vector = $this->postVectorService->generateVector($post->getFile());

            $postId = $result['id'];

            $sql = "UPDATE men_post SET vector = '{$vector}' WHERE id = '{$postId}';";
            $connection->prepare($sql)->executeStatement();
        }

        $output->writeln('Done!');

        return Command::SUCCESS;
    }
}

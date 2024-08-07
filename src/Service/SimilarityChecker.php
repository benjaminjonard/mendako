<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Post;
use App\Entity\PostSignatureWord;
use App\Repository\PostRepository;
use Doctrine\DBAL\Connection;
use SapientPro\ImageComparator\ImageComparator;
use Doctrine\Persistence\ManagerRegistry;
use SapientPro\ImageComparator\Strategy\DifferenceHashStrategy;
use Symfony\Component\HttpFoundation\File\File;

class SimilarityChecker
{
    private readonly Connection $connection;

    public function __construct(
        ManagerRegistry $managerRegistry,
        private readonly PostRepository $postRepository,
        private readonly ThumbnailGenerator $thumbnailGenerator,
    ) {
        $this->connection = $managerRegistry->getConnection();
    }

    public function hasSimilarities(Post $post): array
    {
        if ($post->getHash0() === null) {
            return [];
        }

        $sql = ("
            SELECT id, BIT_COUNT(cast (hash0 as bit varying) # cast ('{$post->getHash0()}' as bit varying)) + BIT_COUNT(cast (hash1 as bit varying) # cast ('{$post->getHash1()}' as bit varying)) + BIT_COUNT(cast (hash2 as bit varying) # cast ('{$post->getHash2()}' as bit varying)) + BIT_COUNT(cast (hash3 as bit varying) # cast ('{$post->getHash3()}' as bit varying)) AS strength 
            FROM men_post 
            WHERE hash0 IS NOT NULL
            AND BIT_COUNT(cast (hash0 as bit varying) # cast ('{$post->getHash0()}' as bit varying)) + BIT_COUNT(cast (hash1 as bit varying) # cast ('{$post->getHash1()}' as bit varying)) + BIT_COUNT(cast (hash2 as bit varying) # cast ('{$post->getHash2()}' as bit varying)) + BIT_COUNT(cast (hash3 as bit varying) # cast ('{$post->getHash3()}' as bit varying)) <=3
        ");

        $stmt = $this->connection->prepare($sql);
        $results = $stmt->executeQuery()->fetchAllAssociative();

        $similarPosts = [];
        foreach ($results as $result) {
            $similarPost = $this->postRepository->find($result['id']);
            $similarPosts[] = [
                'post' => $similarPost,
                'strength' => 100 - ($result['strength'] * 10)
            ];
        }

        return $similarPosts;
    }

    public function generateSignature(Post $post): void
    {
        if (!$post->getFile() instanceof File) {
            return;
        }

        $imageComparator = new ImageComparator();
        $imageComparator->setHashStrategy(new DifferenceHashStrategy());

        $path = $post->getFile()->getRealPath();
        $thumbnailPath = '/tmp/' . $post->getFile()->getFilename() .  '_600.jpeg';
        $this->thumbnailGenerator->generate($path, $thumbnailPath, 600, 'jpeg');

        $signature = $imageComparator->convertHashToBinaryString($imageComparator->hashImage($thumbnailPath));

        $post->setHash0(substr($signature, 0, 16));
        $post->setHash1(substr($signature, 16, 16));
        $post->setHash2(substr($signature, 32, 16));
        $post->setHash3(substr($signature, 48, 16));
    }
}

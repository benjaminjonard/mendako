<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Post;
use App\Entity\PostSignatureWord;
use App\Repository\PostRepository;
use Doctrine\DBAL\Connection;
use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use Doctrine\Persistence\ManagerRegistry;

class SimilarityChecker
{
    private Connection $connection;

    public function __construct(
        ManagerRegistry $managerRegistry,
        private readonly PostRepository $postRepository,
        private readonly ThumbnailGenerator $thumbnailGenerator
    ) {
        $this->connection = $managerRegistry->getConnection();
    }

    public function hasSimilarities(Post $post): array
    {
        if ($post->getSignature() === null) {
            return [];
        }

        $words = [];
        foreach ($post->getSignatureWords() as $word) {
            $words[] = "'" . $word->getWord() . "'";
        }
        $words = implode(',', $words);

        $sql = ("
            SELECT word.post_id AS id, COUNT(word.word) AS strength
            FROM men_post_signature_word word 
            WHERE word.word IN ($words)
            GROUP BY word.post_id
            HAVING COUNT(word.word) > 20
            ORDER BY strength DESC
            LIMIT 5
        ");

        $stmt = $this->connection->prepare($sql);
        $results = $stmt->executeQuery()->fetchAllAssociative();

        $similarPosts = [];
        foreach ($results as $result) {
            $similarPost = $this->postRepository->find($result['id']);
            $similarPosts[] = [
                'post' => $similarPost,
                'strength' => $result['strength']
            ];
        }

        return $similarPosts;
    }

    public function generateSignature(Post $post): void
    {
        if ($post->getFile() === null) {
            return;
        }

        $path = $post->getFile()->getRealPath();
        $thumbnailPath = '/tmp/' . $post->getFile()->getFilename() .  '_600.jpeg';
        $this->thumbnailGenerator->generate($path, $thumbnailPath, 600, 'jpeg');

        $signature = puzzle_fill_cvec_from_file($thumbnailPath);
        if ($signature === false) {
            return;
        }

        $post->getSignatureWords()->clear();
        $post->setSignature(hash('xxh3', $signature));

        $wordLength = 10;
        $wordCount = 100;
        for ($i = 0; $i < min($wordCount, strlen($signature) - $wordLength + 1); $i++) {
            $word = substr($signature, $i, $wordLength);
            $signatureWord = new PostSignatureWord($post, hash('xxh3',$i.'__'.$word));
            $post->addSignatureWord($signatureWord);
        }
    }
}

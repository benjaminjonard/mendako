<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

class BoardTagPurger
{
    private const string TARGET_POST = 'post';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<string> $keep tag names to leave alone, matched exactly
     * @return array{posts: int, links: int, tags: int, suggestions: int}
     */
    public function preview(string $slug, array $keep = [], array $keepCategories = []): array
    {
        $spare = [$keep, $keepCategories];

        return [
            'posts' => $this->fetch('SELECT COUNT(*) FROM men_post p WHERE '.$this->onBoard('p.id'), $slug, ...$spare),
            'links' => $this->fetch('SELECT COUNT(*) FROM men_post_tag pt WHERE '.$this->doomedLink(...$spare), $slug, ...$spare),
            'tags' => $this->fetch($this->tagsLeftUnusedSql(...$spare), $slug, ...$spare),
            'suggestions' => $this->fetch('SELECT COUNT(*) FROM men_tag_suggestion s WHERE '.$this->doomedSuggestion(...$spare), $slug, ...$spare),
        ];
    }

    /**
     * @param list<string> $keep
     * @return array{posts: int, links: int, tags: int, suggestions: int}
     */
    public function purge(string $slug, array $keep = [], array $keepCategories = []): array
    {
        $posts = $this->preview($slug, $keep, $keepCategories)['posts'];

        return $this->connection->transactional(function (Connection $connection) use ($slug, $keep, $keepCategories, $posts): array {
            $links = $this->execute($connection, 'DELETE FROM men_post_tag pt WHERE '.$this->doomedLink($keep, $keepCategories), $slug, $keep, $keepCategories);
            $suggestions = $this->execute($connection, 'DELETE FROM men_tag_suggestion s WHERE '.$this->doomedSuggestion($keep, $keepCategories), $slug, $keep, $keepCategories);
            $tags = (int) $connection->executeStatement(
                'DELETE FROM men_tag t WHERE NOT EXISTS (SELECT 1 FROM men_post_tag pt WHERE pt.tag_id = t.id)',
            );

            return ['posts' => $posts, 'links' => $links, 'tags' => $tags, 'suggestions' => $suggestions];
        });
    }

    /**
     * @param list<string> $keep
     */
    private function fetch(string $sql, string $slug, array $keep, array $keepCategories = []): int
    {
        return (int) $this->connection->fetchOne(
            $sql,
            $this->values($slug, $keep, $keepCategories),
            $this->types($keep, $keepCategories),
        );
    }

    /**
     * @param list<string> $keep
     */
    private function execute(Connection $connection, string $sql, string $slug, array $keep, array $keepCategories = []): int
    {
        return (int) $connection->executeStatement(
            $sql,
            $this->values($slug, $keep, $keepCategories),
            $this->types($keep, $keepCategories),
        );
    }

    /**
     * @param list<string> $keep
     * @return array<string, mixed>
     */
    private function values(string $slug, array $keep, array $keepCategories = []): array
    {
        $values = ['slug' => $slug];
        if ($keep !== []) {
            $values['keep'] = $keep;
        }
        if ($keepCategories !== []) {
            $values['keepCategories'] = $keepCategories;
        }

        return $values;
    }

    /**
     * @param list<string> $keep
     * @return array<string, mixed>
     */
    private function types(array $keep, array $keepCategories = []): array
    {
        $types = [];
        if ($keep !== []) {
            $types['keep'] = ArrayParameterType::STRING;
        }
        if ($keepCategories !== []) {
            $types['keepCategories'] = ArrayParameterType::STRING;
        }

        return $types;
    }

    private function onBoard(string $postColumn): string
    {
        return "EXISTS (SELECT 1 FROM men_board b WHERE b.slug = :slug AND b.id = (
                    SELECT p2.board_id FROM men_post p2 WHERE p2.id = {$postColumn}
                ))";
    }

    /**
     * @param list<string> $keep
     */
    private function doomedLink(array $keep, array $keepCategories = []): string
    {
        $spared = [];
        if ($keep !== []) {
            $spared[] = 't.name IN (:keep)';
        }
        if ($keepCategories !== []) {
            $spared[] = 't.category IN (:keepCategories)';
        }
        $kept = $spared === []
            ? ''
            : ' AND NOT EXISTS (SELECT 1 FROM men_tag t WHERE t.id = pt.tag_id AND ('.implode(' OR ', $spared).'))';

        return $this->onBoard('pt.post_id').$kept;
    }

    /**
     * @param list<string> $keep
     */
    private function doomedSuggestion(array $keep, array $keepCategories = []): string
    {
        $spared = [];
        if ($keep !== []) {
            $spared[] = 's.tag_name IN (:keep)';
        }
        if ($keepCategories !== []) {
            $spared[] = 's.category IN (:keepCategories)';
        }
        $kept = $spared === [] ? '' : ' AND NOT ('.implode(' OR ', $spared).')';

        return "s.target_type = '".self::TARGET_POST."' AND ".$this->onBoard('s.target_id').$kept;
    }

    /**
     * @param list<string> $keep
     */
    private function tagsLeftUnusedSql(array $keep, array $keepCategories = []): string
    {
        $doomed = $this->doomedLink($keep, $keepCategories);

        return "SELECT COUNT(*) FROM men_tag t
                WHERE EXISTS (SELECT 1 FROM men_post_tag pt WHERE pt.tag_id = t.id AND {$doomed})
                  AND NOT EXISTS (SELECT 1 FROM men_post_tag pt WHERE pt.tag_id = t.id AND NOT ({$doomed}))";
    }
}

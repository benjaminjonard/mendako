<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\TagSuggestion;
use Doctrine\DBAL\Connection;

class SuggestedTagPurger
{
    private const string TARGET_POST = 'post';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{links: int, tags: int, suggestions: int}
     */
    public function preview(): array
    {
        return [
            'links' => (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM men_post_tag pt WHERE '.$this->appliedLinkPredicate(),
                $this->linkParameters(),
            ),
            'tags' => (int) $this->connection->fetchOne(
                $this->countTagsLeftUnusedSql(),
                $this->linkParameters(),
            ),
            'suggestions' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM men_tag_suggestion'),
        ];
    }

    /**
     * @return array{links: int, tags: int, suggestions: int}
     */
    public function purge(): array
    {
        return $this->connection->transactional(function (Connection $connection): array {
            $links = (int) $connection->executeStatement($this->deleteAppliedLinksSql(), $this->linkParameters());

            $tags = (int) $connection->executeStatement(
                $this->deleteTagsLeftUnusedSql(),
                ['accepted' => TagSuggestion::STATUS_ACCEPTED],
            );

            $suggestions = (int) $connection->executeStatement('DELETE FROM men_tag_suggestion');

            return ['links' => $links, 'tags' => $tags, 'suggestions' => $suggestions];
        });
    }

    /**
     * @return array<string, string>
     */
    private function linkParameters(): array
    {
        return [
            'accepted' => TagSuggestion::STATUS_ACCEPTED,
            'postTarget' => self::TARGET_POST,
        ];
    }

    private function appliedLinkPredicate(): string
    {
        return <<<'SQL'
            EXISTS (
                SELECT 1
                FROM men_tag_suggestion s
                INNER JOIN men_tag t ON t.name = s.tag_name
                WHERE s.status = :accepted
                  AND s.target_type = :postTarget
                  AND s.target_id = pt.post_id
                  AND t.id = pt.tag_id
            )
            SQL;
    }

    private function deleteAppliedLinksSql(): string
    {
        return <<<'SQL'
            DELETE FROM men_post_tag pt
            USING men_tag_suggestion s, men_tag t
            WHERE s.status = :accepted
              AND s.target_type = :postTarget
              AND s.target_id = pt.post_id
              AND t.name = s.tag_name
              AND t.id = pt.tag_id
            SQL;
    }

    private function countTagsLeftUnusedSql(): string
    {
        return <<<'SQL'
            SELECT COUNT(*)
            FROM men_tag t
            WHERE EXISTS (
                    SELECT 1
                    FROM men_tag_suggestion s
                    WHERE s.status = :accepted
                      AND s.tag_name = t.name
                  )
              AND NOT EXISTS (
                    SELECT 1
                    FROM men_post_tag pt
                    WHERE pt.tag_id = t.id
                      AND NOT EXISTS (
                            SELECT 1
                            FROM men_tag_suggestion s2
                            WHERE s2.status = :accepted
                              AND s2.target_type = :postTarget
                              AND s2.target_id = pt.post_id
                              AND s2.tag_name = t.name
                        )
                  )
            SQL;
    }

    private function deleteTagsLeftUnusedSql(): string
    {
        return <<<'SQL'
            DELETE FROM men_tag t
            WHERE EXISTS (
                    SELECT 1
                    FROM men_tag_suggestion s
                    WHERE s.status = :accepted
                      AND s.tag_name = t.name
                  )
              AND NOT EXISTS (
                    SELECT 1
                    FROM men_post_tag pt
                    WHERE pt.tag_id = t.id
                  )
            SQL;
    }
}

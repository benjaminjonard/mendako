<?php

declare(strict_types=1);

namespace App\Service\AutoTag;

use App\Entity\TagSuggestion;
use Doctrine\DBAL\Connection;

/**
 * Undoes auto-tagging: detaches from their post every tag a suggestion put there, drops the tags
 * that are then left on no post at all, and purges the whole suggestion history.
 *
 * `Tag::source` is deliberately NOT the criterion: StringToTagTransformer stamps SOURCE_WD on a name
 * the user typed by hand as soon as a model is known to emit it, so purging on source would delete
 * the user's own work. An ACCEPTED suggestion is the only proof that a tag reached a post through
 * auto-tagging, so what gets removed is the join between `men_post_tag` and those suggestions.
 *
 * Set-based SQL on purpose: the back-catalogue can hold hundreds of thousands of links and none of
 * this work needs entities. Callers holding posts or tags in the UoW must clear it afterwards.
 */
class SuggestedTagPurger
{
    private const string TARGET_POST = 'post';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * What a purge would delete, computed without writing anything.
     *
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

            // Order matters: "left unused" is read off men_tag_suggestion, so the tags must go
            // before the history that identifies them.
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

    /**
     * True for a `men_post_tag` row (aliased `pt`) that an accepted suggestion put there.
     * `tag_name` carries no FK to men_tag, hence the join on the name.
     */
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

    /**
     * Tags auto-tagging applied somewhere and whose every remaining link is one the purge removes —
     * i.e. the tags the purge leaves on no post. A tag the user also put on a post by hand keeps
     * that link and therefore survives.
     */
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

    /**
     * Same set as countTagsLeftUnusedSql(), but run once the links are already gone: a tag that
     * auto-tagging applied and that no post carries any more.
     */
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

<?php
declare(strict_types=1);

final class AttentionInbox
{
    private const HELP = <<<'REGEX'
        ~\b(?:
            нужна\s+(?:(?:ваша|твоя)\s+)?помощь
            | нужен\s+совет\s+руководителя
            | (?:просьба|прошу)\s+помочь
            | помогите
            | не\s+(?:могу|можем|уда[её]тся)\s+продолжить
            | без\s+(?:доступа|ответа|решения|согласования)\s+не\s+продолжить
            | работа\s+заблокирована
        )\b~iux
        REGEX;
    private const RECOVERY = <<<'REGEX'
        ~\b(?:
            (?:препятствий|блокеров|проблем|блокировок)\s+(?:больше\s+)?нет
            | (?:блокер|блокировка|препятствие|проблема|вопрос)\s+(?:снят[ао]?|устран[её]н[ао]?|реш[её]н[ао]?)
            | доступ\s+(?:получен|выдан|есть)
            | помощь\s+(?:(?:больше|уже)\s+)?не\s+(?:нужна|требуется)
            | не\s+нужна\s+(?:(?:ваша|твоя)\s+)?помощь
            | не\s+нужен\s+совет\s+руководителя
            | (?:теперь|уже)\s+(?:можно|можем|могу)\s+продолж(?:ить|ать)
            | вс[её]\s+решено
            | разблокирован[ао]?
        )\b~iux
        REGEX;
    private const STAMP = 'Y-m-d\TH:i:s\Z';

    public function __construct(private PDO $db) {}

    public function forUser(int $userId, DateTimeImmutable $now): array
    {
        $current = $now->format(self::STAMP);
        $soon = $now->modify('+24 hours')->format(self::STAMP);
        $recent = $now->modify('-7 days')->format(self::STAMP);

        $query = $this->db->prepare(<<<'SQL'
            SELECT t.*, p.name AS project_name, c.id AS comment_id, c.body AS comment_body,
                   c.created_at AS comment_at, u.name AS comment_author
              FROM projects p
              JOIN project_members m ON m.project_id = p.id AND m.user_id = p.owner_id
              JOIN tasks t ON t.project_id = p.id
              LEFT JOIN comments c ON c.task_id = t.id AND c.created_at BETWEEN :recent AND :now
              LEFT JOIN users u ON u.id = c.user_id
             WHERE p.owner_id = :user AND p.archived = 0 AND t.status <> 'done'
             ORDER BY t.id, c.created_at DESC, c.id DESC
            SQL);
        $query->execute(['recent' => $recent, 'now' => $current, 'user' => $userId]);

        $items = [];
        $classified = [];
        while ($row = $query->fetch()) {
            $id = $row['id'];
            if (!isset($items[$id])) {
                $task = $row;
                unset($task['project_name'], $task['comment_id'], $task['comment_body'], $task['comment_at'], $task['comment_author']);
                $items[$id] = [
                    'task' => $task,
                    'project_id' => $task['project_id'],
                    'project_name' => $row['project_name'],
                    'signals' => [],
                    'evidence' => null,
                ];
            }
            if ($row['comment_id'] === null || isset($classified[$id])) {
                continue;
            }
            $excerpt = $this->commentEvidence($row['comment_body']);
            if ($excerpt === null) {
                continue;
            }
            $classified[$id] = true;
            if ($excerpt !== false) {
                $items[$id]['evidence'] = [
                    'id' => $row['comment_id'],
                    'author' => $row['comment_author'],
                    'body' => $excerpt,
                    'created_at' => $row['comment_at'],
                ];
            }
        }

        $attention = [];
        foreach ($items as $item) {
            $due = $item['task']['due_at'];
            $signals = array_filter([
                'blocked' => $item['task']['status'] === 'blocked',
                'overdue' => $due !== null && $due < $current,
                'needs_help' => $item['evidence'] !== null,
                'due_soon' => $due !== null && $due >= $current && $due <= $soon,
            ]);
            if ($signals !== []) {
                $item['signals'] = array_keys($signals);
                $attention[] = $item;
            }
        }
        usort($attention, fn(array $left, array $right): int => $this->sortKey($left) <=> $this->sortKey($right));
        return $attention;
    }

    private function sortKey(array $item): array
    {
        $signals = $item['signals'];
        $priority = in_array('blocked', $signals, true) && in_array('overdue', $signals, true)
            ? -1
            : array_search($signals[0], ['blocked', 'overdue', 'needs_help', 'due_soon'], true);
        $due = $item['task']['due_at'];
        return [$priority, $due === null, $due, $item['task']['id']];
    }

    // A recovery marker stops the backward scan. Neutral text leaves the last signal intact.
    // A positive marker returns the comment excerpt for the reviewer to interpret.
    private function commentEvidence(string $body): string|false|null
    {
        $clauses = preg_split('/[.!?;,]+(?:\h+|$)|\R+/u', $body, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);
        foreach (array_reverse($clauses ?: []) as [$clause, $offset]) {
            if (preg_match(self::RECOVERY, $clause)) {
                return false;
            }
            if (preg_match(self::HELP, $clause, $match, PREG_OFFSET_CAPTURE)) {
                return $this->excerpt($body, $offset + $match[0][1]);
            }
        }
        return null;
    }

    private function excerpt(string $body, int $matchOffset): string
    {
        $full = $this->inline($body);
        if (!preg_match('/\A.{221}/us', $full)) {
            return $full;
        }

        $before = $this->inline(substr($body, 0, $matchOffset));
        $after = $this->inline(substr($body, $matchOffset));
        preg_match('/.{0,55}\z/us', $before, $prefix);
        $start = $prefix[0];
        $context = ($start === $before ? '' : '…') . ($start === '' ? '' : $start . ' ') . $after;
        return preg_match('/\A(.{219})../us', $context, $truncated) ? rtrim($truncated[1]) . '…' : $context;
    }

    private function inline(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

<?php

declare(strict_types=1);

/**
 * Ticket-based support system. Emails go out through the system-wide SMTP
 * settings (Mailer) whenever a staff member replies to a ticket.
 */
final class SupportService
{
    public const STATUS_LABELS = ['open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed'];

    public static function create(array $user, string $subject, string $content): array
    {
        $subject = trim($subject);
        $content = trim($content);
        if ($subject === '') {
            return ['ok' => false, 'error' => 'A subject is required.'];
        }
        if ($content === '') {
            return ['ok' => false, 'error' => 'Please describe your issue.'];
        }
        Database::query(
            'INSERT INTO support_tickets (user_id, subject) VALUES (?, ?)',
            [$user['id'], mb_substr($subject, 0, 120)]
        );
        $id = (int) Database::lastId();
        Database::query(
            'INSERT INTO support_ticket_replies (ticket_id, author_id, is_staff, content) VALUES (?, ?, 0, ?)',
            [$id, (int) $user['id'], $content]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function mine(array $user): array
    {
        return Database::all(
            'SELECT t.*, u.username FROM support_tickets t JOIN users u ON u.id = t.user_id
             WHERE t.user_id = ? ORDER BY t.updated_at DESC LIMIT 100',
            [$user['id']]
        );
    }

    /** All tickets for staff, newest activity first. */
    public static function all(string $status = ''): array
    {
        $sql = 'SELECT t.*, u.username, a.username AS assignee_name,
                       (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id = t.id) AS replies
                FROM support_tickets t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.assigned_to';
        $params = [];
        if ($status !== '' && in_array($status, ['open', 'answered', 'closed'], true)) {
            $sql .= ' WHERE t.status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY t.updated_at DESC LIMIT 300';
        return Database::all($sql, $params);
    }

    public static function get(int $id): ?array
    {
        return Database::row(
            'SELECT t.*, u.username, a.username AS assignee_name FROM support_tickets t
             LEFT JOIN users u ON u.id = t.user_id
             LEFT JOIN users a ON a.id = t.assigned_to
             WHERE t.id = ?',
            [$id]
        );
    }

    public static function replies(int $ticketId): array
    {
        return Database::all(
            'SELECT r.*, u.username, u.role FROM support_ticket_replies r
             LEFT JOIN users u ON u.id = r.author_id
             WHERE r.ticket_id = ? ORDER BY r.id ASC',
            [$ticketId]
        );
    }

    public static function canView(array $ticket, array $user): bool
    {
        if ((int) $ticket['user_id'] === (int) $user['id']) {
            return true;
        }
        return ModerationService::isStaff($user);
    }

    public static function reply(int $ticketId, array $user, string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return ['ok' => false, 'error' => 'A reply is required.'];
        }
        $ticket = self::get($ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.'];
        }
        $isStaff = ModerationService::isStaff($user);
        if (!$isStaff && (int) $ticket['user_id'] !== (int) $user['id']) {
            return ['ok' => false, 'error' => 'You cannot reply to this ticket.'];
        }
        Database::query(
            'INSERT INTO support_ticket_replies (ticket_id, author_id, is_staff, content) VALUES (?, ?, ?, ?)',
            [$ticketId, (int) $user['id'], $isStaff ? 1 : 0, $content]
        );
        $status = $isStaff ? 'answered' : 'open';
        if ($isStaff && (string) $ticket['status'] === 'closed') {
            $status = 'answered';
        }
        Database::query(
            "UPDATE support_tickets SET updated_at = datetime('now'), status = ? WHERE id = ?",
            [$status, $ticketId]
        );
        // Email the owner whenever staff reply.
        if ($isStaff && (int) $ticket['user_id'] !== (int) $user['id']) {
            $owner = Database::row('SELECT * FROM users WHERE id = ?', [(int) $ticket['user_id']]);
            if ($owner) {
                Mailer::sendSupportReply(
                    (string) $owner['email'],
                    $ticket['subject'],
                    $content,
                    (string) $user['username'],
                    $ticketId
                );
            }
        }
        return ['ok' => true];
    }

    public static function setStatus(int $ticketId, string $status, array $user): array
    {
        $ticket = self::get($ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.'];
        }
        $isStaff = ModerationService::isStaff($user);
        $owner = (int) $ticket['user_id'] === (int) $user['id'];
        if ($status === 'closed' && !$isStaff && !$owner) {
            return ['ok' => false, 'error' => 'You cannot close this ticket.'];
        }
        if ($status === 'open' && !$isStaff) {
            return ['ok' => false, 'error' => 'Only staff can reopen a ticket.'];
        }
        Database::query(
            'UPDATE support_tickets SET status = ?, updated_at = datetime("now"), closed_at = CASE WHEN ? = "closed" THEN datetime("now") ELSE NULL END WHERE id = ?',
            [$status, $status, $ticketId]
        );
        return ['ok' => true];
    }
}

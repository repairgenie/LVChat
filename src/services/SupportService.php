<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

/**
 * Ticket-based support system. Tickets are opened by a registered user, or by
 * staff on behalf of either a registered user OR an external email address.
 * Staff may assign a ticket to any admin/staff member. Emails go out through
 * the system-wide SMTP settings (Mailer) whenever a staff member replies.
 */
final class SupportService
{
    public const STATUS_LABELS = ['open' => 'Open', 'answered' => 'Answered', 'closed' => 'Closed'];

    private static function sanitizeHtml(string $html): string
    {
        return LegalService::sanitize($html);
    }

    /** Create a ticket for a registered user (user-facing form). */
    public static function create(array $user, string $subject, string $content, array $attachments = []): array
    {
        return self::createTicket($user['id'], null, $user['id'], $subject, $content, null, $attachments);
    }

    /**
     * Create a ticket from the staff dashboard. Either $userId or $email must
     * resolve to a contact; if an email matches a registered user, it links to
     * that account. Returns ['ok' => bool, 'id' => int, 'error' => ?string].
     */
    public static function createStaff(?int $userId, string $email, int $openedBy, string $subject, string $content, ?int $assignedTo, array $attachments = []): array
    {
        $subject = trim($subject);
        $content = trim($content);
        if ($subject === '') {
            return ['ok' => false, 'error' => 'A subject is required.'];
        }
        if (trim(strip_tags($content)) === '') {
            return ['ok' => false, 'error' => 'Please describe the issue.'];
        }
        $userId = $userId ? (int) $userId : null;
        $email = trim($email);
        // Prefer linking by email when the address belongs to a registered user.
        if (!$userId && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $byEmail = Database::scalar('SELECT id FROM users WHERE email = ? COLLATE NOCASE', [$email]);
            if ($byEmail) {
                $userId = (int) $byEmail;
                $email = '';
            }
        }
        if (!$userId && $email === '') {
            return ['ok' => false, 'error' => 'Provide a registered user or an email address.'];
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That email address is not valid.'];
        }
        return self::createTicket($userId, $email !== '' ? mb_substr($email, 0, 254) : null, $openedBy, $subject, $content, $assignedTo, $attachments);
    }

    /** Shared insert: opens the ticket and adds the opening message as a reply. */
    private static function createTicket(?int $userId, ?string $email, int $authorId, string $subject, string $content, ?int $assignedTo, array $attachments = []): array
    {
        Database::query(
            'INSERT INTO support_tickets (user_id, email, subject, assigned_to, opened_by) VALUES (?, ?, ?, ?, ?)',
            [$userId, $email, mb_substr($subject, 0, 120), $assignedTo ? (int) $assignedTo : null, $authorId]
        );
        $id = (int) Database::lastId();
        $attJson = !empty($attachments) ? json_encode($attachments) : null;
        $safeContent = self::sanitizeHtml($content);
        Database::query(
            'INSERT INTO support_ticket_replies (ticket_id, author_id, is_staff, content, attachments) VALUES (?, ?, 1, ?, ?)',
            [$id, $authorId, $safeContent, $attJson]
        );
        log_audit('support_ticket_staff_open', 'ticket#' . $id, $subject);
        return ['ok' => true, 'id' => $id];
    }

    /** Tickets for a registered user (their own + any email ticket matching their address). */
    public static function mine(array $user): array
    {
        return Database::all(
            'SELECT t.*, u.username, a.username AS assignee_name FROM support_tickets t
             JOIN users u ON u.id = t.user_id
             LEFT JOIN users a ON a.id = t.assigned_to
             WHERE t.user_id = ? ORDER BY t.updated_at DESC LIMIT 100',
            [$user['id']]
        );
    }

    /** All tickets for staff. Filters: status and/or assignee ("mine"/"unassigned"). */
    public static function all(string $status = '', string $assignee = '', ?int $currentStaffId = null): array
    {
        $sql = 'SELECT t.*, u.username, a.username AS assignee_name,
                       (SELECT COUNT(*) FROM support_ticket_replies r WHERE r.ticket_id = t.id) AS replies
                FROM support_tickets t
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users a ON a.id = t.assigned_to';
        $params = [];
        $where = [];
        if ($status !== '' && in_array($status, ['open', 'answered', 'closed'], true)) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }
        if ($assignee === 'mine' && $currentStaffId !== null) {
            $where[] = 't.assigned_to = ?';
            $params[] = $currentStaffId;
        } elseif ($assignee === 'unassigned') {
            $where[] = 't.assigned_to IS NULL';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
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
        if ($ticket['user_id'] !== null && (int) $ticket['user_id'] === (int) $user['id']) {
            return true;
        }
        return ModerationService::isStaff($user);
    }

    /** The contact address to reach about a ticket: user's email, else ticket email. */
    public static function contactEmail(array $ticket): ?string
    {
        if ($ticket['email']) {
            return (string) $ticket['email'];
        }
        if ($ticket['user_id'] !== null) {
            return (string) (Database::scalar('SELECT email FROM users WHERE id = ?', [(int) $ticket['user_id']]) ?? '');
        }
        return null;
    }

    public static function reply(int $ticketId, array $user, string $content, array $attachments = []): array
    {
        $content = trim($content);
        if (trim(strip_tags($content)) === '' && empty($attachments)) {
            return ['ok' => false, 'error' => 'A reply or attachment is required.'];
        }
        $ticket = self::get($ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.'];
        }
        $isStaff = ModerationService::isStaff($user);
        $isOwner = $ticket['user_id'] !== null && (int) $ticket['user_id'] === (int) $user['id'];
        if (!$isStaff && !$isOwner) {
            return ['ok' => false, 'error' => 'You cannot reply to this ticket.'];
        }
        $attJson = !empty($attachments) ? json_encode($attachments) : null;
        $safeContent = self::sanitizeHtml($content);
        Database::query(
            'INSERT INTO support_ticket_replies (ticket_id, author_id, is_staff, content, attachments) VALUES (?, ?, ?, ?, ?)',
            [$ticketId, (int) $user['id'], $isStaff ? 1 : 0, $safeContent, $attJson]
        );
        $status = $isStaff ? 'answered' : 'open';
        if ($isStaff && (string) $ticket['status'] === 'closed') {
            $status = 'answered';
        }
        Database::query(
            "UPDATE support_tickets SET updated_at = datetime('now'), status = ? WHERE id = ?",
            [$status, $ticketId]
        );
        // Email the owner whenever staff reply (registered user or email-only).
        if ($isStaff && !$isOwner) {
            $to = self::contactEmail($ticket);
            if ($to) {
                Mailer::sendSupportReply(
                    $to,
                    $ticket['subject'],
                    $content,
                    (string) $user['username'],
                    $ticketId
                );
            }
        }
        return ['ok' => true];
    }

    /** Assign a ticket to an admin/staff member (or unassign). */
    public static function assign(int $ticketId, ?int $staffId): array
    {
        Database::query(
            'UPDATE support_tickets SET assigned_to = ? WHERE id = ?',
            [$staffId, $ticketId]
        );
        return ['ok' => true];
    }

    /** All admins and staff users, for the assignment dropdown. */
    public static function staff(): array
    {
        return Database::all(
            "SELECT id, username, role FROM users WHERE role IN ('admin', 'staff') ORDER BY role DESC, username COLLATE NOCASE"
        );
    }

    public static function setStatus(int $ticketId, string $status, array $user): array
    {
        $ticket = self::get($ticketId);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.'];
        }
        $isStaff = ModerationService::isStaff($user);
        $owner = $ticket['user_id'] !== null && (int) $ticket['user_id'] === (int) $user['id'];
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

<?php

declare(strict_types=1);

final class SupportController
{
    private static function requireAccount(): array
    {
        $u = Auth::require();
        if (Auth::isGuest($u)) {
            flash('Support tickets require a registered account. Register or log in to open a ticket.');
            redirect('/login');
        }
        return $u;
    }

    /** GET /support — the user's tickets + create form. */
    public static function index(): void
    {
        $user = self::requireAccount();
        $old = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
        render_view('support/index', [
            'user' => $user,
            'tickets' => SupportService::mine($user),
            'error' => flash(),
            'old' => $old,
        ]);
    }

    /** POST /support — create a ticket. */
    public static function create(): void
    {
        $user = self::requireAccount();
        Csrf::verify();
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $result = SupportService::create($user, $subject, $content);
        if (!$result['ok']) {
            $_SESSION['old'] = ['subject' => $subject, 'content' => $content];
            flash($result['error']);
            redirect('/support');
        }
        log_audit('support_ticket_open', 'ticket#' . $result['id'], $subject);
        flash('Ticket created. Staff will get back to you soon.');
        redirect('/support/' . $result['id']);
    }

    /** GET /support/{id} — view a ticket thread. */
    public static function show(array $params): void
    {
        $user = self::requireAccount();
        $ticket = SupportService::get((int) $params['id']);
        if (!$ticket || !SupportService::canView($ticket, $user)) {
            render_view('errors/notfound', [], null);
        }
        $isStaff = ModerationService::isStaff($user);
        render_view('support/show', [
            'user' => $user,
            'ticket' => $ticket,
            'replies' => SupportService::replies((int) $ticket['id']),
            'error' => flash(),
            'isStaffView' => $isStaff,
            'staff' => $isStaff ? SupportService::staff() : [],
            'contactEmail' => $isStaff ? SupportService::contactEmail($ticket) : null,
        ]);
    }

    /** POST /admin/support/create — staff opens a ticket for a user or an email. */
    public static function staffCreate(): void
    {
        $staff = ModerationService::requireStaff();
        Csrf::verify();
        $userId = (int) ($_POST['user_id'] ?? 0);
        $email = trim((string) ($_POST['email'] ?? ''));
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
        $subject = (string) ($_POST['subject'] ?? '');
        $content = (string) ($_POST['content'] ?? '');
        $result = SupportService::createStaff(
            $userId > 0 ? $userId : null,
            $email,
            (int) $staff['id'],
            $subject,
            $content,
            $assignedTo > 0 ? $assignedTo : null
        );
        if (!$result['ok']) {
            flash($result['error']);
            redirect('/admin/support');
        }
        log_audit('support_ticket_staff_open', 'ticket#' . $result['id'], $subject);
        flash('Ticket opened.');
        redirect('/admin/support/' . $result['id']);
    }

    /** POST /admin/support/{id}/assign — assign a ticket to an admin/staff member. */
    public static function assign(array $params): void
    {
        $staff = ModerationService::requireStaff();
        Csrf::verify();
        $ticket = SupportService::get((int) $params['id']);
        if (!$ticket) {
            render_view('errors/notfound', [], null);
        }
        $assignedTo = (int) ($_POST['assigned_to'] ?? 0);
        SupportService::assign((int) $ticket['id'], $assignedTo > 0 ? $assignedTo : null);
        log_audit('support_ticket_assign', 'ticket#' . $ticket['id'], $assignedTo > 0 ? 'staff#' . $assignedTo : 'unassigned');
        flash('Assignment updated.');
        redirect('/admin/support/' . (int) $ticket['id']);
    }

    /** POST /support/{id}/reply — add a reply to a ticket. */
    public static function reply(array $params): void
    {
        $user = self::requireAccount();
        Csrf::verify();
        $ticket = SupportService::get((int) $params['id']);
        if (!$ticket || !SupportService::canView($ticket, $user)) {
            http_response_code(404);
            exit('Not found');
        }
        $content = trim((string) ($_POST['content'] ?? ''));
        $result = SupportService::reply((int) $ticket['id'], $user, $content);
        if (!$result['ok']) {
            flash($result['error']);
        } else {
            log_audit('support_ticket_reply', 'ticket#' . $ticket['id'], $user['username']);
            flash('Reply sent.');
        }
        redirect('/support/' . (int) $ticket['id']);
    }

    /** POST /support/{id}/close — close a ticket. */
    public static function close(array $params): void
    {
        $user = self::requireAccount();
        Csrf::verify();
        $ticket = SupportService::get((int) $params['id']);
        if (!$ticket || !SupportService::canView($ticket, $user)) {
            http_response_code(404);
            exit('Not found');
        }
        SupportService::setStatus((int) $ticket['id'], 'closed', $user);
        log_audit('support_ticket_close', 'ticket#' . $ticket['id']);
        redirect('/support/' . (int) $ticket['id']);
    }

    /** POST /support/{id}/reopen — reopen a ticket (staff). */
    public static function reopen(array $params): void
    {
        $user = self::requireAccount();
        Csrf::verify();
        $ticket = SupportService::get((int) $params['id']);
        if (!$ticket || !SupportService::canView($ticket, $user)) {
            http_response_code(404);
            exit('Not found');
        }
        SupportService::setStatus((int) $ticket['id'], 'open', $user);
        log_audit('support_ticket_reopen', 'ticket#' . $ticket['id']);
        redirect('/support/' . (int) $ticket['id']);
    }
}

<?php

declare(strict_types=1);

/**
 * Account invitations. An admin invites an email address; a token is stored in
 * `registration_invites` and emailed. The recipient opens /register?invite=<token>
 * which proves access even when open registration is disabled.
 */
final class InviteService
{
    /** Invite links are valid for this many days. */
    public const TTL_DAYS = 7;

    /** Create an invite and email the link. Returns ok + token/link (link is
     *  still usable/copyable even when the email itself fails to send). */
    public static function create(string $email, ?string $message, int $invitedBy): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'A valid email address is required.'];
        }
        if (Database::scalar('SELECT id FROM users WHERE email = ? COLLATE NOCASE', [$email])) {
            return ['ok' => false, 'error' => 'That email address already belongs to a registered user.'];
        }
        $token = bin2hex(random_bytes(24));
        $msg = $message !== null && trim($message) !== '' ? mb_substr(trim($message), 0, 1000) : null;
        Database::query(
            'INSERT INTO registration_invites (email, token, invited_by, message, expires_at)
             VALUES (?, ?, ?, ?, datetime("now", "+' . self::TTL_DAYS . ' days"))',
            [$email, $token, $invitedBy, $msg]
        );
        $link = self::link($token);
        $mail = Mailer::sendInvite($email, $link, $msg ?? '');
        return [
            'ok' => true,
            'id' => (int) Database::lastId(),
            'email' => $email,
            'token' => $token,
            'link' => $link,
            'email_sent' => $mail['ok'],
            'error' => $mail['error'],
        ];
    }

    /** Return the invite row if the token is unused and unexpired, else null. */
    public static function valid(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }
        $inv = Database::row(
            'SELECT * FROM registration_invites WHERE token = ? AND used_at IS NULL AND expires_at > datetime("now")',
            [$token]
        );
        return $inv ?: null;
    }

    /** Mark an invite used once the account is created. */
    public static function claim(int $id, int $userId): void
    {
        Database::query(
            'UPDATE registration_invites SET used_at = datetime("now"), used_by_user_id = ? WHERE id = ?',
            [$userId, $id]
        );
    }

    public static function revoke(int $id): void
    {
        Database::query('DELETE FROM registration_invites WHERE id = ?', [$id]);
    }

    public static function row(int $id): ?array
    {
        return Database::row('SELECT * FROM registration_invites WHERE id = ?', [$id]);
    }

    /** Roll a new token for an unused invite and email it again. */
    public static function resend(int $id): array
    {
        $inv = self::row($id);
        if (!$inv) {
            return ['ok' => false, 'error' => 'Invite not found.'];
        }
        if (!empty($inv['used_at'])) {
            return ['ok' => false, 'error' => 'That invite has already been used.'];
        }
        $token = bin2hex(random_bytes(24));
        Database::query(
            'UPDATE registration_invites SET token = ?, created_at = datetime("now"),
             expires_at = datetime("now", "+' . self::TTL_DAYS . ' days") WHERE id = ?',
            [$token, $id]
        );
        $link = self::link($token);
        $mail = Mailer::sendInvite($inv['email'], $link, (string) ($inv['message'] ?? ''));
        return [
            'ok' => true,
            'id' => $id,
            'email' => $inv['email'],
            'token' => $token,
            'link' => $link,
            'email_sent' => $mail['ok'],
            'error' => $mail['error'],
        ];
    }

    /** All invites, newest first, with the inviter/claimant usernames joined in. */
    public static function all(): array
    {
        return Database::all(
            'SELECT i.*, u.username AS invited_by_name, c.username AS used_by_name
             FROM registration_invites i
             LEFT JOIN users u ON u.id = i.invited_by
             LEFT JOIN users c ON c.id = i.used_by_user_id
             ORDER BY i.id DESC LIMIT 200'
        );
    }

    public static function link(string $token): string
    {
        return base_url() . '/register?invite=' . rawurlencode($token);
    }
}

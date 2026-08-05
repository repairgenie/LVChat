<?php

declare(strict_types=1);

/**
 * Dependency-free SMTP mailer. Reads its settings from `server_config` (managed
 * under Admin → Settings) and talks SMTP directly over a socket — no mail(),
 * no PHPMailer, no Composer. Supports plaintext, SSL (implicit) and STARTTLS,
 * plus AUTH PLAIN / AUTH LOGIN.
 *
 * Config keys: smtp_enabled, smtp_host, smtp_port, smtp_encryption
 * (none|ssl|tls), smtp_username, smtp_password, smtp_from_email, smtp_from_name.
 */
final class Mailer
{
    /** Send a prebuilt email. Returns ['ok' => bool, 'error' => ?string]. */
    public static function send(string $to, string $subject, string $textBody, string $htmlBody = ''): array
    {
        $host = trim((string) (config_get('smtp_host', '') ?? ''));
        if (config_get('smtp_enabled', '0') !== '1') {
            return ['ok' => false, 'error' => 'SMTP is disabled. Enable it under Admin → Settings.'];
        }
        if ($host === '') {
            return ['ok' => false, 'error' => 'SMTP is enabled but no host is set.'];
        }
        $from = trim((string) (config_get('smtp_from_email', '') ?? ''));
        if ($from === '') {
            return ['ok' => false, 'error' => 'A "From" email address is required in the SMTP settings.'];
        }
        $port = max(1, (int) (config_get('smtp_port', '587') ?? 587));
        $encryption = (string) (config_get('smtp_encryption', 'tls') ?? 'tls');
        $username = (string) (config_get('smtp_username', '') ?? '');
        $password = (string) (config_get('smtp_password', '') ?? '');
        $timeout = 10;

        $errno = 0;
        $errstr = '';
        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $fp = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, $timeout);
        if (!$fp) {
            return ['ok' => false, 'error' => "Could not connect to $host:$port (" . ($errstr !== '' ? $errstr : "errno $errno") . ').'];
        }
        stream_set_timeout($fp, $timeout);
        try {
            $greeting = self::read($fp);
            if (!preg_match('/^220/', $greeting)) {
                return ['ok' => false, 'error' => 'SMTP greeting failed: ' . self::firstLine($greeting)];
            }

            if ($encryption === 'tls') {
                $code = self::cmd($fp, 'EHLO ' . self::helo());
                if (!preg_match('/^250/', $code) && !preg_match('/^250/', self::cmd($fp, 'HELO ' . self::helo()))) {
                    return ['ok' => false, 'error' => 'Server rejected EHLO/HELO: ' . self::firstLine($code)];
                }
                $code = self::cmd($fp, 'STARTTLS');
                if (!preg_match('/^220/', $code)) {
                    return ['ok' => false, 'error' => 'Server refused STARTTLS: ' . self::firstLine($code)];
                }
                if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'error' => 'STARTTLS negotiation failed.'];
                }
                $code = self::cmd($fp, 'EHLO ' . self::helo());
                if (!preg_match('/^250/', $code)) {
                    return ['ok' => false, 'error' => 'EHLO after STARTTLS failed: ' . self::firstLine($code)];
                }
            } else {
                $code = self::cmd($fp, 'EHLO ' . self::helo());
                if (!preg_match('/^250/', $code) && !preg_match('/^250/', self::cmd($fp, 'HELO ' . self::helo()))) {
                    return ['ok' => false, 'error' => 'Server rejected EHLO/HELO: ' . self::firstLine($code)];
                }
            }

            if ($username !== '') {
                $authed = false;
                $code = self::cmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $username . "\0" . $password));
                if (preg_match('/^235/', $code)) {
                    $authed = true;
                } elseif (preg_match('/^334/', $code)) {
                    $code = self::cmd($fp, base64_encode(''));
                    $authed = preg_match('/^235/', $code);
                }
                if (!$authed) {
                    $code = self::cmd($fp, 'AUTH LOGIN');
                    if (preg_match('/^334/', $code)) {
                        $code = self::cmd($fp, base64_encode($username));
                        if (preg_match('/^334/', $code)) {
                            $authed = preg_match('/^235/', self::cmd($fp, base64_encode($password)));
                        }
                    }
                }
                if (!$authed) {
                    return ['ok' => false, 'error' => 'SMTP authentication failed (check username/password).'];
                }
            }

            $code = self::cmd($fp, 'MAIL FROM:<' . $from . '>');
            if (!preg_match('/^250/', $code)) {
                return ['ok' => false, 'error' => 'MAIL FROM rejected: ' . self::firstLine($code)];
            }
            $code = self::cmd($fp, 'RCPT TO:<' . $to . '>');
            if (!preg_match('/^25[025]/', $code)) {
                return ['ok' => false, 'error' => 'Recipient rejected: ' . self::firstLine($code)];
            }

            $code = self::cmd($fp, 'DATA');
            if (!preg_match('/^354/', $code)) {
                return ['ok' => false, 'error' => 'DATA refused: ' . self::firstLine($code)];
            }

            $fromName = trim((string) (config_get('smtp_from_name', '') ?? ''));
            $boundary = 'bnd-' . bin2hex(random_bytes(8));
            $headers = 'From: ' . ($fromName !== '' ? self::encodeHeader($fromName) . ' ' : '') . '<' . $from . '>' . "\r\n"
                . 'To: <' . $to . '>' . "\r\n"
                . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
                . 'Date: ' . date('r') . "\r\n"
                . 'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . self::helo() . '>' . "\r\n"
                . 'MIME-Version: 1.0' . "\r\n";

            if ($htmlBody !== '') {
                $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '"' . "\r\n";
                $body = '--' . $boundary . "\r\n"
                    . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
                    . chunk_split(base64_encode($textBody)) . "\r\n"
                    . '--' . $boundary . "\r\n"
                    . 'Content-Type: text/html; charset=UTF-8' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
                    . chunk_split(base64_encode($htmlBody)) . "\r\n"
                    . '--' . $boundary . '--' . "\r\n";
            } else {
                $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n";
                $body = chunk_split(base64_encode($textBody));
            }

            fwrite($fp, $headers . "\r\n" . $body . "\r\n.\r\n");
            $code = self::read($fp);
            if (!preg_match('/^250/', $code)) {
                return ['ok' => false, 'error' => 'Message not accepted: ' . self::firstLine($code)];
            }
            self::cmd($fp, 'QUIT');
            return ['ok' => true, 'error' => null];
        } finally {
            fclose($fp);
        }
    }

    /** Send an account-invitation email with a sign-up link. */
    public static function sendInvite(string $to, string $link, string $message = ''): array
    {
        $site = (string) (config_get('site_name', 'LVChat') ?: 'LVChat');
        $subject = "You're invited to join $site";
        $text = "Hello,\n\nYou have been invited to create an account on $site.\n\n";
        if ($message !== '') {
            $text .= "Message from the team:\n$message\n\n";
        }
        $text .= 'Click this link to create your account:' . "\n$link\n\n"
            . 'The link expires in ' . InviteService::TTL_DAYS . " days. If you did not expect this invitation, you can ignore this email.\n";

        $body = '<p>Hello,</p>'
            . '<p>You have been invited to create an account on <strong>' . h($site) . '</strong>.</p>';
        if ($message !== '') {
            $body .= '<p><em>"' . h($message) . '"</em></p>';
        }
        $body .= '<p><a href="' . h($link) . '" style="display:inline-block;background:#5865f2;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600">Create your account</a></p>'
            . '<p style="font-size:13px;color:#a1a5ab">Or open this link: <a href="' . h($link) . '">' . h($link) . '</a></p>'
            . '<p style="font-size:13px;color:#a1a5ab">The link expires in ' . InviteService::TTL_DAYS . ' days. If you did not expect this invitation, you can ignore this email.</p>';

        return self::send($to, $subject, $text, self::htmlWrap($subject, $body));
    }

    /** Send a "welcome, here are your credentials" email (admin-created account). */
    public static function sendWelcome(string $to, string $username, string $password): array
    {
        $site = (string) (config_get('site_name', 'LVChat') ?: 'LVChat');
        $subject = "Your $site account";
        $login = base_url() . '/login';
        $text = "Hello $username,\n\nAn account has been created for you on $site.\n\n"
            . "Username: $username\n"
            . "Password: $password\n\n"
            . "Log in here: $login\n\n"
            . 'You can change your password after logging in with /set password <new>.';

        $body = '<p>Hello ' . h($username) . ',</p>'
            . '<p>An account has been created for you on <strong>' . h($site) . '</strong>.</p>'
            . '<p style="font-size:13px;color:#a1a5ab;margin-bottom:2px">Username</p>'
            . '<p style="font-weight:600;margin-top:2px">' . h($username) . '</p>'
            . '<p style="font-size:13px;color:#a1a5ab;margin-bottom:2px">Password</p>'
            . '<p style="font-weight:600;margin-top:2px">' . h($password) . '</p>'
            . '<p><a href="' . h($login) . '" style="display:inline-block;background:#5865f2;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600">Log in</a></p>'
            . '<p style="font-size:13px;color:#a1a5ab">You can change your password after logging in with <code>/set password &lt;new&gt;</code>.</p>';

        return self::send($to, $subject, $text, self::htmlWrap($subject, $body));
    }

    /** Send the ticket owner an email when a staff member replies to their support ticket. */
    public static function sendSupportReply(string $to, string $subject, string $reply, string $staffName, int $ticketId): array
    {
        $site = (string) (config_get('site_name', 'LVChat') ?: 'LVChat');
        $ticketSubject = trim($subject) !== '' ? $subject : 'your support ticket';
        $mailSubject = "Re: $ticketSubject";
        $link = base_url() . '/support/' . $ticketId;
        $plainReply = strip_tags($reply);
        $text = "Hi,\n\n$staffName has replied to your support ticket on $site.\n\n"
            . "Reply:\n$plainReply\n\n"
            . "View the ticket: $link\n";

        $safeReply = LegalService::sanitize($reply);
        $body = '<p>Hi,</p>'
            . '<p><strong>' . h($staffName) . '</strong> has replied to your support ticket on <strong>' . h($site) . '</strong>.</p>'
            . '<div style="border-left:3px solid #5865f2;background:#232428;padding:10px 14px;border-radius:6px">' . $safeReply . '</div>'
            . '<p><a href="' . h($link) . '" style="display:inline-block;background:#5865f2;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600">View the ticket</a></p>';

        return self::send($to, $mailSubject, $text, self::htmlWrap($mailSubject, $body));
    }

    /** Send a password-reset email with a one-time link. */
    public static function sendPasswordReset(string $to, string $username, string $resetUrl): array
    {
        $site = (string) (config_get('site_name', 'LVChat') ?: 'LVChat');
        $subject = "Reset your $site password";
        $text = "Hello $username,\n\nWe received a request to reset the password for your $site account.\n\n"
            . "Click this link to choose a new password:\n$resetUrl\n\n"
            . 'The link expires in ' . AuthTokenService::RESET_TTL_MINUTES . ' minutes and can only be used once. '
            . "If you did not request a password reset, you can ignore this email — your password will not change.\n";

        $body = '<p>Hello ' . h($username) . ',</p>'
            . '<p>We received a request to reset the password for your <strong>' . h($site) . '</strong> account.</p>'
            . '<p><a href="' . h($resetUrl) . '" style="display:inline-block;background:#5865f2;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600">Reset your password</a></p>'
            . '<p style="font-size:13px;color:#a1a5ab">Or open this link: <a href="' . h($resetUrl) . '">' . h($resetUrl) . '</a></p>'
            . '<p style="font-size:13px;color:#a1a5ab">The link expires in ' . AuthTokenService::RESET_TTL_MINUTES . ' minutes and can only be used once. If you did not request a password reset, you can ignore this email &mdash; your password will not change.</p>';

        return self::send($to, $subject, $text, self::htmlWrap($subject, $body));
    }

    /** Send a magic-link login email with a one-time link. */
    public static function sendMagicLink(string $to, string $username, string $magicUrl): array
    {
        $site = (string) (config_get('site_name', 'LVChat') ?: 'LVChat');
        $subject = "Log in to $site";
        $text = "Hello $username,\n\nClick this link to log in to your $site account:\n$magicUrl\n\n"
            . 'The link expires in ' . AuthTokenService::MAGIC_TTL_MINUTES . ' minutes and can only be used once. '
            . "If you did not request this link, you can ignore this email.\n";

        $body = '<p>Hello ' . h($username) . ',</p>'
            . '<p>Click the button below to log in to your <strong>' . h($site) . '</strong> account.</p>'
            . '<p><a href="' . h($magicUrl) . '" style="display:inline-block;background:#5865f2;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:600">Log in to ' . h($site) . '</a></p>'
            . '<p style="font-size:13px;color:#a1a5ab">Or open this link: <a href="' . h($magicUrl) . '">' . h($magicUrl) . '</a></p>'
            . '<p style="font-size:13px;color:#a1a5ab">The link expires in ' . AuthTokenService::MAGIC_TTL_MINUTES . ' minutes and can only be used once. If you did not request this link, you can ignore this email.</p>';

        return self::send($to, $subject, $text, self::htmlWrap($subject, $body));
    }

    /** Whether SMTP is enabled and pointed at a host (used to hide/emphasise UI). */
    public static function configured(): bool
    {
        return config_get('smtp_enabled', '0') === '1'
            && trim((string) (config_get('smtp_host', '') ?? '')) !== '';
    }

    private static function cmd($fp, string $line): string
    {
        fwrite($fp, $line . "\r\n");
        return self::read($fp);
    }

    /** Read a (possibly multi-line) SMTP reply up to the "code " terminator. */
    private static function read($fp): string
    {
        $data = '';
        while (($line = fgets($fp)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return trim($data);
    }

    private static function firstLine(string $reply): string
    {
        return trim(explode("\n", $reply)[0]);
    }

    private static function helo(): string
    {
        return !empty($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : (php_uname('n') ?: 'localhost');
    }

    /** RFC 2047 encode a header value when it contains non-ASCII characters. */
    private static function encodeHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }

    private static function htmlWrap(string $heading, string $bodyHtml): string
    {
        return '<div style="font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;border:1px solid #2b2d31;border-radius:8px;background:#1e1f22;color:#e3e5e8">'
            . '<div style="font-size:18px;font-weight:600;color:#fff;margin-bottom:12px">' . h($heading) . '</div>'
            . '<div style="font-size:14px;line-height:1.6;color:#c4c7cc">' . $bodyHtml . '</div>'
            . '</div>';
    }
}

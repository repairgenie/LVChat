<?php

declare(strict_types=1);

final class AuthController
{
    public static function loginForm(): void
    {
        if (Auth::user()) {
            redirect('/app?channel=general');
        }
        $next = $_GET['next'] ?? '/app?channel=general';
        render_view('auth/login', [
            'next' => $next,
            'error' => flash(),
        ]);
    }

    public static function login(): void
    {
        Csrf::verify();
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $next = $_POST['next'] ?? '/app?channel=general';

        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many failed login attempts. Please wait a few minutes and try again.');
            redirect('/login?next=' . rawurlencode($next));
        }

        $user = Auth::attempt($username, $password);
        if (!$user) {
            login_attempt_record();
            flash('Invalid username or password.');
            redirect('/login?next=' . rawurlencode($next));
        }
        $ban = Auth::globalBanFor($user);
        if ($ban || (int) $user['banned'] === 1) {
            login_attempt_record();
            $reason = $ban['reason'] ?? $user['ban_reason'] ?? '';
            flash('This account is banned.' . ($reason ? ' Reason: ' . $reason : ''));
            redirect('/login');
        }
        if (($user['status'] ?? 'active') === 'suspended') {
            login_attempt_record();
            $reason = trim((string) ($user['status_reason'] ?? ''));
            flash('This account is suspended.' . ($reason !== '' ? ' Reason: ' . $reason : ''));
            redirect('/login');
        }
        login_attempt_clear();
        Auth::login($user);
        redirect($next);
    }

    public static function registerForm(): void
    {
        if (Auth::user()) {
            redirect('/app?channel=general');
        }
        $next = $_GET['next'] ?? '/app?channel=general';
        $inviteToken = trim((string) ($_GET['invite'] ?? ''));
        $invite = null;
        if ($inviteToken !== '') {
            $invite = InviteService::valid($inviteToken);
            if (!$invite) {
                flash('This invitation is invalid, expired, or has already been used.');
                redirect('/register');
            }
        }
        render_view('auth/register', [
            'next' => $next,
            'error' => flash(),
            'old' => $_SESSION['old'] ?? [],
            'invite' => $invite,
            'registration_open' => config_get('registration_enabled', '1') === '1',
            'requires_approval' => config_get('registration_requires_approval', '0') === '1',
        ]);
    }

    public static function register(): void
    {
        Csrf::verify();
        $next = $_POST['next'] ?? '/app?channel=general';
        $inviteToken = trim((string) ($_POST['invite'] ?? ''));
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $invite = null;
        if ($inviteToken !== '') {
            // An invite token bypasses the registration_enabled toggle: it is
            // the proof of access when open registration is closed.
            $invite = InviteService::valid($inviteToken);
            if (!$invite) {
                $_SESSION['old'] = ['username' => $username];
                flash('This invitation is invalid, expired, or has already been used.');
                redirect('/register');
            }
            if (strcasecmp($email, $invite['email']) !== 0) {
                $_SESSION['old'] = ['username' => $username];
                flash('This invitation is only valid for ' . $invite['email'] . '.');
                redirect('/register?invite=' . rawurlencode($inviteToken));
            }
        } elseif (config_get('registration_enabled', '1') !== '1') {
            flash('Registration is currently disabled on this server.');
            redirect('/register');
        }

        $age18 = ($_POST['age18'] ?? '0') === '1';
        $result = Auth::register($username, $email, $password, $age18);
        if (!$result['ok']) {
            $_SESSION['old'] = ['username' => $username, 'email' => $email];
            flash(implode(' ', $result['errors']));
            redirect('/register?next=' . rawurlencode($next) . ($invite ? '&invite=' . rawurlencode($inviteToken) : ''));
        }
        if ($invite) {
            InviteService::claim((int) $invite['id'], (int) $result['id']);
        }
        $user = Auth::attempt($username, $password);
        Auth::login($user);
        if (($user['status'] ?? 'active') === 'pending') {
            flash('Your account is pending admin approval. You can browse channels, but you cannot chat until an admin approves it.');
            redirect('/app');
        }
        redirect($next);
    }

    public static function logout(): void
    {
        Csrf::verify();
        Auth::logout();
        redirect('/login');
    }

    public static function guestLogin(): void
    {
        Csrf::verify();
        $next = $_POST['next'] ?? '/app?channel=general';
        $nick = trim((string) ($_POST['nick'] ?? ''));
        $age18 = ($_POST['age18'] ?? '0') === '1';
        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many attempts. Please wait a few minutes and try again.');
            redirect('/login?next=' . rawurlencode($next));
        }
        if (!$age18) {
            login_attempt_record();
            flash('You must certify that you are at least 18 years old to join as a guest.');
            redirect('/login?next=' . rawurlencode($next));
        }
        $user = Auth::loginGuest($nick, true);
        if (!$user) {
            login_attempt_record();
            flash('That nickname is invalid or already in use. Try another one.');
            redirect('/login?next=' . rawurlencode($next));
        }
        login_attempt_clear();
        redirect($next);
    }
}

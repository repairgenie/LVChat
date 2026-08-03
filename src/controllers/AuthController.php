<?php

declare(strict_types=1);

final class AuthController
{
    public static function loginForm(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        $next = $_GET['next'] ?? '/';
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
        $next = $_POST['next'] ?? '/';

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
        login_attempt_clear();
        Auth::login($user);
        redirect($next);
    }

    public static function registerForm(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
        $next = $_GET['next'] ?? '/';
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
        ]);
    }

    public static function register(): void
    {
        Csrf::verify();
        $next = $_POST['next'] ?? '/';
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

        $result = Auth::register($username, $email, $password);
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
        $next = $_POST['next'] ?? '/';
        $nick = trim((string) ($_POST['nick'] ?? ''));
        if (login_attempt_count() >= login_attempt_max()) {
            flash('Too many attempts. Please wait a few minutes and try again.');
            redirect('/login?next=' . rawurlencode($next));
        }
        $user = Auth::loginGuest($nick);
        if (!$user) {
            login_attempt_record();
            flash('That nickname is invalid or already in use. Try another one.');
            redirect('/login?next=' . rawurlencode($next));
        }
        login_attempt_clear();
        redirect($next);
    }
}

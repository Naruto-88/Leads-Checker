<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Security\Csrf;
use App\Models\User;
use App\Models\PasswordReset;
use App\Helpers;

class AuthController
{
    public function __construct(private array $env) {}

    public function register(): void
    {
        if (Auth::user()) { Helpers::redirect('/'); }
        View::render('auth/register');
    }

    public function registerPost(): void
    {
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            View::render('auth/register', ['error' => 'Invalid email or password too short.']);
            return;
        }
        if (User::byEmail($email)) {
            View::render('auth/register', ['error' => 'Email already registered.']);
            return;
        }
        $hash = Auth::hashPassword($password);
        User::create($email, $hash);
        Helpers::redirect('/auth/login');
    }

    public function login(): void
    {
        if (Auth::user()) { Helpers::redirect('/'); }
        View::render('auth/login');
    }

    public function loginPost(): void
    {
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (Auth::login($email, $password)) {
            Helpers::redirect('/');
        }
        View::render('auth/login', ['error' => 'Invalid credentials']);
    }

    public function logoutPost(): void
    {
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        Auth::logout();
        Helpers::redirect('/auth/login');
    }

    public function forgot(): void
    {
        View::render('auth/forgot');
    }

    public function forgotPost(): void
    {
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $email = trim($_POST['email'] ?? '');
        $user = User::byEmail($email);
        if ($user) {
            $token = PasswordReset::createToken((int)$user['id']);
            // In production, email link. Here we display on page as acceptable dev flow
            View::render('auth/forgot', ['info' => 'Reset link (dev): /auth/reset?token=' . $token]);
            return;
        }
        View::render('auth/forgot', ['info' => 'If account exists, a reset link was generated.']);
    }

    public function reset(): void
    {
        $token = $_GET['token'] ?? '';
        View::render('auth/reset', ['token'=>$token]);
    }

    public function resetPost(): void
    {
        if (!Csrf::validate()) { http_response_code(400); echo 'Bad CSRF'; return; }
        $token = $_POST['token'] ?? '';
        $row = PasswordReset::find($token);
        if (!$row) { View::render('auth/reset', ['error' => 'Invalid token', 'token'=>$token]); return; }
        $password = $_POST['password'] ?? '';
        if (strlen($password) < 8) { View::render('auth/reset', ['error' => 'Password too short', 'token'=>$token]); return; }
        $hash = Auth::hashPassword($password);
        \App\Models\User::updatePassword((int)$row['user_id'], $hash);
        PasswordReset::consume($token);
        Helpers::redirect('/auth/login');
    }
}


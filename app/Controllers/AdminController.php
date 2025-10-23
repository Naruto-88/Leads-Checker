<?php
namespace App\Controllers;

use App\Core\View;
use App\Security\Auth;
use App\Security\Csrf;
use App\Models\User;
use App\Models\PasswordReset;
use App\Helpers;

class AdminController
{
    public function __construct(private array $env) {}

    public function users(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) { http_response_code(403); echo 'Forbidden'; return; }
        $users = User::all();
        View::render('admin/users', ['users' => $users]);
    }

    public function promote(): void
    {
        Auth::requireLogin(); if (!Auth::isAdmin()) { http_response_code(403); return; }
        if (!Csrf::validate()) { http_response_code(400); return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) User::updateRole($id, 'admin');
        Helpers::redirect('/admin/users');
    }

    public function demote(): void
    {
        Auth::requireLogin(); if (!Auth::isAdmin()) { http_response_code(403); return; }
        if (!Csrf::validate()) { http_response_code(400); return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) User::updateRole($id, 'user');
        Helpers::redirect('/admin/users');
    }

    public function delete(): void
    {
        Auth::requireLogin(); if (!Auth::isAdmin()) { http_response_code(403); return; }
        if (!Csrf::validate()) { http_response_code(400); return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) User::softDelete($id);
        Helpers::redirect('/admin/users');
    }

    public function resetPassword(): void
    {
        Auth::requireLogin(); if (!Auth::isAdmin()) { http_response_code(403); return; }
        if (!Csrf::validate()) { http_response_code(400); return; }
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $token = PasswordReset::createToken($id);
            $_SESSION['flash'] = 'Reset link (dev): /auth/reset?token=' . $token;
        }
        Helpers::redirect('/admin/users');
    }

    public function createUser(): void
    {
        Auth::requireLogin(); if (!Auth::isAdmin()) { http_response_code(403); return; }
        if (!Csrf::validate()) { http_response_code(400); return; }
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
            $_SESSION['flash'] = 'Invalid email or password too short';
            Helpers::redirect('/admin/users');
        }
        if (\App\Models\User::byEmail($email)) {
            $_SESSION['flash'] = 'Email already exists';
            Helpers::redirect('/admin/users');
        }
        $hash = \App\Security\Auth::hashPassword($password);
        \App\Models\User::create($email, $hash);
        $_SESSION['flash'] = 'User created';
        Helpers::redirect('/admin/users');
    }
}

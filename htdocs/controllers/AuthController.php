<?php
declare(strict_types=1);

class AuthController
{
    private UserModel $userModel;

    public function __construct(PDO $db)
    {
        $this->userModel = new UserModel($db);
    }

    public function index(): void
    {
        render('auth/login.php', [
            'pageTitle' => 'Autentificare',
            'showSidebar' => false,
            'currentPage' => '',
        ]);
    }

    public function autentificare(): void
    {
        ensure_csrf_or_redirect(url('index.php?page=login'));

        $email = trim((string) ($_POST['email'] ?? ''));
        $parola = (string) ($_POST['parola'] ?? '');

        if ($email === '' || $parola === '') {
            flash_set('danger', 'Completeaza emailul si parola.');
            redirect(url('index.php?page=login'));
        }

        $user = $this->userModel->findByEmail($email);

        if (!$user || !password_verify($parola, $user['parola'])) {
            flash_set('danger', 'Date de autentificare invalide.');
            redirect(url('index.php?page=login'));
        }

        if (($user['status'] ?? 'inactiv') !== 'activ') {
            flash_set('warning', 'Contul este inactiv. Contacteaza administratorul.');
            redirect(url('index.php?page=login'));
        }

        login_user($user);
        flash_set('success', 'Bine ai venit, ' . $user['nume'] . '!');
        redirect(url('index.php?page=dashboard'));
    }

    public function logout(): void
    {
        logout_user();
        flash_set('info', 'Ai fost deconectat cu succes.');
        redirect(url('index.php?page=login'));
    }
}

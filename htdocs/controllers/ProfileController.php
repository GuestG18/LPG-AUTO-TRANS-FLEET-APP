<?php
declare(strict_types=1);

class ProfileController
{
    private UserModel $userModel;

    public function __construct(PDO $db)
    {
        $this->userModel = new UserModel($db);
    }

    public function index(): void
    {
        $user = $this->userModel->findById((int) current_user()['id']);

        if ($user === null) {
            flash_set('danger', 'Utilizatorul curent nu a fost gasit.');
            redirect(url('index.php?page=dashboard'));
        }

        $formFlash = consume_form_flash();
        $old = $formFlash['old'];
        $errors = $formFlash['errors'];

        $formData = [
            'nume' => $old['nume'] ?? $user['nume'],
            'email' => $old['email'] ?? $user['email'],
            'telefon' => $old['telefon'] ?? ($user['telefon'] ?? ''),
        ];

        render('profile/index.php', [
            'pageTitle' => 'Profilul meu',
            'currentPage' => 'profil',
            'formData' => $formData,
            'errors' => $errors,
        ]);
    }

    public function actualizeaza(): void
    {
        ensure_csrf_or_redirect(url('index.php?page=profil'));

        $id = (int) current_user()['id'];
        $user = $this->userModel->findById($id);

        if ($user === null) {
            flash_set('danger', 'Utilizatorul curent nu a fost gasit.');
            redirect(url('index.php?page=dashboard'));
        }

        $nume = trim((string) ($_POST['nume'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $telefon = trim((string) ($_POST['telefon'] ?? ''));
        $parolaNoua = (string) ($_POST['parola_noua'] ?? '');
        $confirmareParola = (string) ($_POST['confirmare_parola'] ?? '');

        $errors = [];
        if ($nume === '') {
            $errors['nume'] = 'Numele este obligatoriu.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email invalid.';
        }

        if ($email !== '' && $this->userModel->existsValue('utilizatori', 'email', $email, $id)) {
            $errors['email'] = 'Exista deja un cont cu acest email.';
        }

        if ($telefon !== '' && strlen($telefon) > 20) {
            $errors['telefon'] = 'Numarul de telefon este prea lung.';
        }

        if ($parolaNoua !== '' || $confirmareParola !== '') {
            if (strlen($parolaNoua) < 8) {
                $errors['parola_noua'] = 'Parola noua trebuie sa aiba minimum 8 caractere.';
            }

            if ($parolaNoua !== $confirmareParola) {
                $errors['confirmare_parola'] = 'Confirmarea parolei nu coincide.';
            }
        }

        if ($errors !== []) {
            set_form_flash([
                'nume' => $nume,
                'email' => $email,
                'telefon' => $telefon,
            ], $errors);
            redirect(url('index.php?page=profil'));
        }

        $updateData = [
            'nume' => $nume,
            'email' => $email,
            'telefon' => $telefon !== '' ? $telefon : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($parolaNoua !== '') {
            $updateData['parola'] = password_hash($parolaNoua, PASSWORD_DEFAULT);
        }

        $this->userModel->updateProfile($id, $updateData);

        $_SESSION['auth_user']['nume'] = $nume;
        $_SESSION['auth_user']['email'] = $email;

        flash_set('success', 'Profilul a fost actualizat cu succes.');
        redirect(url('index.php?page=profil'));
    }
}

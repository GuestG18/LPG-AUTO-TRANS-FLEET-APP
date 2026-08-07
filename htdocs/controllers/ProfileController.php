<?php
declare(strict_types=1);

class ProfileController
{
    private UserModel $userModel;
    private PasskeyModel $passkeyModel;

    private const PASSKEY_REG_CHALLENGE_KEY = '_passkey_reg_challenge';

    public function __construct(PDO $db)
    {
        $this->userModel = new UserModel($db);
        $this->passkeyModel = new PasskeyModel($db);
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

        $passkeys = [];
        try {
            $passkeys = $this->passkeyModel->listForUser((int) $user['id']);
        } catch (Throwable $exception) {
            error_log('[profile][passkeys] ' . $exception->getMessage());
        }

        render('profile/index.php', [
            'pageTitle' => 'Profilul meu',
            'currentPage' => 'profil',
            'formData' => $formData,
            'errors' => $errors,
            'passkeys' => $passkeys,
        ]);
    }

    /** Optiuni pentru inregistrarea unui passkey nou (challenge), ca JSON. */
    public function passkeyRegisterOptions(): void
    {
        try {
            $user = current_user() ?? [];
            $uid = (int) ($user['id'] ?? 0);
            $webAuthn = WebAuthnService::create();
            $exclude = array_map(
                [WebAuthnService::class, 'b64urlDecode'],
                $this->passkeyModel->credentialIdsForUser($uid)
            );
            $args = $webAuthn->getCreateArgs(
                (string) $uid,
                (string) ($user['email'] ?? ('user' . $uid)),
                (string) ($user['nume'] ?? ('Utilizator ' . $uid)),
                120,
                'preferred',
                'preferred',
                null,
                $exclude
            );
            $_SESSION[self::PASSKEY_REG_CHALLENGE_KEY] = $webAuthn->getChallenge()->getBinaryString();
            $this->json(['publicKey' => $args->publicKey]);
        } catch (Throwable $exception) {
            error_log('[passkey][reg_options] ' . $exception->getMessage());
            $this->json(['error' => 'Nu am putut initia inregistrarea passkey.'], 500);
        }
    }

    /** Verifica atestarea si salveaza passkey-ul. */
    public function passkeyRegisterVerify(): void
    {
        $body = $this->jsonBody();
        if (!verify_csrf_token((string) ($body['csrf'] ?? ''))) {
            $this->json(['error' => 'Token CSRF invalid. Reincarca pagina.'], 400);
        }

        $uid = (int) (current_user()['id'] ?? 0);
        $challenge = (string) ($_SESSION[self::PASSKEY_REG_CHALLENGE_KEY] ?? '');
        unset($_SESSION[self::PASSKEY_REG_CHALLENGE_KEY]);
        if ($uid <= 0 || $challenge === '') {
            $this->json(['error' => 'Sesiunea de inregistrare a expirat. Reincearca.'], 400);
        }

        $response = (array) ($body['response'] ?? []);
        $clientDataJSON = WebAuthnService::b64urlDecode((string) ($response['clientDataJSON'] ?? ''));
        $attestationObject = WebAuthnService::b64urlDecode((string) ($response['attestationObject'] ?? ''));
        if ($clientDataJSON === '' || $attestationObject === '') {
            $this->json(['error' => 'Date passkey lipsa.'], 400);
        }

        try {
            $webAuthn = WebAuthnService::create();
            $data = $webAuthn->processCreate($clientDataJSON, $attestationObject, $challenge, false, true, false, false);
        } catch (Throwable $exception) {
            error_log('[passkey][reg_verify] ' . $exception->getMessage());
            $this->json(['error' => 'Inregistrarea passkey a esuat.'], 400);
        }

        $credentialId = WebAuthnService::b64urlEncode((string) $data->credentialId);
        if ($credentialId === '' || $this->passkeyModel->existsByCredentialId($credentialId)) {
            $this->json(['error' => 'Acest passkey este deja inregistrat.'], 400);
        }

        $aaguid = isset($data->AAGUID) && (string) $data->AAGUID !== ''
            ? WebAuthnService::b64urlEncode((string) $data->AAGUID)
            : null;
        $transports = implode(',', array_filter(array_map('strval', (array) ($body['transports'] ?? [])), static function (string $t): bool {
            return in_array($t, ['usb', 'nfc', 'ble', 'internal', 'hybrid'], true);
        }));
        $label = trim((string) ($body['label'] ?? ''));
        if ($label === '') {
            $label = 'Passkey ' . date('d.m.Y');
        }

        try {
            $this->passkeyModel->create(
                $uid,
                $credentialId,
                (string) $data->credentialPublicKey,
                (int) ($data->signatureCounter ?? 0),
                $aaguid,
                $transports !== '' ? $transports : null,
                $label
            );
        } catch (Throwable $exception) {
            error_log('[passkey][reg_save] ' . $exception->getMessage());
            $this->json(['error' => 'Nu am putut salva passkey-ul.'], 500);
        }

        flash_set('success', 'Passkey adaugat cu succes.');
        $this->json(['ok' => true]);
    }

    /** Sterge un passkey al utilizatorului curent. */
    public function passkeyDelete(): void
    {
        ensure_csrf_or_redirect(url('index.php?page=profil'));

        $uid = (int) (current_user()['id'] ?? 0);
        $passkeyId = (int) ($_POST['passkey_id'] ?? 0);
        if ($passkeyId > 0 && $this->passkeyModel->deleteForUser($passkeyId, $uid)) {
            flash_set('success', 'Passkey-ul a fost sters.');
        } else {
            flash_set('warning', 'Passkey-ul nu a putut fi sters.');
        }

        redirect(url('index.php?page=profil'));
    }

    /** @return array<string,mixed> */
    private function jsonBody(): array
    {
        $raw = (string) file_get_contents('php://input');
        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
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

<?php

declare(strict_types=1);

namespace ImaginaPay\Domain\Services;

use ImaginaPay\Domain\Entities\Customer;
use ImaginaPay\Domain\Repositories\CustomerRepository;
use ImaginaPay\Mail\EmailNotifications;
use ImaginaPay\Support\Clock;
use ImaginaPay\Support\Logger;

/**
 * Cuenta WP del cliente: se crea automáticamente en la primera compra
 * (rol impay_customer, sin acceso a wp-admin) y recibe el email de
 * establecer contraseña. Idempotente: si ya hay usuario, solo vincula.
 */
class CustomerAccountService
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly EmailNotifications $mail,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    public function ensureWpUser(Customer $customer): void
    {
        if ($customer->wpUserId !== null) {
            return;
        }

        // ¿Ya existe un usuario WP con ese email? Solo vincular.
        $existing = get_user_by('email', $customer->email);

        if ($existing instanceof \WP_User) {
            $this->customers->linkWpUser($customer->id, (int) $existing->ID, $this->clock->now());

            return;
        }

        $userId = wp_insert_user([
            'user_login' => $this->uniqueLogin($customer->email),
            'user_email' => $customer->email,
            'display_name' => $customer->fullName,
            'user_pass' => wp_generate_password(24),
            'role' => 'impay_customer',
        ]);

        if (is_wp_error($userId)) {
            $this->logger->error('accounts', sprintf(
                'No fue posible crear el usuario WP para %s: %s',
                $customer->email,
                $userId->get_error_message(),
            ));

            return;
        }

        $this->customers->linkWpUser($customer->id, (int) $userId, $this->clock->now());

        $this->sendPasswordSetup($customer, (int) $userId);

        $this->logger->info('accounts', sprintf('Usuario WP creado para %s.', $customer->email));
    }

    private function sendPasswordSetup(Customer $customer, int $userId): void
    {
        $user = get_user_by('id', $userId);

        if (!$user instanceof \WP_User) {
            return;
        }

        $key = get_password_reset_key($user);

        if (is_wp_error($key)) {
            $this->logger->warning('accounts', 'No fue posible generar el enlace de contraseña.', [
                'email' => $customer->email,
            ]);

            return;
        }

        $setupUrl = network_site_url(
            sprintf('wp-login.php?action=rp&key=%s&login=%s', $key, rawurlencode($user->user_login)),
            'login',
        );

        $this->mail->passwordSetup($customer->email, $customer->fullName, $setupUrl);
    }

    private function uniqueLogin(string $email): string
    {
        $base = sanitize_user(strstr($email, '@', true) ?: $email, true);
        $base = $base !== '' ? $base : 'cliente';
        $login = $base;
        $suffix = 1;

        while (username_exists($login) !== false) {
            $login = $base . '-' . $suffix;
            ++$suffix;
        }

        return $login;
    }
}

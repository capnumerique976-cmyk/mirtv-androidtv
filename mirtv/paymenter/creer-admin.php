<?php

/**
 * MirTV — crée le compte administrateur de Paymenter s'il n'existe pas.
 * Le mot de passe vient du fichier secret (jamais affiché, jamais en ligne de commande).
 * Lancé dans le conteneur : php artisan tinker --execute="require '/app/mirtv/creer-admin.php';"
 */

use App\Models\Role;
use App\Models\User;

$email = 'admin@mirtv.rahiss.com';
$fichier = (getenv('MIRTV_SECRETS_DIR') ?: '/run/mirtv') . '/paymenter_admin_password';
$mdp = is_readable($fichier) ? trim(file_get_contents($fichier)) : '';
if ($mdp === '') {
    echo "MirTV : mot de passe administrateur absent, compte non créé.\n";

    return;
}
$role = Role::where('name', 'admin')->first() ?? Role::orderBy('id')->first();
if (User::where('email', $email)->exists()) {
    echo "MirTV : administrateur déjà présent ({$email}).\n";

    return;
}
User::create([
    'first_name' => 'Ravi',
    'last_name' => 'MirTV',
    'email' => $email,
    'password' => $mdp,
    'role_id' => $role?->id,
    'email_verified_at' => now(),
]);
echo "MirTV : administrateur créé ({$email}).\n";

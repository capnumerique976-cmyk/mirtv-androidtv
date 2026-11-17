<?php

/**
 * MirTV — applique le mot de passe de admin@mirtv.rahiss.com lu dans le fichier secret (jamais affiché).
 * Lancé par changer-mot-de-passe-admin.sh.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;

$fichier = '/run/secrets-mirtv/paymenter_admin_password';
$mdp = is_readable($fichier) ? trim((string) file_get_contents($fichier)) : '';
$u = User::where('email', 'admin@mirtv.rahiss.com')->first();
if ($mdp === '' || !$u) {
    echo "MirTV : mot de passe ou administrateur introuvable, rien n'a changé.\n";

    return;
}
$u->password = $mdp;   // chiffré par Paymenter (hash)
$u->save();
DB::table('user_sessions')->where('user_id', $u->id)->delete();   // déconnecte les anciennes sessions
echo "MirTV : mot de passe de admin@mirtv.rahiss.com changé. Connectez-vous sur https://mirtv.rahiss.com/admin\n";

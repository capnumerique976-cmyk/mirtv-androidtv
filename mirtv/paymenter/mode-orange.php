<?php

/**
 * MirTV — change le mode d'Orange Money (simulation, sandbox, production) puis teste les clés.
 * Lancé par brancher-orange.sh : MIRTV_MODE=sandbox php artisan tinker --execute="require '/app/mirtv/mode-orange.php';"
 */

use App\Models\Gateway;
use Paymenter\Extensions\Gateways\OrangeMoney\OrangeMoney;

$mode = getenv('MIRTV_MODE') ?: '';
$g = Gateway::where('extension', 'OrangeMoney')->first();
if (!$g) {
    echo "Orange Money n'est pas installé (lancez installer-paymenter.sh).\n";

    return;
}
if (in_array($mode, ['simulation', 'sandbox', 'production'], true)) {
    $g->settings()->updateOrCreate(['key' => 'mode'], ['value' => $mode]);
    if (getenv('MIRTV_PAYS')) {
        $g->settings()->updateOrCreate(['key' => 'pays'], ['value' => getenv('MIRTV_PAYS')]);
    }
    $g->refresh();
}
$om = new OrangeMoney($g->settings->pluck('value', 'key')->toArray());
echo 'Mode Orange Money : ' . $g->settings->firstWhere('key', 'mode')?->value . "\n";
echo 'Test des clés : ' . $om->tester() . "\n";

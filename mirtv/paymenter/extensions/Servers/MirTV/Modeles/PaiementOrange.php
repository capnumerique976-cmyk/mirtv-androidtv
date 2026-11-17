<?php

namespace Paymenter\Extensions\Servers\MirTV\Modeles;

use App\Models\Invoice;
use Illuminate\Database\Eloquent\Model;

/** Paiement Orange Money (table ext_orange_paiements), lecture seule pour le guichet. */
class PaiementOrange extends Model
{
    protected $table = 'ext_orange_paiements';

    protected $guarded = ['*'];

    protected $hidden = ['pay_token', 'notif_token'];

    protected $casts = ['verified_at' => 'datetime'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}

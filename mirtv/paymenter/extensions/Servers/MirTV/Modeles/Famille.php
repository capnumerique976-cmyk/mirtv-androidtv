<?php

namespace Paymenter\Extensions\Servers\MirTV\Modeles;

use App\Models\Service;
use Illuminate\Database\Eloquent\Model;

/** Famille MirTV (table ext_mirtv_familles), pour les tableaux du guichet. */
class Famille extends Model
{
    protected $table = 'ext_mirtv_familles';

    protected $guarded = ['*'];

    protected $hidden = ['profils', 'jeton_paiement'];

    protected $casts = [
        'code_expire_at' => 'datetime',
        'resiliee_at' => 'datetime',
        'relance_at' => 'datetime',
    ];

    public function service()
    {
        return $this->belongsTo(Service::class);
    }
}

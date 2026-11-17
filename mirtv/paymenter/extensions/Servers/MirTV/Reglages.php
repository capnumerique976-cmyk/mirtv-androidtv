<?php

namespace Paymenter\Extensions\Servers\MirTV;

use App\Models\Gateway;
use App\Models\Server as ServerModel;
use Illuminate\Support\Facades\Cache;

/**
 * MirTV — lecture et écriture des réglages du back office (guichet unique).
 *
 * - Réglages MirTV (aide WhatsApp, annonce, adresses…) : réglages du serveur « MirTV » de Paymenter.
 * - Réglages Orange Money (mode, pays, clés) : réglages de la passerelle « OrangeMoney ».
 *   Les clés sont chiffrées en base avec la clé de l'appli (jamais affichées, jamais dans les journaux).
 */
class Reglages
{
    /** Indicatifs proposés pour le numéro WhatsApp. */
    public const INDICATIFS = [
        '261' => 'Madagascar (+261)',
        '262' => 'Mayotte / La Réunion (+262)',
        '269' => 'Comores (+269)',
        '33' => 'France (+33)',
        '230' => 'Maurice (+230)',
        '248' => 'Seychelles (+248)',
    ];

    /** Clés Orange Money (nom technique => libellé). */
    public const CLES_ORANGE = [
        'orange_client_id' => 'Client ID (identifiant de l\'application)',
        'orange_client_secret' => 'Client Secret (secret de l\'application)',
        'orange_merchant_key' => 'Merchant Key (clé marchand)',
    ];

    public static function serveur(): ?ServerModel
    {
        return ServerModel::where('extension', 'MirTV')->first();
    }

    public static function passerelle(): ?Gateway
    {
        return Gateway::where('extension', 'OrangeMoney')->first();
    }

    /** Un réglage du serveur MirTV. */
    public static function mirtv(string $cle, $defaut = null)
    {
        $s = self::serveur();
        if (!$s) {
            return $defaut;
        }
        $v = $s->settings()->where('key', $cle)->first()?->value;

        return ($v === null || $v === '') ? $defaut : $v;
    }

    /** Enregistre des réglages du serveur MirTV. */
    public static function ecrireMirtv(array $valeurs): void
    {
        $s = self::serveur();
        if (!$s) {
            return;
        }
        foreach ($valeurs as $cle => $v) {
            $type = is_bool($v) ? 'boolean' : (is_int($v) ? 'integer' : 'string');
            $s->settings()->updateOrCreate(['key' => $cle], ['value' => is_bool($v) ? (int) $v : $v, 'type' => $type]);
        }
        Cache::forget('mirtv_reglages_publics');
    }

    /** Un réglage de la passerelle Orange Money (hors clés). */
    public static function orange(string $cle, $defaut = null)
    {
        $g = self::passerelle();
        if (!$g) {
            return $defaut;
        }
        $v = $g->settings()->where('key', $cle)->first()?->value;

        return ($v === null || $v === '') ? $defaut : $v;
    }

    public static function ecrireOrange(array $valeurs): void
    {
        $g = self::passerelle();
        if (!$g) {
            return;
        }
        foreach ($valeurs as $cle => $v) {
            $g->settings()->updateOrCreate(['key' => $cle], ['value' => $v, 'type' => 'string']);
        }
    }

    /** Enregistre une clé Orange chiffrée (valeur vide = rien ne change). */
    public static function ecrireCleOrange(string $nom, ?string $valeur): bool
    {
        $valeur = trim((string) $valeur);
        $g = self::passerelle();
        if (!$g || $valeur === '' || !array_key_exists($nom, self::CLES_ORANGE)) {
            return false;
        }
        $g->settings()->updateOrCreate(['key' => $nom], ['value' => $valeur, 'type' => 'string', 'encrypted' => true]);
        Cache::forget('mirtv_orange_jeton_sandbox');
        Cache::forget('mirtv_orange_jeton_production');

        return true;
    }

    public static function effacerCleOrange(string $nom): void
    {
        self::passerelle()?->settings()->where('key', $nom)->delete();
    }

    /** Clé Orange enregistrée dans le back office (déchiffrée), ou null. */
    public static function cleOrange(string $nom): ?string
    {
        $g = self::passerelle();
        if (!$g) {
            return null;
        }
        $v = $g->settings()->where('key', $nom)->first()?->value;

        return ($v === null || $v === '') ? null : (string) $v;
    }

    /** Où se trouve chaque clé : « back office », « fichier du serveur » ou null. */
    public static function origineCle(string $nom): ?string
    {
        if (self::cleOrange($nom)) {
            return 'back office';
        }
        $dossier = getenv('MIRTV_SECRETS_DIR') ?: '/run/mirtv';

        return is_readable(rtrim($dossier, '/') . '/' . $nom) && trim((string) file_get_contents(rtrim($dossier, '/') . '/' . $nom)) !== '' ? 'fichier du serveur' : null;
    }

    /** Numéro WhatsApp : chiffres seulement, avec l'indicatif (ex. 261341234567). */
    public static function whatsapp(): string
    {
        return preg_replace('/\D/', '', (string) self::mirtv('whatsapp', ''));
    }

    /** Découpe un numéro enregistré en [indicatif, numéro local]. */
    public static function decouper(string $numero): array
    {
        foreach (array_keys(self::INDICATIFS) as $ind) {
            if ($numero !== '' && str_starts_with($numero, (string) $ind)) {
                return [(string) $ind, substr($numero, strlen((string) $ind))];
            }
        }

        return ['261', $numero];
    }

    /** Assemble indicatif + numéro local (enlève le 0 de tête et tout ce qui n'est pas un chiffre). */
    public static function assembler(string $indicatif, string $local): string
    {
        $local = ltrim(preg_replace('/\D/', '', $local), '0');

        return $local === '' ? '' : preg_replace('/\D/', '', $indicatif) . $local;
    }

    /** Affichage lisible : +261 34 12 345 67 */
    public static function lisible(string $numero): string
    {
        if ($numero === '') {
            return '';
        }
        [$ind, $local] = self::decouper($numero);
        // Groupes de chiffres habituels de chaque pays (sinon par deux)
        $groupes = [
            '261' => [2, 2, 3, 2],   // +261 34 12 345 67
            '262' => [3, 2, 2, 2],   // +262 639 12 34 56
            '269' => [3, 2, 2],      // +269 321 23 45
            '33' => [1, 2, 2, 2, 2], // +33 6 12 34 56 78
            '230' => [4, 4],         // +230 5123 4567
            '248' => [1, 3, 3],      // +248 2 123 456
        ][$ind] ?? null;
        if ($groupes && strlen($local) === array_sum($groupes)) {
            $morceaux = [];
            $i = 0;
            foreach ($groupes as $g) {
                $morceaux[] = substr($local, $i, $g);
                $i += $g;
            }

            return '+' . $ind . ' ' . implode(' ', $morceaux);
        }

        return '+' . $ind . ' ' . trim(chunk_split($local, 2, ' '));
    }

    /** Lien WhatsApp (avec le message de départ s'il existe). */
    public static function lienWhatsapp(?string $numero = null): string
    {
        $numero = $numero ?? self::whatsapp();
        if ($numero === '') {
            return '';
        }
        $msg = (string) self::mirtv('whatsapp_message', '');

        return 'https://wa.me/' . $numero . ($msg !== '' ? '?text=' . rawurlencode($msg) : '');
    }

    /** Réglages publics lus par l'appli (aucune donnée sensible). */
    public static function publics(): array
    {
        return Cache::remember('mirtv_reglages_publics', 60, function () {
            $annonce = (bool) self::mirtv('annonce_active', false) && trim((string) self::mirtv('annonce', '')) !== '';

            return [
                'whatsapp' => self::whatsapp(),
                'whatsapp_lisible' => self::lisible(self::whatsapp()),
                'whatsapp_lien' => self::lienWhatsapp(),
                'aide_horaires' => (string) self::mirtv('aide_horaires', ''),
                'aide_langues' => (string) self::mirtv('aide_langues', ''),
                'email_aide' => (string) self::mirtv('email_aide', ''),
                'annonce' => $annonce ? [
                    'texte' => (string) self::mirtv('annonce', ''),
                    'type' => (string) self::mirtv('annonce_type', 'info'),
                ] : null,
            ];
        });
    }
}

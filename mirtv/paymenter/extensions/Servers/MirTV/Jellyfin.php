<?php

namespace Paymenter\Extensions\Servers\MirTV;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Petit client Jellyfin pour MirTV (clé API lue dans un fichier, jamais en base).
 */
class Jellyfin
{
    public function __construct(private string $url) {}

    public static function secret(string $nom): ?string
    {
        $dossier = getenv('MIRTV_SECRETS_DIR') ?: '/run/mirtv';
        $chemin = rtrim($dossier, '/') . '/' . $nom;
        if (!is_readable($chemin)) {
            return null;
        }
        $v = trim((string) file_get_contents($chemin));

        return $v === '' ? null : $v;
    }

    private function entete(?string $jeton = null): string
    {
        $jeton ??= self::secret('jellyfin_api_key');
        if (!$jeton) {
            throw new Exception('Clé API Jellyfin absente');
        }

        return 'MediaBrowser Client="MirTV-compte", Device="compte", DeviceId="mirtv-compte", Version="1.0", Token="' . $jeton . '"';
    }

    public function appel(string $methode, string $chemin, ?array $corps = null, ?string $jeton = null, array $query = [])
    {
        $req = Http::withHeaders(['Authorization' => $this->entete($jeton), 'Accept' => 'application/json'])->timeout(20);
        $adresse = rtrim($this->url, '/') . $chemin . ($query ? '?' . http_build_query($query) : '');
        $r = $corps === null ? $req->send($methode, $adresse) : $req->asJson()->send($methode, $adresse, ['json' => $corps]);
        if ($r->status() === 401) {
            throw new Exception('Jellyfin refuse la clé (401)', 401);
        }
        if (!$r->successful()) {
            throw new Exception('Jellyfin répond ' . $r->status() . ' sur ' . $methode . ' ' . strtok($chemin, '?'));
        }

        return $r->json();
    }

    public function utilisateurs(): array
    {
        return $this->appel('GET', '/Users') ?? [];
    }

    public function parNom(string $nom): ?array
    {
        foreach ($this->utilisateurs() as $u) {
            if (strcasecmp($u['Name'], $nom) === 0) {
                return $u;
            }
        }

        return null;
    }

    public function famille(int $numero): array
    {
        return array_values(array_filter($this->utilisateurs(), fn ($u) => str_starts_with(strtolower($u['Name']), 'f' . $numero . '-')));
    }

    public function politique(string $id): array
    {
        return $this->appel('GET', '/Users/' . $id)['Policy'] ?? [];
    }

    /** Crée un profil avec les droits copiés sur un compte modèle. */
    public function creer(string $nom, string $mdp, array $modele): array
    {
        $u = $this->appel('POST', '/Users/New', ['Name' => $nom, 'Password' => $mdp]);
        $pol = $modele;
        $pol['AccessSchedules'] = array_map(fn ($p) => array_merge($p, ['UserId' => $u['Id']]), $modele['AccessSchedules'] ?? []);
        $pol['IsAdministrator'] = false;
        $pol['IsDisabled'] = false;
        $this->appel('POST', '/Users/' . $u['Id'] . '/Policy', $pol);

        return $u;
    }

    public function activer(string $id, bool $actif): void
    {
        $pol = $this->politique($id);
        $pol['IsDisabled'] = !$actif;
        $this->appel('POST', '/Users/' . $id . '/Policy', $pol);
        if (!$actif) {
            // Les écrans ouverts s'arrêtent : on ferme les sessions de ce profil
            foreach ($this->appel('GET', '/Sessions') ?? [] as $s) {
                if (($s['UserId'] ?? '') === $id) {
                    try {
                        $this->appel('POST', '/Sessions/' . $s['Id'] . '/Playing/Stop');
                    } catch (Exception $e) { /* écran déjà parti */
                    }
                }
            }
        }
    }

    public function supprimer(string $id): void
    {
        $this->appel('DELETE', '/Users/' . $id);
    }

    /** Connexion d'un profil (activation d'un écran) : renvoie le jeton d'accès Jellyfin. */
    public function connecter(string $nom, string $mdp, string $appareil, string $idAppareil): array
    {
        $entete = 'MediaBrowser Client="MirTV Web", Device="' . self::ascii($appareil) . '", DeviceId="' . self::ascii($idAppareil) . '", Version="activation"';
        $r = Http::withHeaders(['Authorization' => $entete, 'Accept' => 'application/json'])->timeout(20)->asJson()
            ->post(rtrim($this->url, '/') . '/Users/AuthenticateByName', ['Username' => $nom, 'Pw' => $mdp]);
        if (!$r->successful()) {
            throw new Exception('Connexion du profil refusée (' . $r->status() . ')');
        }

        return ['id' => $r->json('User.Id'), 'nom' => $r->json('User.Name'), 'jeton' => $r->json('AccessToken')];
    }

    /** Qui est derrière ce jeton d'écran ? (null si le jeton n'est pas valable) */
    public function moi(string $jeton): ?array
    {
        try {
            return $this->appel('GET', '/Users/Me', null, $jeton);
        } catch (Exception $e) {
            return null;
        }
    }

    public static function motDePasse(): string
    {
        return Str::password(32, symbols: false);
    }

    /** Jellyfin refuse les en-têtes non ASCII (accents) */
    public static function ascii(string $s): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', Str::ascii($s)) ?: 'ecran';
    }
}

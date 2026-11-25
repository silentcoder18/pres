<?php

namespace App\Http\Middleware;

use App\Models\Poste;
use App\Models\SessionPoste;
use App\Support\ContexteSync;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reconnait le poste a son jeton (§11, Securite), et la personne a sa session quand c'est sa base
 * qui se synchronise. Un poste inconnu ou revoque, une session qui ne vaut plus (compte desactive,
 * mot de passe ou site change) sont refuses avec un code que l'application de bureau sait
 * interpreter.
 */
class AuthentifierPoste
{
    /** Jeton de la session de la personne, remis au poste a la verification de son compte. */
    public const ENTETE_SESSION = 'X-Pressing-Session';

    public function handle(Request $request, Closure $next): Response
    {
        $jeton = $request->bearerToken();
        $poste = $jeton ? Poste::where('jeton_hash', Poste::empreinte($jeton))->first() : null;

        if ($poste === null) {
            return response()->json([
                'erreur' => 'jeton_invalide',
                'message' => "Ce poste n'est pas reconnu par le serveur.",
            ], 401);
        }

        if ($poste->revoque_le !== null) {
            return response()->json([
                'erreur' => 'poste_revoque',
                'message' => "L'accès de ce poste a été révoqué par le Promoteur.",
            ], 403);
        }

        // La base d'une personne se synchronise sous sa session (decision du 16 octobre 2026) ; sans
        // session, c'est la base de l'ordinateur lui-meme
        $session = null;
        $jetonSession = (string) $request->header(self::ENTETE_SESSION, '');
        if ($jetonSession !== '') {
            $session = SessionPoste::with('utilisateur')
                ->where('jeton_hash', Poste::empreinte($jetonSession))
                ->where('poste_id', $poste->id)
                ->first();
            $motif = $session === null
                ? 'Cette session est inconnue du serveur. Reconnectez-vous.'
                : $session->motifDeRefus();
            if ($motif !== null) {
                return response()->json(['erreur' => 'session_invalide', 'message' => $motif], 401);
            }
            $session->forceFill(['dernier_contact_le' => now()])->saveQuietly();
        }

        $poste->forceFill(['dernier_contact_le' => now()])->saveQuietly();
        $request->attributes->set('poste', $poste);
        $request->attributes->set('session_poste', $session);

        ContexteSync::definir($poste, $session);
        try {
            return $next($request);
        } finally {
            ContexteSync::definir(null);
        }
    }
}

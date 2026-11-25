<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\SyncOperation;
use Illuminate\View\View;

/**
 * Ecritures qu'un poste a envoyees et que le serveur a refusees (§4.3). Chacune est une
 * operation faite sur un site qui n'existe pas dans les comptes du reseau : elle merite un regard.
 */
class EcritureController extends Controller
{
    public function index(): View
    {
        return view('promoteur.ecritures', [
            'ecritures' => SyncOperation::with(['poste.site', 'sessionPoste.site', 'sessionPoste.utilisateur'])
                ->where('resultat', SyncOperation::REJETEE)
                ->orderByDesc('recu_le')
                ->paginate(25),
        ]);
    }
}

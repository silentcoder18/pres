<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\JournalAudit;
use App\Support\Affichage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Journal d'audit : les actions faites sur l'interface web, les plus recentes d'abord. */
class JournalController extends Controller
{
    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'action' => ['nullable', Rule::in(array_keys(Affichage::actions()))],
        ]);

        return view('promoteur.journal', [
            'lignes' => JournalAudit::query()
                ->when($filtres['action'] ?? null, fn (Builder $q, $action) => $q->where('action', $action))
                ->orderByDesc('cree_le')->orderByDesc('id')
                ->paginate(40)->withQueryString(),
            'filtres' => $filtres,
        ]);
    }
}

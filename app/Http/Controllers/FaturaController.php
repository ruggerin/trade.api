<?php

namespace App\Http\Controllers;

use App\Enums\StatusFatura;
use App\Http\Requests\Fatura\StoreFaturaRequest;
use App\Http\Requests\Fatura\UpdateFaturaRequest;
use App\Http\Resources\FaturaResource;
use App\Models\Empresa;
use App\Models\Fatura;
use Illuminate\Http\JsonResponse;

/**
 * Registro manual de cobrança — restrito a SUPERADMIN (middleware da rota). Sem endpoint de
 * delete: fatura errada se corrige editando ou marcando CANCELADA, não excluindo (é registro
 * financeiro).
 */
class FaturaController extends Controller
{
    public function index(Empresa $empresa): JsonResponse
    {
        $faturas = Fatura::where('empresa_id', $empresa->id)
            ->orderByDesc('referencia')
            ->get();

        return response()->json([
            'faturas' => FaturaResource::collection($faturas),
        ]);
    }

    public function store(StoreFaturaRequest $request, Empresa $empresa): JsonResponse
    {
        $fatura = Fatura::create([
            ...$request->validated(),
            'empresa_id' => $empresa->id,
        ]);

        return response()->json([
            'fatura' => new FaturaResource($fatura),
        ], 201);
    }

    public function update(UpdateFaturaRequest $request, Empresa $empresa, Fatura $fatura): JsonResponse
    {
        // Fatura não é tenant-aware por conta própria (sem BelongsToEmpresa, ver App\Models\
        // Fatura) — confirma manualmente que ela pertence mesmo à empresa da rota.
        abort_if($fatura->empresa_id !== $empresa->id, 404);

        $dados = $request->validated();

        // Marcar como paga sem informar a data explicitamente assume "hoje" — evita o
        // SUPERADMIN precisar preencher isso à mão no caso comum.
        if (($dados['status'] ?? null) === StatusFatura::PAGA->value && ! array_key_exists('pago_em', $dados)) {
            $dados['pago_em'] = now()->toDateString();
        }

        $fatura->update($dados);

        return response()->json([
            'fatura' => new FaturaResource($fatura),
        ]);
    }
}

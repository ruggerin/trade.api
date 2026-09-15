<?php

namespace App\Http\Requests\PontoVenda;

use Illuminate\Foundation\Http\FormRequest;

class AtualizarFachadaPontoVendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // user_type/permissão já validados pelo middleware da rota (pontos_venda.gerenciar).
        return true;
    }

    public function rules(): array
    {
        // Mesmo limite de AtualizarFotoUsuarioRequest (8MB) — só uma foto de fachada, não uma
        // prova de campo (essas continuam em StoreVisitaRegistroRequest, limite próprio).
        return [
            'imagem' => ['required', 'file', 'image', 'max:8192'],
        ];
    }
}

<?php

namespace App\Http\Requests\Auth;

use App\Enums\TipoDocumentoLegal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AceitarDocumentosLegaisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'documentos' => ['required', 'array', 'min:1'],
            'documentos.*.tipo' => ['required', 'string', Rule::enum(TipoDocumentoLegal::class)],
            'documentos.*.versao' => ['required', 'string', 'max:20'],
            'dispositivo_identificador' => ['nullable', 'string', 'max:255'],
        ];
    }
}

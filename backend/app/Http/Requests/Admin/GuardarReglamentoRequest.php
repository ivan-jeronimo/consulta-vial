<?php

namespace App\Http\Requests\Admin;

use App\Enums\EstatusVigencia;
use App\Enums\NivelJurisdiccion;
use App\Enums\TipoDocumento;
use App\Models\Municipio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class GuardarReglamentoRequest extends FormRequest
{
    public function rules(): array
    {
        $creando = $this->isMethod('POST');
        $requerido = $creando ? 'required' : 'sometimes';

        return [
            'titulo' => [$requerido, 'string', 'max:255'],
            'tipo' => ['sometimes', Rule::enum(TipoDocumento::class)],
            'nivel' => [$requerido, Rule::enum(NivelJurisdiccion::class)],
            'estado_id' => ['nullable', 'integer', 'exists:estados,id'],
            'municipio_id' => ['nullable', 'integer', 'exists:municipios,id'],
            'anio' => [$requerido, 'integer', 'between:1900,2100'],
            'fecha_publicacion' => ['nullable', 'date'],
            'url_publica' => ['nullable', 'url', 'max:2048'],
            'estatus_vigencia' => ['sometimes', Rule::enum(EstatusVigencia::class)],
            'reemplaza_a_id' => ['nullable', 'integer', 'exists:documentos_reglamentos,id'],

            // Archivo o texto plano, exactamente uno (solo al crear).
            'archivo' => $creando
                ? ['required_without:texto', 'prohibits:texto', 'file', 'max:51200', 'mimes:pdf,docx,txt,md']
                : ['prohibited'],
            'texto' => $creando ? ['required_without:archivo', 'string', 'min:50'] : ['prohibited'],
        ];
    }

    /** Coherencia nivel ↔ estado ↔ municipio (también la exige un CHECK en la BD). */
    public function after(): array
    {
        return [function (Validator $validator) {
            $documento = $this->route('reglamento');
            $nivel = $this->input('nivel', $documento?->nivel?->value);
            $estadoId = $this->has('estado_id') ? $this->input('estado_id') : $documento?->estado_id;
            $municipioId = $this->has('municipio_id') ? $this->input('municipio_id') : $documento?->municipio_id;

            $error = match ($nivel) {
                'federal' => ($estadoId || $municipioId) ? ['nivel', 'Un reglamento federal no lleva estado ni municipio.'] : null,
                'estatal' => (! $estadoId || $municipioId) ? ['estado_id', 'Un reglamento estatal requiere estado y no lleva municipio.'] : null,
                'municipal' => (! $estadoId || ! $municipioId) ? ['municipio_id', 'Un reglamento municipal requiere estado y municipio.'] : null,
                default => null,
            };

            if ($error) {
                $validator->errors()->add(...$error);
            }

            if ($estadoId && $municipioId && ! Municipio::whereKey($municipioId)->where('estado_id', $estadoId)->exists()) {
                $validator->errors()->add('municipio_id', 'El municipio no pertenece al estado indicado.');
            }
        }];
    }
}

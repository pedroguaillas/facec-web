<?php

namespace App\Http\Requests\Product;

use App\Models\Product\ProductDetail;
use App\Models\Product\ProductTemplate;
use App\Models\Product\ProductTemplateField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Alta/edición de una plantilla con todos sus campos (se envía la lista
 * completa; los campos que no vienen se eliminan). Ver docs/caracteristicas-producto.md.
 */
class ProductTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * Si no llega `key`, se deriva del label ("Año modelo" → "ano_modelo").
     */
    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('fields'))) {
            return;
        }

        $fields = collect($this->input('fields'))->map(function ($field, int $index) {
            if (! is_array($field)) {
                return $field;
            }

            if (blank($field['key'] ?? null) && filled($field['label'] ?? null)) {
                $field['key'] = Str::slug((string) $field['label'], '_');
            }

            $field['sort'] ??= $index;

            return $field;
        });

        $this->merge(['fields' => $fields->all()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $template = $this->currentTemplate();

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('product_templates', 'name')
                    ->where('branch_id', $this->branchId())
                    ->ignore($template?->id),
            ],
            'code_prefix' => ['nullable', 'string', 'max:5', 'alpha_num'],
            'code_field_key' => ['nullable', 'string', Rule::in($this->collect('fields')->pluck('key')->filter()->all())],
            'active' => ['sometimes', 'boolean'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.id' => [
                'nullable',
                'integer',
                $template
                    ? Rule::exists('product_template_fields', 'id')->where('product_template_id', $template->id)
                    : 'prohibited',
            ],
            'fields.*.key' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'distinct'],
            'fields.*.label' => ['required', 'string', 'max:100'],
            'fields.*.type' => ['required', Rule::in(ProductTemplateField::TYPES)],
            'fields.*.level' => ['required', Rule::in(ProductTemplateField::LEVELS)],
            'fields.*.required' => ['sometimes', 'boolean'],
            'fields.*.unique' => ['sometimes', 'boolean'],
            'fields.*.in_sri' => ['sometimes', 'boolean'],
            'fields.*.sort' => ['sometimes', 'integer', 'min:0'],
            'fields.*.active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Collection<int, array<string, mixed>> $fields */
                $fields = $this->collect('fields');
                $activeFields = $fields->filter(fn (array $field) => (bool) ($field['active'] ?? true));

                if ($activeFields->filter(fn (array $field) => (bool) ($field['in_sri'] ?? false))->count() > ProductTemplate::MAX_SRI_FIELDS) {
                    $validator->errors()->add('fields', 'Solo '.ProductTemplate::MAX_SRI_FIELDS.' características pueden ir en la información adicional del SRI.');
                }

                $codeFieldKey = $this->input('code_field_key');

                if (filled($codeFieldKey)) {
                    $codeField = $fields->firstWhere('key', $codeFieldKey);

                    if (($codeField['level'] ?? null) !== ProductTemplateField::LEVEL_UNIT
                        || ! ($codeField['unique'] ?? false)
                        || ! ($codeField['active'] ?? true)) {
                        $validator->errors()->add('code_field_key', 'El campo del código debe ser una característica de la unidad, única y activa.');
                    }
                }

                $this->validateFieldsWithValues($validator, $fields);
            },
        ];
    }

    /**
     * Un campo con valores guardados no se elimina (se desactiva) ni cambia
     * de key/nivel: los productos existentes perderían su característica.
     *
     * @param  Collection<int, array<string, mixed>>  $fields
     */
    private function validateFieldsWithValues(Validator $validator, $fields): void
    {
        $template = $this->currentTemplate();

        if (! $template) {
            return;
        }

        $fieldIdsWithValues = ProductDetail::whereIn('product_template_field_id', $template->fields()->pluck('id'))
            ->distinct()
            ->pluck('product_template_field_id');

        $incomingById = $fields->filter(fn (array $field) => filled($field['id'] ?? null))->keyBy('id');

        $template->fields()->whereIn('id', $fieldIdsWithValues)->get()->each(function (ProductTemplateField $field) use ($validator, $incomingById) {
            $incoming = $incomingById->get($field->id);

            if (! $incoming) {
                $validator->errors()->add('fields', "La característica \"{$field->label}\" ya tiene valores registrados: desactívala en lugar de eliminarla.");

                return;
            }

            if ($incoming['key'] !== $field->key || $incoming['level'] !== $field->level) {
                $validator->errors()->add('fields', "La característica \"{$field->label}\" ya tiene valores registrados: no se puede cambiar su clave ni su nivel.");
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una plantilla con ese nombre.',
            'fields.required' => 'La plantilla debe tener al menos una característica.',
            'fields.*.key.distinct' => 'Hay características con la misma clave.',
            'fields.*.key.regex' => 'La clave solo admite minúsculas, números y guion bajo.',
            'code_field_key.in' => 'El campo del código debe ser una de las características de la plantilla.',
        ];
    }

    private function currentTemplate(): ?ProductTemplate
    {
        $template = $this->route('productTemplate');

        return $template instanceof ProductTemplate ? $template : null;
    }

    private function branchId(): ?int
    {
        return $this->user()->company?->branches()->orderBy('created_at')->value('id');
    }
}

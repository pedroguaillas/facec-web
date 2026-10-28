<?php

namespace App\Services\Product;

use App\Models\Product\ProductTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Guarda una plantilla con su lista completa de campos: elimina los que ya
 * no vienen (la request impide eliminar campos con valores registrados),
 * actualiza los que traen id y crea los nuevos.
 */
class ProductTemplateService
{
    /**
     * @param  array<string, mixed>  $data  validated() de ProductTemplateRequest
     */
    public function save(ProductTemplate $template, array $data): ProductTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $template->fill([
                'name' => $data['name'],
                'code_prefix' => filled($data['code_prefix'] ?? null) ? $data['code_prefix'] : null,
                'active' => $data['active'] ?? $template->active ?? true,
            ])->save();

            // Primero se eliminan los que ya no vienen: un campo nuevo puede reusar su key.
            /** @var array<int, array<string, mixed>> $fields */
            $fields = $data['fields'];
            $incomingIds = array_filter(array_column($fields, 'id'));
            $template->fields()->whereNotIn('id', $incomingIds)->delete();

            $fieldIdsByKey = [];

            foreach ($fields as $fieldData) {
                $attributes = [
                    'key' => $fieldData['key'],
                    'label' => $fieldData['label'],
                    'type' => $fieldData['type'],
                    'level' => $fieldData['level'],
                    'required' => $fieldData['required'] ?? false,
                    'unique' => $fieldData['unique'] ?? false,
                    'in_sri' => $fieldData['in_sri'] ?? false,
                    'sort' => $fieldData['sort'] ?? 0,
                    'active' => $fieldData['active'] ?? true,
                ];

                if (filled($fieldData['id'] ?? null)) {
                    $field = $template->fields()->findOrFail((int) $fieldData['id']);
                    $field->update($attributes);
                } else {
                    $field = $template->fields()->create($attributes);
                }

                $fieldIdsByKey[$field->key] = $field->id;
            }

            $codeFieldKey = $data['code_field_key'] ?? null;

            $template->update([
                'code_field_id' => filled($codeFieldKey) ? $fieldIdsByKey[$codeFieldKey] : null,
            ]);

            return $template->load('fields');
        });
    }
}

{{-- Características del producto bajo la descripción de la línea (todas, no solo las del XML). --}}
@if(! empty($details))
    <div style="font-size: 0.85em; color: #555;">
        {{ collect($details)->sortBy('sort')->map(fn ($detail) => $detail['label'].': '.$detail['value'])->implode(' | ') }}
    </div>
@endif

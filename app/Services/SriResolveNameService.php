<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SriResolveNameService
{
    public function searchByIdentificationSRI(string $identification)
    {
        $url = config('services.sri.url');

        $isRUC = strlen($identification) === 13;

        /** @var Response $response */
        $response = Http::timeout(5)
            ->retry(2, 200)
            ->get($url, [
                'numeroIdentificacion' => $identification,
                'tipoIdentificacion' => $isRUC ? 'R' : 'C',
            ]);

        if ($response->failed()) {
            abort($response->status(), 'Error al conectar con el servicio externo del SRI.');
        }

        if (empty($response['nombreCompleto'])) {
            abort(404, 'RUC no encontrado en los registros del SRI.');
        }

        return [
            'name' => trim($response['nombreCompleto']),
        ];
    }
}

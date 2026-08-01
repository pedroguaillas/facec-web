<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanyUpdateRequest;
use App\Models\Company;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    public function edit(): JsonResponse
    {
        $company = Auth::user()->company;

        return response()->json([
            'succes' => true,
            'company' => $this->present($company),
        ], 200);
    }

    public function update(CompanyUpdateRequest $request): JsonResponse
    {
        $company = Auth::user()->company;

        $data = $request->safe()->except(['logo', 'cert', 'pass_cert', 'sign_valid_from', 'sign_valid_to']);

        if ($request->hasFile('logo')) {
            $filename = $company->ruc.'.'.$request->file('logo')->getClientOriginalExtension();
            $request->file('logo')->storeAs('logos', $filename, 'public');
            $data['logo_dir'] = $filename;
        }

        if ($request->hasFile('cert')) {
            $certContent = file_get_contents($request->file('cert')->getRealPath());
            $parsed = [];

            if (! openssl_pkcs12_read($certContent, $parsed, $request->input('pass_cert'))) {
                return response()->json([
                    'succes' => false,
                    'message' => 'No se pudo leer el certificado. Verificá el archivo y la contraseña.',
                ], 422);
            }

            $certData = openssl_x509_parse($parsed['cert']);

            $filename = $company->ruc.'.p12';
            $request->file('cert')->storeAs('cert', $filename);

            $data['cert_dir'] = $filename;
            $data['pass_cert'] = $request->input('pass_cert');
            $data['sign_valid_from'] = Carbon::createFromTimestamp($certData['validFrom_time_t']);
            $data['sign_valid_to'] = Carbon::createFromTimestamp($certData['validTo_time_t']);
        }

        $company->update($data);

        return response()->json([
            'succes' => true,
            'message' => 'Empresa actualizada exitosamente.',
            'company' => $this->present($company->fresh()),
        ], 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Company $company): array
    {
        $data = $company->toArray();

        $data['logo_url'] = $company->logo_dir
            ? Storage::disk('public')->url('logos/'.$company->logo_dir)
            : null;
        $data['has_cert'] = (bool) $company->cert_dir;

        return $data;
    }
}

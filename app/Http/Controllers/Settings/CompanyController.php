<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\CompanyUpdateRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CompanyController extends Controller
{
    public function edit(): JsonResponse
    {
        $company = Auth::user()->company;

        return response()->json($this->present($company), 200);
    }

    public function downloadCert(): JsonResponse
    {
        $company = Auth::user()->company;

        if (! $company->cert_dir) {
            return response()->json([
                'message' => 'Certificado no encontrado.',
            ], 404);
        }

        return response()->json([
            'cert' => base64_encode(Storage::get('cert/'.$company->cert_dir)),
        ]);
    }

    public function update(CompanyUpdateRequest $request): JsonResponse
    {
        $company = Auth::user()->company;

        $data = $request->safe()->only(['accounting', 'rimpe', 'retention_agent']);

        if ($request->hasFile('logo')) {
            $filename = $company->ruc.'.'.$request->file('logo')->getClientOriginalExtension();
            $request->file('logo')->storeAs('logos', $filename, 'public');
            $data['logo_dir'] = $filename;
        }

        if ($request->hasFile('cert')) {
            $filename = $company->ruc.'.p12';
            $request->file('cert')->storeAs('cert', $filename);

            $data['cert_dir'] = $filename;
            $data['pass_cert'] = $request->input('pass_cert');
            $data['sign_valid_from'] = $request->input('sign_valid_from');
            $data['sign_valid_to'] = $request->input('sign_valid_to');
        }

        if ($request->has('accounting')) {
            $data['accounting'] = filter_var($request->input('accounting'), FILTER_VALIDATE_BOOLEAN);
        }

        $company->update($data);

        return response()->json($this->present($company->fresh()), 200);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Company $company): array
    {
        $data = $company->toArray();
        unset($data['laravel_through_key']);

        $data['logo_url'] = $company->logo_dir
            ? Storage::disk('public')->url('logos/'.$company->logo_dir)
            : null;
        $data['has_cert'] = (bool) $company->cert_dir;

        return $data;
    }
}

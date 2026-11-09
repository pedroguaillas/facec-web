<?php

namespace App\Services;

use App\Models\Company;
use App\StaticClasses\VoucherStates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class VoucherLifecycleService
{
    /** Detalle que se deja en el comprobante cuando la firma electrónica está caducada. */
    public const EXPIRED_SIGNATURE_DETAIL = 'FIRMA ELECTRÓNICA CADUCADA. Actualice el certificado de la empresa.';

    public function __construct(private XmlSignerService $signer) {}

    /**
     * Guarda el XML, lo firma y actualiza el modelo.
     *
     * @param  Model  $model  Modelo Eloquent a actualizar (Order, Shop, ReferralGuide…)
     * @param  string  $xml  Contenido XML sin firmar
     * @param  string  $dateField  Campo del modelo que contiene la fecha (para la ruta de carpetas)
     * @param  string  $xmlField  Campo del modelo donde se guarda el path del XML
     * @param  string|null  $stateField  Campo del modelo donde se guarda el estado del voucher
     * @param  string|null  $extraDetailField  Campo del modelo a limpiar tras guardar (null = no limpiar)
     * @param  string|null  $authorizationField  Campo del modelo donde se guarda la clave de acceso
     * @param  callable|null  $onSigned  Callback a ejecutar cuando la firma es exitosa
     */
    public function saveAndSign(
        Company $company,
        Model $model,
        string $xml,
        string $dateField = 'date',
        string $xmlField = 'xml',
        ?string $stateField = 'state',
        ?string $extraDetailField = 'extra_detail',
        ?string $authorizationField = null,
        ?callable $onSigned = null,
    ): void {
        $accessKey = substr($xml, strpos($xml, '<claveAcceso>') + 13, 49);
        $filename = "{$accessKey}.xml";
        $date = new \DateTime($model->{$dateField});

        $rootPath = implode(DIRECTORY_SEPARATOR, [
            'xmls',
            $company->ruc,
            $date->format('Y'),
            $date->format('m'),
        ]);

        $savedPath = $rootPath.DIRECTORY_SEPARATOR.VoucherStates::SAVED.DIRECTORY_SEPARATOR.$filename;

        Storage::put($savedPath, $xml);

        if (! file_exists(Storage::path($savedPath))) {
            return;
        }

        $model->{$xmlField} = $savedPath;

        if ($authorizationField) {
            $model->{$authorizationField} = $accessKey;
        }

        if ($extraDetailField) {
            $model->{$extraDetailField} = null;
        }

        $model->save();

        if ($company->cert_dir === null) {
            return;
        }

        if ($company->isSignatureExpired()) {
            Log::warning('Firma electrónica caducada, no se firma el comprobante', [
                'company_id' => $company->id,
                'sign_valid_to' => $company->sign_valid_to?->toDateTimeString(),
                'filename' => $filename,
            ]);

            if ($extraDetailField) {
                $model->{$extraDetailField} = self::EXPIRED_SIGNATURE_DETAIL;
                $model->save();
            }

            return;
        }

        // El disco 'local' ya rootea en storage/app/private (default de Laravel 11+),
        // por eso acá NO se antepone 'private' de nuevo.
        $certPath = Storage::path(implode(DIRECTORY_SEPARATOR, ['cert', $company->cert_dir]));
        $signedDir = $rootPath.DIRECTORY_SEPARATOR.VoucherStates::SIGNED;

        if (! Storage::exists($signedDir)) {
            Storage::makeDirectory($signedDir);
        }

        $signed = $this->signer->sign(
            certPath: $certPath,
            password: $company->pass_cert,
            inputPath: Storage::path($savedPath),
            outputDir: Storage::path($signedDir),
            filename: $filename,
        );

        if (! $signed) {
            return;
        }

        $model->{$xmlField} = $signedDir.DIRECTORY_SEPARATOR.$filename;

        if ($stateField) {
            $model->{$stateField} = VoucherStates::SIGNED;
        }

        $model->save();

        Storage::delete($savedPath);

        if ($onSigned) {
            ($onSigned)();
        }
    }
}

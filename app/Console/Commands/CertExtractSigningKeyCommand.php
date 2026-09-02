<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Cert\Pkcs12BagInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('cert:extract-signing-key {company : ID de la company} {--key-id= : localKeyID (hex, con o sin espacios/":") de la llave a extraer — ver cert:inspect} {--apply : Reemplaza el .p12 real (con backup previo); sin esta flag solo escribe el .fixed.p12 para revisar}')]
#[Description('Reconstruye un .p12 con exactamente 1 key bag + su certificado emparejado, a partir de un .p12 con varias llaves (p.ej. certificados del Banco Central del Ecuador, que separan Signing Key de Decryption Key). Nunca sobrescribe el original salvo --apply, y siempre deja backup.')]
class CertExtractSigningKeyCommand extends Command
{
    public function handle(Pkcs12BagInspector $inspector): int
    {
        $company = Company::find($this->argument('company'));

        if (! $company) {
            $this->error('Company no encontrada.');

            return self::FAILURE;
        }

        if (! $company->cert_dir) {
            $this->error('La company no tiene cert_dir configurado.');

            return self::FAILURE;
        }

        $keyIdOption = $this->option('key-id');

        if (! $keyIdOption) {
            $this->error("Falta --key-id. Corré primero: php artisan cert:inspect {$company->id}");

            return self::FAILURE;
        }

        $wantedKeyId = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $keyIdOption));

        $path = storage_path("app/private/cert/{$company->cert_dir}");

        if (! is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $dump = Process::run([
            'openssl', 'pkcs12', '-in', $path, '-nodes', '-legacy',
            '-passin', "pass:{$company->pass_cert}",
        ]);

        if ($dump->failed()) {
            $this->error('openssl no pudo leer el certificado:');
            $this->line($dump->errorOutput());

            return self::FAILURE;
        }

        $bags = $inspector->parse($dump->output());

        $keyBag = collect($bags)->first(fn ($b) => $b['type'] === 'key' && $b['local_key_id'] === $wantedKeyId);
        $certBag = collect($bags)->first(fn ($b) => $b['type'] === 'cert' && $b['local_key_id'] === $wantedKeyId);

        if (! $keyBag) {
            $this->error("No se encontró ninguna llave con localKeyID {$wantedKeyId}. Corré cert:inspect {$company->id} para ver los disponibles.");

            return self::FAILURE;
        }

        if (! $certBag) {
            $this->error("Se encontró la llave pero ningún certificado con el mismo localKeyID {$wantedKeyId} — no se puede armar el par.");

            return self::FAILURE;
        }

        $tmpDir = storage_path('app/private/cert-tmp');

        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0700, true);
        }

        $tmpPem = tempnam($tmpDir, 'pair_');
        $tmpP12 = tempnam($tmpDir, 'fixed_');

        try {
            file_put_contents($tmpPem, $keyBag['pem'].$certBag['pem']);

            $export = Process::run([
                'openssl', 'pkcs12', '-export',
                '-in', $tmpPem,
                '-out', $tmpP12,
                '-name', 'cert',
                '-passin', "pass:{$company->pass_cert}",
                '-passout', "pass:{$company->pass_cert}",
            ]);

            if ($export->failed()) {
                $this->error('Falló el reexport:');
                $this->line($export->errorOutput());

                return self::FAILURE;
            }

            $verify = Process::run([
                'openssl', 'pkcs12', '-in', $tmpP12, '-nodes', '-legacy',
                '-passin', "pass:{$company->pass_cert}",
            ]);

            if ($verify->failed()) {
                $this->error('El .p12 generado no pasa la verificación con openssl:');
                $this->line($verify->errorOutput());

                return self::FAILURE;
            }

            $foundKeyBags = collect($inspector->parse($verify->output()))
                ->where('type', 'key')
                ->count();

            if ($foundKeyBags !== 1) {
                $this->error("Verificación falló: el .p12 generado tiene {$foundKeyBags} key bag(s), se esperaba exactamente 1.");

                return self::FAILURE;
            }

            $fixedPath = "{$path}.fixed.p12";
            copy($tmpP12, $fixedPath);

            $this->info('OK — .p12 limpio (1 key bag) escrito en:');
            $this->line("  {$fixedPath}");

            if (! $this->option('apply')) {
                $this->line('Dry run — revisá el archivo. Corré de nuevo con --apply para reemplazar el certificado real (se hace backup automático primero).');

                return self::SUCCESS;
            }

            if (! $this->confirm("¿Reemplazar {$path} por la versión limpia? Se hace backup antes.", false)) {
                $this->line('Cancelado — el .fixed.p12 queda disponible para revisar.');

                return self::SUCCESS;
            }

            $backupPath = "{$path}.bak-".now()->format('Ymd-His').'.p12';
            copy($path, $backupPath);
            $this->info("Backup del cert actual: {$backupPath}");

            copy($fixedPath, $path);
            $this->info("Aplicado — {$path} reemplazado.");

            return self::SUCCESS;
        } finally {
            @unlink($tmpPem);
            @unlink($tmpP12);
        }
    }
}

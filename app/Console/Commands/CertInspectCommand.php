<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Cert\Pkcs12BagInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

#[Signature('cert:inspect {company : ID de la company}')]
#[Description('Lista los bags (llaves/certificados) del .p12 de firma electrónica de una company: friendlyName, localKeyID y vencimiento de cada uno. Solo lectura, no modifica nada.')]
class CertInspectCommand extends Command
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

        if ($bags === []) {
            $this->error('No se encontró ningún bag en el archivo.');

            return self::FAILURE;
        }

        $rows = [];
        $keyCount = 0;

        foreach ($bags as $i => $bag) {
            if ($bag['type'] === 'key') {
                $keyCount++;
            }

            $rows[] = [
                $i + 1,
                $bag['type'] === 'key' ? 'LLAVE' : 'CERT',
                $bag['local_key_id'] ?? '—',
                Str::limit($bag['friendly_name'] ?? '—', 55),
                $bag['type'] === 'cert' ? ($inspector->certEndDate($bag['pem']) ?? '—') : '—',
            ];
        }

        $this->table(['#', 'Tipo', 'localKeyID', 'friendlyName', 'Vence'], $rows);

        if ($keyCount > 1) {
            $this->warn("Este .p12 tiene {$keyCount} llaves privadas — go-signer (go-pkcs12) solo acepta exactamente una.");
            $this->warn('Identificá el localKeyID de la llave de FIRMA (friendlyName suele decir "Signing Key" o similar) y corré:');
            $this->line("  php artisan cert:extract-signing-key {$company->id} --key-id=<localKeyID>");
        } else {
            $this->info('Un solo key bag — estructura compatible con go-signer.');
        }

        return self::SUCCESS;
    }
}

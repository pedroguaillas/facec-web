<?php

namespace App\Services\Cert;

use Illuminate\Support\Facades\Process;

class Pkcs12BagInspector
{
    /**
     * Parsea la salida de texto de `openssl pkcs12 -in ... -nodes` (con Bag
     * Attributes, sin -noout) en una lista de bags individuales.
     *
     * @return array<int, array{type: 'key'|'cert', friendly_name: ?string, local_key_id: ?string, subject: ?string, pem: string}>
     */
    public function parse(string $opensslOutput): array
    {
        $bags = [];
        $lines = preg_split('/\r?\n/', $opensslOutput) ?: [];

        $friendlyName = null;
        $localKeyId = null;
        $subject = null;
        $pemLines = [];
        $capturingPem = false;
        $pemType = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'Bag Attributes')) {
                $friendlyName = null;
                $localKeyId = null;
                $subject = null;

                continue;
            }

            if (preg_match('/^\s*friendlyName:\s*(.*)$/', $line, $m)) {
                $friendlyName = trim($m[1]);

                continue;
            }

            if (preg_match('/^\s*localKeyID:\s*(.*)$/', $line, $m)) {
                $localKeyId = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $m[1]));

                continue;
            }

            if (str_starts_with($line, 'subject=')) {
                $subject = trim(substr($line, strlen('subject=')));

                continue;
            }

            if (preg_match('/^-----BEGIN (PRIVATE KEY|CERTIFICATE)-----$/', $line, $m)) {
                $capturingPem = true;
                $pemType = $m[1];
                $pemLines = [$line];

                continue;
            }

            if (! $capturingPem) {
                continue;
            }

            $pemLines[] = $line;

            if (preg_match('/^-----END (PRIVATE KEY|CERTIFICATE)-----$/', $line)) {
                $capturingPem = false;

                $bags[] = [
                    'type' => $pemType === 'PRIVATE KEY' ? 'key' : 'cert',
                    'friendly_name' => $friendlyName,
                    'local_key_id' => $localKeyId,
                    'subject' => $subject,
                    'pem' => implode("\n", $pemLines)."\n",
                ];

                $friendlyName = null;
                $localKeyId = null;
                $subject = null;
            }
        }

        return $bags;
    }

    /**
     * Fecha de vencimiento (notAfter) de un bloque PEM de certificado.
     */
    public function certEndDate(string $certPem): ?string
    {
        $result = Process::input($certPem)->run(['openssl', 'x509', '-noout', '-enddate']);

        if ($result->failed()) {
            return null;
        }

        return trim(str_replace('notAfter=', '', $result->output()));
    }
}

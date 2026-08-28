<?php

namespace App\Jobs;

use App\Models\Company;
use App\StaticClasses\VoucherJobRegistry;
use App\StaticClasses\VoucherStates;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessVoucherJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 30;

    public int $uniqueFor = 900;

    /** @var array<int, int> */
    private const BACKOFF = [30, 60, 120, 180, 300, 300, 600, 600];

    public function __construct(
        private readonly string $voucherType,
        private readonly int $modelId,
        private readonly int $companyId,
    ) {}

    public function uniqueId(): string
    {
        return "voucher:{$this->voucherType}:{$this->modelId}";
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(30)->expireAfter(900)];
    }

    public function handle(): void
    {
        $config = VoucherJobRegistry::TYPES[$this->voucherType] ?? null;

        if (! $config) {
            Log::error('ProcessVoucherJob: tipo desconocido', ['type' => $this->voucherType]);

            return;
        }

        // withoutGlobalScope('branch'): el Job corre sin usuario autenticado, así que
        // App\BranchScope no tiene de dónde resolver company_id y filtraría todo; se
        // evita el ambiguo y se busca directo por PK (ya se conoce el id concreto).
        $model = $config['model']::withoutGlobalScope('branch')->find($this->modelId);
        $company = Company::find($this->companyId);

        if (! $model || ! $company) {
            Log::warning('ProcessVoucherJob: recurso no encontrado', [
                'type' => $this->voucherType,
                'model_id' => $this->modelId,
                'company_id' => $this->companyId,
            ]);

            return;
        }

        $stateField = $config['state'];

        if (in_array($model->{$stateField}, VoucherStates::FINAL_STATES, true)) {
            return;
        }

        app($config['service'])->process($model, $company);
        $model->refresh();

        if ($this->attempts() < $this->tries && $this->stillPending($model->{$stateField})) {
            $this->release(self::BACKOFF[min($this->attempts() - 1, count(self::BACKOFF) - 1)]);
        }
    }

    /**
     * Cualquier estado no final es candidato a reintento — incluye SAVED/SIGNED/SENDED/
     * RECEIVED/IN_PROCESS, pero también RETURNED y REJECTED: process() los trata como
     * "reconstruir y reenviar" (ver el match de estado en cada *LifecycleService), así
     * que el Job debe seguir reintentando ahí también, no solo en los estados "en curso".
     */
    private function stillPending(?string $state): bool
    {
        return ! in_array($state, VoucherStates::FINAL_STATES, true);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessVoucherJob failed', [
            'type' => $this->voucherType,
            'model_id' => $this->modelId,
            'message' => $exception->getMessage(),
        ]);
    }
}

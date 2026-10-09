<?php

namespace App\Services;

use App\Mail\VerifyEmail;
use App\Models\Company;
use App\Models\User;
use App\Models\UserType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Registro público "Prueba gratis": crea empresa, usuario dueño, sucursal y
 * punto 001 y el plan GRATUITO (1 mes), y envía el correo de verificación.
 * El usuario no puede iniciar sesión hasta confirmar su correo.
 */
class RegistrationService
{
    public function __construct(
        private SriResolveNameService $sriService,
        private BranchService $branchService,
        private EmisionPointService $emisionPointService,
        private SubscriptionService $subscriptionService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  Validado por RegisterRequest: ruc, address, phone?, user, email, password.
     */
    public function register(array $data): User
    {
        $companyName = $this->resolveCompanyName($data['ruc']);

        $user = DB::transaction(function () use ($data, $companyName) {
            $company = Company::create([
                'ruc' => $data['ruc'],
                'company' => $companyName,
                'economic_activity' => 'otros',
                'phone' => $data['phone'] ?? null,
                'enviroment_type' => 2,
                'decimal' => 6,
            ]);

            $user = User::create([
                'name' => $companyName,
                'user' => $data['user'],
                'email' => $data['email'],
                'password' => $data['password'],
                'user_type_id' => UserType::firstOrCreate(['type' => 'client'])->id,
            ]);

            $user->companyusers()->create([
                'level' => 'owner',
                'level_id' => $company->id,
            ]);

            $branch = $this->branchService->create([
                'company_id' => $company->id,
                'store' => 1,
                'address' => $data['address'],
                'name' => $companyName,
                'type' => 'matriz',
            ]);
            $this->branchService->createFinalConsumer($branch);
            $this->emisionPointService->checkAndCreateFirstPoint($company);

            $this->subscriptionService->assignTrial($company);

            return $user;
        });

        Mail::to($user->email)->send(new VerifyEmail($user));

        return $user;
    }

    /**
     * La razón social sale del SRI, no del formulario.
     *
     * @throws ValidationException
     */
    public function resolveCompanyName(string $ruc): string
    {
        try {
            return $this->sriService->searchByIdentificationSRI($ruc)['name'];
        } catch (HttpExceptionInterface $exception) {
            throw ValidationException::withMessages([
                'ruc' => $exception->getStatusCode() === 404
                    ? 'RUC no encontrado en los registros del SRI.'
                    : 'No pudimos consultar el SRI en este momento. Intenta de nuevo en unos minutos.',
            ]);
        }
    }
}

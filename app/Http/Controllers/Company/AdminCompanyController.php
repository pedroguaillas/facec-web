<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\AssignSubscriptionRequest;
use App\Http\Requests\Company\CompanyStoreRequest;
use App\Http\Requests\Company\CompanyUpdateRequest;
use App\Models\Company;
use App\Models\User;
use App\Services\CompanyService;
use App\Services\SubscriptionService;
use App\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminCompanyController extends Controller
{
    public function __construct(
        private UserService $userService,
        private CompanyService $companyService,
        private SubscriptionService $subscriptionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companies = $this->companyService->getAll(
            $request->query('search'),
            (int) $request->query('per_page', 15)
        );

        return response()->json($companies);
    }

    public function store(CompanyStoreRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $companyData = $request->safe()->except(['user', 'password', 'email', 'user_type_id', 'plan', 'plan_ends_at', 'plan_amount']);
            $userData = $request->safe()->only(['user', 'password', 'email', 'user_type_id']);

            $company = $this->companyService->create($companyData);
            $user = $this->userService->create($userData);
            // El admin responde por el correo: no pasa por la verificación del registro público.
            $user->forceFill(['email_verified_at' => now()])->save();

            $subscription = $this->subscriptionService->assignPlan(
                $company,
                $request->validated('plan'),
                $request->validated('plan_ends_at'),
                $request->filled('plan_amount') ? (float) $request->validated('plan_amount') : null,
            );

            $user->companyusers()->create([
                'level' => 'owner',
                'level_id' => $company->id,
            ]);

            return response()->json([
                'message' => 'Registro de empresa exitoso.',
                'user' => $user,
                'company' => $company,
                'subscription' => $subscription,
            ], 201);
        });
    }

    public function resolve(string $identification): JsonResponse
    {
        $company = Company::where('ruc', $identification)->first();

        if ($company) {
            return response()->json([
                'registered' => true,
                'company' => $company,
            ]);
        }

        return response()->json([
            'registered' => false,
            ...$this->companyService->searchByIdentificationSRI($identification),
        ]);
    }

    public function checkAvailability(Request $request): JsonResponse
    {
        $user = $request->query('user');
        $email = $request->query('email');

        $emailValid = $email
            ? Validator::make(['email' => $email], ['email' => 'email:rfc,dns'])->passes()
            : false;

        return response()->json([
            'succes' => true,
            'user' => ['registered' => $user ? User::where('user', $user)->exists() : false],
            'email' => [
                'valid' => $emailValid,
                'registered' => $emailValid ? User::where('email', $email)->exists() : false,
            ],
        ]);
    }

    public function edit(int $id): JsonResponse
    {
        return response()->json($this->companyService->findByAttributes(['id' => $id]));
    }

    public function update(CompanyUpdateRequest $request, Company $company): JsonResponse
    {
        $updatedCompany = $this->companyService->update($company, $request->validated());

        return response()->json($updatedCompany);
    }

    public function subscriptions(Company $company): JsonResponse
    {
        return response()->json([
            'company' => $company->only(['id', 'ruc', 'company']),
            'current' => $company->currentSubscription(),
            'history' => $company->subscriptions()
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function assignSubscription(AssignSubscriptionRequest $request, Company $company): JsonResponse
    {
        $subscription = $this->subscriptionService->assignPlan(
            $company,
            $request->validated('plan'),
            $request->validated('ends_at'),
            $request->filled('amount') ? (float) $request->validated('amount') : null,
        );

        return response()->json($subscription, 201);
    }
}

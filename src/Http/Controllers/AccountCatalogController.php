<?php

declare(strict_types=1);

namespace Karnoweb\Accounting\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Karnoweb\Accounting\Services\AccountService;

/**
 * Host route contract:
 *   GET /accounts/hierarchy
 *   GET /accounts
 *
 * Disabled unless `accounting.routes.enabled` is true.
 */
final class AccountCatalogController
{
    public function __construct(
        private readonly AccountService $accounts,
    ) {}

    public function hierarchy(Request $request): JsonResponse
    {
        return response()->json(
            $this->accounts->hierarchy($request->all())->toArray()
        );
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(
            $this->accounts->paginate($request->all())->toArray()
        );
    }
}

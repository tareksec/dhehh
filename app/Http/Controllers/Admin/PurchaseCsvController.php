<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Services\PurchaseCsvImportService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseCsvController extends Controller
{
    public function sample(Request $request, PurchaseCsvImportService $purchases): StreamedResponse
    {
        abort_unless($request->user()?->canPerformModelAbility('create', Purchase::class), 403);

        return $purchases->sample();
    }
}

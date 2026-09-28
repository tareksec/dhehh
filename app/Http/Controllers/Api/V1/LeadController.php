<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Website inquiry submission -> ERP CRM "query list". Lands on the existing
 * Lead model with source='website' (already one of Lead::SOURCES), so no
 * new CRM concept was needed for this. An optional external_reference (the
 * website's own quote id) makes a retry return the existing lead, and is
 * what the lead.status_updated webhook echoes back (see Lead::booted()).
 */
class LeadController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'external_reference' => ['nullable', 'string', 'max:190'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'interest' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $externalReference = filled($data['external_reference'] ?? null) ? $data['external_reference'] : null;

        if ($externalReference !== null) {
            $existing = Lead::query()->where('external_reference', $externalReference)->first();

            if ($existing) {
                return response()->json(['data' => $this->transform($existing)]);
            }
        }

        $lead = Lead::query()->create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'source' => 'website',
            'external_reference' => $externalReference,
            'status' => 'new',
            'interest' => $data['interest'] ?? null,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $this->transform($lead)], 201);
    }

    protected function transform(Lead $lead): array
    {
        return [
            'id' => $lead->getKey(),
            'external_reference' => $lead->external_reference,
            'status' => $lead->status,
            'created_at' => $lead->created_at?->toIso8601String(),
        ];
    }
}

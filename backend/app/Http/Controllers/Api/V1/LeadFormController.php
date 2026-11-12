<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Models\Organization;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LeadFormController extends Controller
{
    public function __construct(private LeadIngestionService $ingestion) {}

    public function index(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.forms'), 403);
        return response()->json(['data' => LeadForm::where('organization_id', $organization->id)->latest()->get()]);
    }

    public function store(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('leads.forms'), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'property_reference' => ['nullable', 'string', 'max:160'],
            'intent' => ['nullable', 'in:buy,sell,rent,invest'],
            'success_message' => ['nullable', 'string', 'max:300'],
        ]);
        $form = LeadForm::create(array_merge($data, [
            'organization_id' => $organization->id,
            'public_key' => Str::random(40),
            'success_message' => $data['success_message'] ?? 'Thank you! Our team will contact you shortly.',
        ]));
        return response()->json(['data' => $form], 201);
    }

    public function update(Request $request, Organization $organization, LeadForm $form)
    {
        abort_unless($form->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.forms'), 403);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'property_reference' => ['nullable', 'string', 'max:160'],
            'intent' => ['nullable', 'in:buy,sell,rent,invest'],
            'success_message' => ['nullable', 'string', 'max:300'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $form->update($data);
        return response()->json(['data' => $form]);
    }

    public function destroy(Request $request, Organization $organization, LeadForm $form)
    {
        abort_unless($form->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('leads.forms'), 403);
        $form->delete();
        return response()->json(['data' => ['message' => 'Form deleted.']]);
    }

    /** Public form metadata for the hosted/embeddable form (no private data). */
    public function publicShow(string $publicKey)
    {
        $form = LeadForm::where('public_key', $publicKey)->where('is_active', true)->firstOrFail();
        return response()->json(['data' => [
            'name' => $form->name,
            'property_reference' => $form->property_reference,
            'intent' => $form->intent,
            'fields' => ['full_name', 'phone', 'whatsapp', 'email', 'city', 'preferred_location', 'budget_min', 'budget_max', 'message'],
        ]]);
    }

    /**
     * Public submission endpoint. Spam controls: honeypot field, per-IP
     * throttle (route middleware), validation, and dedupe on ingest.
     */
    public function publicSubmit(Request $request, string $publicKey)
    {
        if ($request->filled('website')) {
            // Honeypot tripped — pretend success, store nothing.
            return response()->json(['data' => ['message' => 'Thank you!']]);
        }
        $form = LeadForm::where('public_key', $publicKey)->where('is_active', true)->firstOrFail();
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'city' => ['nullable', 'string', 'max:120'],
            'preferred_location' => ['nullable', 'string', 'max:190'],
            'budget_min' => ['nullable', 'numeric', 'min:0'],
            'budget_max' => ['nullable', 'numeric', 'min:0'],
            'message' => ['nullable', 'string', 'max:2000'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:160'],
            'utm_term' => ['nullable', 'string', 'max:120'],
            'utm_content' => ['nullable', 'string', 'max:120'],
            'landing_page' => ['nullable', 'string', 'max:500'],
            'consent_whatsapp' => ['sometimes', 'boolean'],
        ]);
        if (empty($data['phone']) && empty($data['email'])) {
            return response()->json(['error' => ['code' => 'contact_required', 'message' => 'Please provide a phone number or an email.']], 422);
        }
        $organization = $form->organization()->firstOrFail();
        $payload = array_merge($data, [
            'property_reference' => $form->property_reference,
            'intent' => $form->intent,
            'notes' => $data['message'] ?? null,
        ]);
        unset($payload['message']);
        $this->ingestion->ingest($organization, $payload, 'website_form', null, ['form_id' => $form->id, 'form_name' => $form->name]);
        $form->increment('submissions_count');
        return response()->json(['data' => ['message' => $form->success_message]]);
    }
}

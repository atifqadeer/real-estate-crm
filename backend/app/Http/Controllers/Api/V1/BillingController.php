<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BillingInvoice;
use App\Models\ClientSubscription;
use App\Models\Organization;
use App\Models\ServicePackage;
use App\Services\AuditLogger;
use App\Services\Billing\PlanLimitService;
use Illuminate\Http\Request;

/**
 * Packages are owned by the agency organization; subscriptions and
 * invoices belong to client organizations. Billing here is invoicing +
 * manual payment recording (bank/JazzCash/EasyPaisa/cash) — no payment
 * gateway is simulated.
 */
class BillingController extends Controller
{
    public function __construct(private PlanLimitService $plans, private AuditLogger $audit) {}

    // ---- Packages (route org = agency) ----
    public function packages(Request $request, Organization $organization)
    {
        abort_unless($organization->type === 'agency', 404);
        abort_unless($request->user()->can('billing.view'), 403);
        return response()->json(['data' => ServicePackage::where('organization_id', $organization->id)->latest()->get()]);
    }

    public function packageStore(Request $request, Organization $organization)
    {
        abort_unless($organization->type === 'agency', 404);
        abort_unless($request->user()->can('billing.manage'), 403);
        $data = $this->validatePackage($request);
        $package = ServicePackage::create(array_merge($data, ['organization_id' => $organization->id]));
        return response()->json(['data' => $package], 201);
    }

    public function packageUpdate(Request $request, Organization $organization, ServicePackage $package)
    {
        abort_unless($organization->type === 'agency' && $package->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('billing.manage'), 403);
        $package->update($this->validatePackage($request, partial: true));
        return response()->json(['data' => $package->fresh()]);
    }

    public function packageDestroy(Request $request, Organization $organization, ServicePackage $package)
    {
        abort_unless($organization->type === 'agency' && $package->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('billing.manage'), 403);
        $package->delete();
        return response()->json(['data' => ['message' => 'Package deleted.']]);
    }

    // ---- Subscriptions (route org = agency) ----
    public function subscriptions(Request $request, Organization $organization)
    {
        abort_unless($organization->type === 'agency', 404);
        abort_unless($request->user()->can('billing.view'), 403);
        $agencyPackageIds = ServicePackage::where('organization_id', $organization->id)->pluck('id');
        $subs = ClientSubscription::whereIn('service_package_id', $agencyPackageIds)
            ->with(['package', 'organization:id,business_name'])->latest()->get();
        return response()->json(['data' => $subs]);
    }

    public function subscribe(Request $request, Organization $organization)
    {
        abort_unless($organization->type === 'agency', 404);
        abort_unless($request->user()->can('billing.manage'), 403);
        $data = $request->validate([
            'client_organization_id' => ['required', 'integer'],
            'service_package_id' => ['required', 'integer'],
            'status' => ['nullable', 'in:trial,active'],
            'months' => ['nullable', 'integer', 'min:1', 'max:36'],
        ]);
        $client = Organization::where('type', 'real_estate_client')->findOrFail($data['client_organization_id']);
        $package = ServicePackage::where('organization_id', $organization->id)->findOrFail($data['service_package_id']);
        // A new assignment supersedes the client's previous current subscription.
        ClientSubscription::where('organization_id', $client->id)
            ->whereIn('status', ['trial', 'active', 'past_due'])->update(['status' => 'cancelled']);
        $subscription = ClientSubscription::create([
            'organization_id' => $client->id,
            'service_package_id' => $package->id,
            'status' => $data['status'] ?? 'active',
            'starts_at' => now(),
            'ends_at' => now()->addMonths($data['months'] ?? 1),
        ]);
        $this->audit->log($organization->id, $request->user()->id, 'billing.subscription_assigned', [
            'client' => $client->business_name, 'package' => $package->name,
        ], $request);
        return response()->json(['data' => $subscription->load('package')], 201);
    }

    public function subscriptionStatus(Request $request, Organization $organization, ClientSubscription $subscription)
    {
        abort_unless($organization->type === 'agency', 404);
        abort_unless($request->user()->can('billing.manage'), 403);
        $agencyPackageIds = ServicePackage::where('organization_id', $organization->id)->pluck('id');
        abort_unless($agencyPackageIds->contains($subscription->service_package_id), 404);
        $data = $request->validate(['status' => ['required', 'in:trial,active,past_due,cancelled']]);
        $subscription->update($data);
        return response()->json(['data' => $subscription->fresh()]);
    }

    // ---- Client billing view + invoices (route org = client) ----
    public function overview(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('billing.view'), 403);
        return response()->json(['data' => array_merge($this->plans->summary($organization), [
            'invoices' => BillingInvoice::where('organization_id', $organization->id)->latest()->limit(24)->get(),
        ])]);
    }

    public function invoiceStore(Request $request, Organization $organization)
    {
        abort_unless($request->user()->can('billing.manage'), 403);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:draft,sent'],
        ]);
        $summary = $this->plans->summary($organization);
        $count = BillingInvoice::where('organization_id', $organization->id)->count() + 1;
        $invoice = BillingInvoice::create([
            'organization_id' => $organization->id,
            'client_subscription_id' => $summary['subscription']?->id,
            'service_package_id' => $summary['subscription']?->service_package_id,
            'number' => 'INV-' . str_pad((string) $count, 4, '0', STR_PAD_LEFT),
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'PKR',
            'status' => $data['status'] ?? 'sent',
            'issued_date' => now()->toDateString(),
            'due_date' => $data['due_date'] ?? now()->addDays(14)->toDateString(),
            'notes' => $data['notes'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        return response()->json(['data' => $invoice], 201);
    }

    public function invoiceUpdate(Request $request, Organization $organization, BillingInvoice $invoice)
    {
        abort_unless($invoice->organization_id === $organization->id, 404);
        abort_unless($request->user()->can('billing.manage'), 403);
        $data = $request->validate([
            'status' => ['required', 'in:draft,sent,paid,overdue,void'],
            'payment_method' => ['nullable', 'in:bank_transfer,jazzcash,easypaisa,cash,card,other'],
        ]);
        $invoice->update(array_merge($data, [
            'paid_at' => $data['status'] === 'paid' ? now() : $invoice->paid_at,
        ]));
        return response()->json(['data' => $invoice->fresh()]);
    }

    private function validatePackage(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'limits' => ['nullable', 'array'],
            'limits.max_users' => ['nullable', 'integer', 'min:0'],
            'limits.max_social_accounts' => ['nullable', 'integer', 'min:0'],
            'limits.max_leads_per_month' => ['nullable', 'integer', 'min:0'],
            'limits.max_posts_per_month' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}

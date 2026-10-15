<?php
// app/Http/Controllers/Admin/AdminOrganisationController.php
namespace App\Http\Controllers\Admin;

use App\Models\Earning;
use App\Models\Hospital;
use App\Models\Provider;
use Illuminate\Http\Request;

/** Hospitals and providers: list, view, approve / suspend. */
class AdminOrganisationController extends AdminController
{
    public function hospitals(Request $request)
    {
        $hospitals = Hospital::withCount(['users', 'paramedics', 'ambulances', 'bookings'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where(fn($q) => $q
                ->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('lga', 'like', "%{$s}%")))
            ->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $hospitals]);
    }

    public function hospital($id)
    {
        $hospital = Hospital::withCount(['paramedics', 'ambulances', 'bookings'])->with('users')->findOrFail($id);

        return response()->json(['success' => true, 'data' => [...$hospital->toArray(), ...$this->money('hospital', $hospital->id)]]);
    }

    public function providers(Request $request)
    {
        $providers = Provider::withCount(['users', 'drivers', 'towTrucks', 'bookings'])
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->search, fn($q, $s) => $q->where(fn($q) => $q
                ->where('company_name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('lga', 'like', "%{$s}%")))
            ->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")
            ->latest()
            ->paginate($this->perPage($request));

        return response()->json(['success' => true, 'data' => $providers]);
    }

    public function provider($id)
    {
        $provider = Provider::withCount(['drivers', 'towTrucks', 'bookings'])->with('users')->findOrFail($id);

        return response()->json(['success' => true, 'data' => [...$provider->toArray(), ...$this->money('provider', $provider->id)]]);
    }

    public function setHospitalStatus(Request $request, $id)
    {
        return $this->setStatus($request, Hospital::findOrFail($id));
    }

    public function setProviderStatus(Request $request, $id)
    {
        return $this->setStatus($request, Provider::findOrFail($id));
    }

    private function setStatus(Request $request, $org)
    {
        $this->authorizeAbility($request, 'operations');
        $status = $request->validate(['status' => 'required|in:active,pending,suspended'])['status'];
        $org->update(['status' => $status]);

        return response()->json(['success' => true, 'message' => 'Status updated.', 'data' => $org]);
    }

    private function money(string $type, int $id): array
    {
        $earnings = Earning::where('payee_type', $type)->where('payee_id', $id);

        return [
            'earnings' => [
                'gross'      => round((clone $earnings)->sum('gross'), 2),
                'commission' => round((clone $earnings)->sum('commission'), 2),
                'net'        => round((clone $earnings)->sum('net'), 2),
                'unpaid'     => round((clone $earnings)->whereNull('payout_id')->sum('net'), 2),
            ],
        ];
    }
}

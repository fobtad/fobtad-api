<?php
// app/Http/Controllers/SetupController.php
namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Hospital;
use App\Models\HospitalUser;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * TEMPORARY admin tools until the admin portal exists: create hospital logins
 * and approve/suspend self-registered hospitals and providers. Only active while
 * FOBTAD_SETUP_KEY is set in .env.
 */
class SetupController extends Controller
{
    public function form()
    {
        abort_unless($this->enabled(), 404);

        return response($this->page($this->formHtml()));
    }

    public function store(Request $request)
    {
        abort_unless($this->enabled(), 404);

        if (!$this->keyMatches($request)) {
            return response($this->page($this->formHtml(['Setup key is incorrect.'], $request->except(['key', 'password']))), 403);
        }

        $validator = Validator::make($request->all(), [
            'hospital_name'    => 'required|string|max:150',
            'hospital_email'   => 'required|email',
            'hospital_phone'   => 'required|string|max:30',
            'hospital_address' => 'required|string|max:255',
            'hospital_lga'     => 'nullable|string|max:100',
            'latitude'         => 'nullable|numeric|between:-90,90',
            'longitude'        => 'nullable|numeric|between:-180,180',
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'required|string|max:100',
            'email'            => 'required|email|unique:hospital_users,email',
            'phone'            => 'nullable|string|max:30',
            'password'         => ['required', Password::min(8)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            return response($this->page($this->formHtml($validator->errors()->all(), $request->except(['key', 'password']))), 422);
        }

        $data = $validator->validated();

        [$hospital, $user] = DB::transaction(function () use ($data) {
            // Re-use the hospital if it was onboarded already (adding a second login).
            $hospital = Hospital::firstOrCreate(['email' => $data['hospital_email']], [
                'name'      => $data['hospital_name'],
                'phone'     => $data['hospital_phone'],
                'address'   => $data['hospital_address'],
                'lga'       => $data['hospital_lga'] ?? null,
                'latitude'  => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'status'    => 'active',
            ]);

            $user = HospitalUser::create([
                'hospital_id' => $hospital->id,
                'first_name'  => $data['first_name'],
                'last_name'   => $data['last_name'],
                'email'       => $data['email'],
                'phone'       => $data['phone'] ?? null,
                'password'    => Hash::make($data['password']),
                'role'        => 'admin',
                'is_active'   => true,
            ]);

            return [$hospital, $user];
        });

        $html = '<div class="ok"><h2>Login created</h2><p><strong>' . e($user->email) . '</strong> can now sign in to the hospital portal for <strong>'
            . e($hospital->name) . '</strong> (hospital #' . $hospital->id . ').</p>'
            . '<p>Manage approvals at <a href="' . e(url('/api/setup/hospitals')) . '">/api/setup/hospitals</a>.</p>'
            . '<p><a href="">Create another login</a></p></div>';

        return response($this->page($html), 201);
    }

    /** Approve / suspend hospitals. GET asks for the key; POST (with key) lists them. */
    public function hospitals(Request $request)
    {
        return $this->approvals($request, 'hospitals');
    }

    /** Approve / suspend providers (towing companies etc). */
    public function providers(Request $request)
    {
        return $this->approvals($request, 'providers');
    }

    public function setStatus(Request $request, $id)
    {
        return $this->changeStatus($request, 'hospitals', Hospital::findOrFail($id));
    }

    public function setProviderStatus(Request $request, $id)
    {
        return $this->changeStatus($request, 'providers', Provider::findOrFail($id));
    }

    private function approvals(Request $request, string $kind)
    {
        abort_unless($this->enabled(), 404);

        if ($request->isMethod('get') || !$this->keyMatches($request)) {
            $error = $request->isMethod('post') ? '<div class="err">Setup key is incorrect.</div>' : '';
            return response($this->page('<h1>' . ucfirst(rtrim($kind, 's')) . ' approvals</h1>' . $error
                . '<form method="post"><label>Setup key<input type="password" name="key" required autofocus></label>'
                . '<button type="submit">Show ' . $kind . '</button></form>'), $error ? 403 : 200);
        }

        return response($this->page($this->approvalList($kind, $request->input('key'))));
    }

    private function changeStatus(Request $request, string $kind, $org)
    {
        abort_unless($this->enabled() && $this->keyMatches($request), 404);

        $status = $request->validate(['status' => 'required|in:active,pending,suspended'])['status'];
        $org->update(['status' => $status]);

        $name  = $org->name ?? $org->company_name;
        $flash = "{$name} is now " . ($status === 'active' ? 'approved' : $status) . '.';

        return response($this->page($this->approvalList($kind, $request->input('key'), $flash)));
    }

    private function approvalList(string $kind, string $key, ?string $flash = null): string
    {
        $isHospital = $kind === 'hospitals';
        $orgs = ($isHospital
                ? Hospital::withCount(['users', 'paramedics', 'ambulances'])
                : Provider::withCount(['users', 'drivers', 'towTrucks']))
            ->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")
            ->latest()
            ->get();

        $badge = ['pending' => '#B37D00', 'active' => '#3B6D11', 'suspended' => '#A32D2D'];
        $label = ['pending' => 'Pending', 'active' => 'Approved', 'suspended' => 'Suspended'];
        $button = fn($o, $status, $text, $bg) =>
            '<form method="post" action="' . e(url("/api/setup/{$kind}/{$o->id}/status")) . '" style="display:inline">'
            . '<input type="hidden" name="key" value="' . e($key) . '"><input type="hidden" name="status" value="' . $status . '">'
            . '<button class="sm" style="background:' . $bg . '">' . $text . '</button></form>';

        $rows = $orgs->map(function ($o) use ($badge, $label, $button, $isHospital) {
            $actions = match ($o->status) {
                'pending'   => $button($o, 'active', 'Approve', '#3B6D11') . ' ' . $button($o, 'suspended', 'Reject', '#A32D2D'),
                'active'    => $button($o, 'suspended', 'Suspend', '#A32D2D'),
                'suspended' => $button($o, 'active', 'Reactivate', '#3B6D11'),
            };
            $location = $o->latitude ? '' : ' · <span style="color:#B37D00">no map location</span>';
            $type     = $isHospital ? ($o->facility_type ?? 'facility') : str_replace('_', ' ', $o->business_type ?? 'provider');
            $details  = $isHospital
                ? 'Licence: ' . e($o->licence_number ?? '—') . ' · CAC: ' . e($o->cac_number ?? '—') . ' · ' . $o->paramedics_count . ' paramedics · ' . $o->ambulances_count . ' vehicles'
                : 'CAC: ' . e($o->cac_number ?? '—') . ' · Services: ' . e(implode(', ', $o->services ?? ['towing'])) . ' · ' . $o->drivers_count . ' drivers · ' . $o->tow_trucks_count . ' vehicles';

            return '<div class="card"><div class="top"><strong>' . e($o->name ?? $o->company_name) . '</strong>'
                . '<span class="badge" style="color:' . $badge[$o->status] . '">' . $label[$o->status] . '</span></div>'
                . '<div class="meta">' . e(ucfirst($type)) . ' · ' . e($o->email) . ' · ' . e($o->phone) . '</div>'
                . '<div class="meta">' . e($o->address) . ($o->lga ? ', ' . e($o->lga) : '') . $location . '</div>'
                . '<div class="meta">' . $details . ' · registered ' . $o->created_at->format('j M Y') . '</div>'
                . '<div class="actions">' . $actions . '</div></div>';
        })->implode('');

        $other = $isHospital ? 'providers' : 'hospitals';

        return '<h1>' . ucfirst(rtrim($kind, 's')) . ' approvals</h1>'
            . '<p class="muted">Approved ' . $kind . ' start receiving requests near them immediately. '
            . '<a href="' . e(url("/api/setup/{$other}")) . '">Switch to ' . $other . '</a></p>'
            . ($flash ? '<div class="ok-flash">' . e($flash) . '</div>' : '')
            . ($rows ?: '<p class="muted" style="margin-top:20px">No ' . $kind . ' yet.</p>');
    }

    /** Create a FOBTAD admin portal login. */
    public function adminForm()
    {
        abort_unless($this->enabled(), 404);

        return response($this->page($this->adminFormHtml()));
    }

    public function storeAdmin(Request $request)
    {
        abort_unless($this->enabled(), 404);

        if (!$this->keyMatches($request)) {
            return response($this->page($this->adminFormHtml(['Setup key is incorrect.'], $request->only(['first_name', 'last_name', 'email']))), 403);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:admins,email',
            'password'   => ['required', Password::min(10)->letters()->numbers()],
        ]);

        if ($validator->fails()) {
            return response($this->page($this->adminFormHtml($validator->errors()->all(), $request->only(['first_name', 'last_name', 'email']))), 422);
        }

        // The very first admin is a super admin; later ones made here are regular admins.
        $admin = Admin::create([
            ...$validator->safe()->only(['first_name', 'last_name', 'email']),
            'password' => Hash::make($request->input('password')),
            'role'     => Admin::exists() ? 'admin' : 'super_admin',
        ]);

        return response($this->page('<div class="ok"><h2>Admin created</h2><p><strong>' . e($admin->email) . '</strong> ('
            . e(str_replace('_', ' ', $admin->role)) . ') can now sign in to the admin portal.</p></div>'), 201);
    }

    private function adminFormHtml(array $errors = [], array $old = []): string
    {
        $v   = fn($k) => e($old[$k] ?? '');
        $err = $errors ? '<div class="err">' . implode('<br>', array_map('e', $errors)) . '</div>' : '';

        return '<h1>Create admin login</h1><p class="muted">The first admin created becomes the super admin (can change settings and add admins).</p>'
            . $err . '<form method="post">'
            . '<div class="row"><label>First name<input name="first_name" value="' . $v('first_name') . '" required></label>'
            . '<label>Last name<input name="last_name" value="' . $v('last_name') . '" required></label></div>'
            . '<label>Email<input type="email" name="email" value="' . $v('email') . '" required></label>'
            . '<label>Password <span>(10+ characters, letters and numbers)</span><input type="password" name="password" required></label>'
            . '<label>Setup key<input type="password" name="key" required></label>'
            . '<button type="submit">Create admin</button></form>';
    }

    private function keyMatches(Request $request): bool
    {
        return hash_equals((string) config('services.fobtad.setup_key'), (string) $request->input('key'));
    }

    private function enabled(): bool
    {
        return filled(config('services.fobtad.setup_key'));
    }

    private function formHtml(array $errors = [], array $old = []): string
    {
        $v = fn($k) => e($old[$k] ?? '');
        $err = $errors
            ? '<div class="err">' . implode('<br>', array_map('e', $errors)) . '</div>'
            : '';
        $field = fn($name, $label, $type = 'text', $req = true) =>
            "<label>{$label}" . ($req ? '' : ' <span>(optional)</span>') . "<input type=\"{$type}\" name=\"{$name}\" value=\"{$v($name)}\"" . ($req ? ' required' : '') . '></label>';

        return '<h1>Create hospital login</h1><p class="muted">Temporary onboarding page. The new user will be the hospital\'s administrator.</p>'
            . $err
            . '<form method="post">'
            . '<h3>Hospital</h3>'
            . $field('hospital_name', 'Hospital name')
            . $field('hospital_email', 'Hospital email', 'email')
            . $field('hospital_phone', 'Hospital phone')
            . $field('hospital_address', 'Address')
            . $field('hospital_lga', 'LGA', 'text', false)
            . '<div class="row">' . $field('latitude', 'Latitude', 'text', false) . $field('longitude', 'Longitude', 'text', false) . '</div>'
            . '<h3>Administrator</h3>'
            . '<div class="row">' . $field('first_name', 'First name') . $field('last_name', 'Last name') . '</div>'
            . $field('email', 'Login email', 'email')
            . $field('phone', 'Phone', 'text', false)
            . '<label>Password <span>(8+ characters, letters and numbers)</span><input type="password" name="password" required></label>'
            . '<h3>Authorisation</h3>'
            . '<label>Setup key<input type="password" name="key" required></label>'
            . '<button type="submit">Create login</button></form>';
    }

    private function page(string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>FOBTAD setup</title><style>'
            . 'body{font-family:system-ui,sans-serif;background:#F7F8FA;color:#042C53;margin:0;padding:32px 16px}'
            . 'main{max-width:520px;margin:0 auto;background:#fff;border:1px solid rgba(4,44,83,.1);border-radius:14px;padding:28px}'
            . 'h1{font-size:22px;margin:0 0 6px}h3{font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#7A95B0;margin:24px 0 8px}'
            . '.muted{color:#7A95B0;font-size:14px;margin:0}label{display:block;font-size:13px;font-weight:600;margin:10px 0}label span{font-weight:400;color:#7A95B0}'
            . 'input{display:block;width:100%;box-sizing:border-box;margin-top:6px;padding:10px 12px;border:1px solid rgba(4,44,83,.15);border-radius:8px;font-size:14px}'
            . '.row{display:grid;grid-template-columns:1fr 1fr;gap:12px}button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:9px;background:#042C53;color:#fff;font-size:15px;font-weight:600;cursor:pointer}'
            . '.err{background:#FCEBEB;color:#A32D2D;border-radius:8px;padding:10px 14px;font-size:13px;margin-top:16px}'
            . '.ok h2{margin-top:0;color:#3B6D11}code{background:#F7F8FA;padding:2px 6px;border-radius:4px}'
            . '.card{border:1px solid rgba(4,44,83,.1);border-radius:10px;padding:14px 16px;margin-top:12px}.top{display:flex;justify-content:space-between;gap:8px}'
            . '.badge{font-size:12px;font-weight:700}.meta{font-size:12px;color:#3D5A7A;margin-top:4px}.actions{margin-top:10px}'
            . 'button.sm{width:auto;margin:0;padding:7px 14px;font-size:13px}.ok-flash{background:#EAF3DE;color:#3B6D11;border-radius:8px;padding:10px 14px;font-size:13px;margin-top:16px}'
            . '</style></head><body><main>' . $body . '</main></body></html>';
    }
}

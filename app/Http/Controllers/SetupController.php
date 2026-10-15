<?php
// app/Http/Controllers/SetupController.php
namespace App\Http\Controllers;

use App\Models\Hospital;
use App\Models\HospitalUser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * TEMPORARY: creates hospital portal logins until the admin portal exists.
 * Only active while FOBTAD_SETUP_KEY is set in .env — remove the key (and
 * this controller) once onboarding is done.
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

        if (!hash_equals((string) config('services.fobtad.setup_key'), (string) $request->input('key'))) {
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
            . '<p>When you have finished onboarding, remove <code>FOBTAD_SETUP_KEY</code> from .env and clear the cache to switch this page off.</p>'
            . '<p><a href="">Create another login</a></p></div>';

        return response($this->page($html), 201);
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
            . '</style></head><body><main>' . $body . '</main></body></html>';
    }
}

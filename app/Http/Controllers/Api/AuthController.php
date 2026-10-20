<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\TenantAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;

class AuthController extends Controller
{
    public function notifications(Request $request)
    {
        return response()->json(['data' => $request->user()->notifications()->latest()->paginate(20)]);
    }

    public function readNotification(Request $request, string $notification)
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return response()->json(['data' => $record]);
    }

    public function register(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'email' => 'required|email:rfc|max:190|unique:users,email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()], 'phone' => 'nullable|string|max:32'], ['email.unique' => 'An account already exists for this email address. Sign in or reset your password.']);
        // The owning vendor is only ever the tenant the server resolved from the verified storefront; nothing in the body can set it.
        $tenant = $request->attributes->get('tenant_vendor');
        $user = new User($data);
        $user->role = 'customer';
        $user->vendor_id = $tenant?->id;
        $user->save();

        return response()->json(['data' => $user, 'token' => TenantAccess::issueToken($user, $tenant)], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $data['email'])->where('is_active', true)->first();
        $tenant = $request->attributes->get('tenant_vendor');
        // One generic answer for: unknown email, wrong password, school account, or an account that belongs to a different store.
        if (! $user || $user->role === 'school' || ! Hash::check($data['password'], $user->password) || ! TenantAccess::allows($user, $tenant)) {
            return response()->json(['message' => 'The supplied credentials are invalid.'], 422);
        }

        return response()->json(['data' => $user->load('vendorMemberships.vendor:id,name,slug,status'), 'token' => TenantAccess::issueToken($user, $tenant)]);
    }

    public function me(Request $request)
    {
        return response()->json(['data' => $request->user()->load('vendorMemberships.vendor:id,name,slug,status')]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:160', 'phone' => 'nullable|string|max:32']);
        $request->user()->update($data);

        return response()->json(['data' => $request->user()->fresh()]);
    }

    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => 'required|string',
            'password' => ['required', 'confirmed', 'different:current_password', PasswordRule::min(10)->letters()->numbers()],
        ]);
        abort_unless(Hash::check($data['current_password'], $request->user()->password), 422, 'Your current password is incorrect.');
        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Your password has been changed.']);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function forgot(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        // Reset links are only sent for accounts that may sign in in this context; the answer is identical either way.
        $user = User::where('email', $request->input('email'))->where('is_active', true)->first();
        if ($user && TenantAccess::allows($user, $request->attributes->get('tenant_vendor'))) {
            Password::sendResetLink($request->only('email'));
        }

        return response()->json(['message' => 'If an account exists for that address, reset instructions will be sent.']);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $user = User::where('email', $data['email'])->first();
        if ($user && ! TenantAccess::allows($user, $request->attributes->get('tenant_vendor'))) {
            return response()->json(['message' => __(Password::INVALID_TOKEN)], 422);   // same answer as a bad token
        }
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        return response()->json(['message' => __($status)], $status === Password::PASSWORD_RESET ? 200 : 422);
    }
}

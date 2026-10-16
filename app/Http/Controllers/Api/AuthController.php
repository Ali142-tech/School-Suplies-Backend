<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
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
        $data = $request->validate(['name' => 'required|string|max:160', 'email' => 'required|email:rfc|max:190|unique:users,email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()], 'phone' => 'nullable|string|max:32']);
        $user = User::create([...$data, 'role' => 'customer']);

        return response()->json(['data' => $user, 'token' => $user->createToken('web')->plainTextToken], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        $user = User::where('email', $data['email'])->where('is_active', true)->first();
        if (! $user || $user->role === 'school' || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'The supplied credentials are invalid.'], 422);
        }

        return response()->json(['data' => $user->load('vendorMemberships.vendor:id,name,slug,status'), 'token' => $user->createToken('web')->plainTextToken]);
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
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => 'If an account exists for that address, reset instructions will be sent.']);
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password])->save();
            $user->tokens()->delete();
        });

        return response()->json(['message' => __($status)], $status === Password::PASSWORD_RESET ? 200 : 422);
    }
}

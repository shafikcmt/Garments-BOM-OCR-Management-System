<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;

class UserController extends Controller
{
    public function index()
    {
        $users = User::latest()->get();
        return view('admin.users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::orderBy('name')->get();
        return view('admin.users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:6'],
            'status' => ['required', 'in:0,1'],
            'role' => ['required', 'exists:roles,name'],
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'status' => $data['status'],
        ]);

        $user->syncRoles([$data['role']]);

        return redirect()->route('admin.users.index')->with('success', 'User created successfully.');
    }

    public function show(User $user)
    {
        return view('admin.users.show', compact('user'));
    }

    public function edit(User $user)
    {
        $roles = Role::orderBy('name')->get();
        return view('admin.users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,' . $user->id],
            'status' => ['required', 'in:0,1'],
            'role' => ['required', 'exists:roles,name'],
            'profile_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $isSelf = $user->id === auth()->id();

        // Safety: admin cannot deactivate or change the role of their own account.
        if ($isSelf) {
            $data['status'] = 1;
            $data['role'] = $user->getRoleNames()->first() ?? $data['role'];
        }

        $user->name = $data['name'];
        $user->email = $data['email'];
        $user->status = $data['status'];

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $user->profile_photo = $request->file('profile_photo')
                ->store('profile-photos', 'public');
        }

        $user->save();
        $user->syncRoles([$data['role']]);

        $message = $isSelf
            ? 'User profile updated. Note: you cannot change your own role or status.'
            : 'User profile updated successfully.';

        return redirect()->route('admin.users.edit', $user)->with('success', $message);
    }

    public function resetPassword(Request $request, User $user)
    {
        $request->validate([
            'new_password' => ['required', 'min:8', 'confirmed'],
        ]);

        $user->update([
            'password' => Hash::make($request->input('new_password')),
        ]);

        return redirect()->route('admin.users.edit', $user)
            ->with('success', 'Password has been reset successfully.');
    }

    public function sendPasswordResetLink(User $user)
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return redirect()->route('admin.users.edit', $user)
                ->with('success', 'Password reset email sent to ' . $user->email . '.');
        }

        return redirect()->route('admin.users.edit', $user)
            ->with('error', 'Could not send password reset email. ' . __($status));
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('admin.users.index')
                ->with('error', 'You cannot delete your own account.');
        }

        $user->delete();
        return redirect()->route('admin.users.index')->with('success', 'User deleted successfully.');
    }
}

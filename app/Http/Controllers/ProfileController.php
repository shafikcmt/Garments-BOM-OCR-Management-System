<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        $departmentLabels = \App\Support\PiAlertSettings::departmentOptions();

        $roleLabels = $user->getRoleNames()
            ->map(fn ($role) => $departmentLabels[$role] ?? \Illuminate\Support\Str::headline($role))
            ->values();

        return view('profile.edit', [
            'user' => $user,
            'roleLabels' => $roleLabels,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        if ($request->hasFile('profile_photo')) {
            if ($user->profile_photo) {
                Storage::disk('public')->delete($user->profile_photo);
            }

            $user->profile_photo = $request->file('profile_photo')
                ->store('profile-photos', 'public');
        }

        $user->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Upload or replace the user's personal signature image. Used to auto-fill
     * the Prepared / Checked / Approved By boxes on PRA documents. Mirrors the
     * admin PI/PRA signature upload rules (PNG/JPG, max 2 MB).
     */
    public function updateSignature(Request $request): RedirectResponse
    {
        $request->validate([
            'signature' => ['required', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ], [
            'signature.required' => 'Please choose a signature image to upload.',
            'signature.max' => 'The signature image must not be larger than 2 MB.',
        ]);

        $user = $request->user();

        if ($user->signature_path) {
            Storage::disk('public')->delete($user->signature_path);
        }

        $user->signature_path = $request->file('signature')->store('signatures', 'public');
        $user->save();

        return Redirect::route('profile.edit')->with('status', 'signature-updated');
    }

    /**
     * Remove the user's uploaded signature image.
     */
    public function destroySignature(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->signature_path) {
            Storage::disk('public')->delete($user->signature_path);
            $user->signature_path = null;
            $user->save();
        }

        return Redirect::route('profile.edit')->with('status', 'signature-removed');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}

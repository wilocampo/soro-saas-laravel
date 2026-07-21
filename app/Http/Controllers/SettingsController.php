<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $tenant = currentTenant();
        $settings = $tenant?->getSettings() ?? [];

        return Inertia::render('Settings/Index', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'app_name' => 'required|string|max:255',
            'app_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
            'app_favicon' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg,ico|max:1024',
            'primary_color' => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'timezone' => 'nullable|string|max:50',
            'date_format' => 'nullable|string|max:20',
            'time_format' => 'nullable|string|max:20',
        ]);

        $tenant = currentTenant();
        if (! $tenant) {
            return redirect()->back()->with('error', 'No tenant found.');
        }

        $settings = $tenant->getSettings();

        // Handle logo upload
        if ($request->hasFile('app_logo')) {
            $logoPath = $request->file('app_logo')->store('logos', 'public');
            $settings['app_logo'] = $logoPath;

            // Delete old logo if exists
            if (isset($settings['app_logo']) && Storage::disk('public')->exists($settings['app_logo'])) {
                Storage::disk('public')->delete($settings['app_logo']);
            }
        }

        // Handle favicon upload
        if ($request->hasFile('app_favicon')) {
            $faviconPath = $request->file('app_favicon')->store('favicons', 'public');
            $settings['app_favicon'] = $faviconPath;

            // Delete old favicon if exists
            if (isset($settings['app_favicon']) && Storage::disk('public')->exists($settings['app_favicon'])) {
                Storage::disk('public')->delete($settings['app_favicon']);
            }
        }

        // Update other settings
        $settings = array_merge($settings, [
            'app_name' => $request->app_name,
            'primary_color' => $request->primary_color,
            'secondary_color' => $request->secondary_color,
            'contact_email' => $request->contact_email,
            'contact_phone' => $request->contact_phone,
            'address' => $request->address,
            'timezone' => $request->timezone ?? 'UTC',
            'date_format' => $request->date_format ?? 'Y-m-d',
            'time_format' => $request->time_format ?? 'H:i:s',
        ]);

        $tenant->setSettings($settings);

        return redirect()->route('settings.index')
            ->with('success', 'Settings updated successfully.');
    }
}

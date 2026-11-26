<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\PurchasingSettings;
use Illuminate\Http\Request;

/**
 * General Stock purchasing settings for Admin and department heads: the PO
 * format defaults, the titles of the PO signature boxes, and who gives each
 * purchase approval. All of it is stored data; nothing here changes a PO that
 * already exists.
 */
class PurchaseSettingsController extends Controller
{
    public function index(Request $request)
    {
        $this->authoriseManager($request);
        $user = $request->user();

        $users = User::query()->where('status', 1)->orderBy('name')->get(['id', 'name', 'designation']);

        $points = collect(PurchasingSettings::approvalPoints())->map(function (array $point) use ($users, $user) {
            $holders = $users->filter(fn (User $u) => collect($point['permissions'])->every(fn ($p) => $u->hasPermissionTo($p)));

            return $point + [
                'holders' => $holders->pluck('id')->all(),
                // People who hold it through their ROLE cannot be removed from here.
                'viaRole' => $holders->filter(fn (User $u) => collect($point['permissions'])->every(fn ($p) => $u->getPermissionsViaRoles()->contains('name', $p)))->pluck('id')->all(),
                'mayEdit' => $user->hasRole('admin') || collect($point['permissions'])->every(fn ($p) => $user->can($p)),
            ];
        });

        return view('store.purchasing.po-settings', [
            'fields' => PurchasingSettings::poFields(),
            'values' => PurchasingSettings::poDefaults(),
            'signs' => PurchasingSettings::poSignLabels(),
            'points' => $points,
            'users' => $users,
            'isAdmin' => $user->hasRole('admin'),
        ]);
    }

    public function saveFormat(Request $request)
    {
        $this->authoriseManager($request);

        $rules = ['sign_checked' => ['nullable', 'string', 'max:60'], 'sign_authorised' => ['nullable', 'string', 'max:60']];
        foreach (array_keys(PurchasingSettings::poFields()) as $key) {
            $rules[$key] = ['nullable', 'string', 'max:1000'];
        }

        $data = $request->validate($rules);

        PurchasingSettings::savePoDefaults($data, $request->user()->hasRole('admin'));
        PurchasingSettings::savePoSignLabels($data['sign_checked'] ?? '', $data['sign_authorised'] ?? '');

        return redirect()->route('store.purchasing.po.settings')
            ->with('success', 'PO format saved. New purchase orders use it; existing ones keep what they were made with.');
    }

    public function saveApprovers(Request $request)
    {
        $this->authoriseManager($request);
        $editor = $request->user();

        $request->validate(['approvers' => ['nullable', 'array'], 'approvers.*' => ['nullable', 'array'], 'approvers.*.*' => ['integer']]);

        $points = PurchasingSettings::approvalPoints();
        $chosen = collect((array) $request->input('approvers', []))
            ->map(fn ($ids) => collect((array) $ids)->map(fn ($id) => (int) $id)->all());

        $editable = collect($points)->filter(
            fn ($point) => $editor->hasRole('admin') || collect($point['permissions'])->every(fn ($p) => $editor->can($p))
        );

        $changed = 0;

        foreach (User::query()->where('status', 1)->get() as $u) {
            $on = [];
            $off = [];

            foreach ($editable as $key => $point) {
                $wants = in_array($u->id, $chosen->get($key, []), true);
                $has = collect($point['permissions'])->every(fn ($p) => $u->hasPermissionTo($p));

                if ($wants && ! $has) {
                    $on = array_merge($on, $point['permissions']);
                } elseif (! $wants && $has) {
                    $off = array_merge($off, $point['permissions']);
                }
            }

            if ($on === [] && $off === []) {
                continue;
            }

            // A permission that a point this person keeps still needs is never taken away
            // (the CS check and CS approve points share store.purchase_cs.view, for example).
            $keepers = collect($points)
                ->filter(fn ($point, $key) => $editable->has($key)
                    ? in_array($u->id, $chosen->get($key, []), true)
                    : collect($point['permissions'])->every(fn ($p) => $u->hasPermissionTo($p)))
                ->flatMap(fn ($point) => $point['permissions'])
                ->all();
            $fromRole = $u->getPermissionsViaRoles()->pluck('name');

            $u->givePermissionTo(collect($on)->unique()->reject(fn ($n) => $fromRole->contains($n))->values()->all());
            $u->revokePermissionTo(collect($off)->unique()->diff($keepers)->diff($on)->values()->all());
            $changed++;
        }

        return redirect()->route('store.purchasing.po.settings')
            ->with('success', $changed > 0 ? "Approvers updated for {$changed} " . \Illuminate\Support\Str::plural('person', $changed) . '.' : 'No change to approvers.');
    }

    private function authoriseManager(Request $request): void
    {
        abort_unless(PurchasingSettings::mayManage($request->user()), 403, 'Only Admin or a department head can change these settings.');
    }
}

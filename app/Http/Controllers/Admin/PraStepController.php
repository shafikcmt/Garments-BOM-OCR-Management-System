<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Models\PraStep;
use App\Services\PraStageResolver;
use App\Models\AppSetting;
use App\Support\DepartmentRoles;
use App\Support\PaymentRequestSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Settings > PRA Approval Steps.
 *
 * Admin defines the chain a departmental PRA goes through: how many steps, their
 * order, who each is for, and which steps print a signature box (and its title).
 * Who exactly acts at a step, per buyer, is chosen on PRA Approval Routing.
 *
 * Steps are never deleted, only switched off, so the history of PRAs that went
 * through them still reads correctly. A step cannot be switched off while a PRA is
 * waiting on it.
 */
class PraStepController extends Controller
{
    /** The most signature boxes a PRA can print (Prepared By + the steps' boxes). */
    private const MAX_BOXES = 8;

    public function index(PraStageResolver $resolver)
    {
        abort_unless(PraStep::isAvailable(), 404);

        $steps = PraStep::query()->orderBy('position')->orderBy('sequence')->get();

        $waiting = PaymentRequest::query()
            ->where('flow_version', PaymentRequest::FLOW_DEPARTMENTAL)
            ->whereNotNull('current_sequence')
            ->selectRaw('current_sequence, count(*) as total')
            ->groupBy('current_sequence')
            ->pluck('total', 'current_sequence');

        $departments = collect(DepartmentRoles::map())->map(fn ($d) => $d['label'])->all();

        return view('admin.pra-steps.index', [
            'steps' => $steps,
            'waiting' => $waiting,
            'departments' => $departments,
            'boxTitles' => $steps->where('is_active', true)->pluck('signature_label')->filter(fn ($t) => trim((string) $t) !== '')->values(),
            'preparedEnabled' => PaymentRequestSettings::preparedBoxEnabled(),
            'preparedTitle' => PaymentRequestSettings::flow2Label('prepared'),
            'maxBoxes' => self::MAX_BOXES,
        ]);
    }

    /** The creator's "Prepared By" box: print it or not, and its title. */
    public function savePrepared(Request $request)
    {
        $data = $request->validate(['prepared_title' => ['nullable', 'string', 'max:80']]);
        $enabled = $request->boolean('prepared_enabled');

        if ($enabled) {
            $this->assertBoxLimit(['signature_label' => null], null, true);
        }

        AppSetting::put(PaymentRequestSettings::KEY_PREPARED_BOX, $enabled ? '1' : '0');
        AppSetting::put(PaymentRequestSettings::flow2LabelKey('prepared'), trim((string) ($data['prepared_title'] ?? '')));

        return redirect()->route('admin.pra-steps.index')
            ->with('success', $enabled ? 'The creator\'s box will be printed.' : 'The creator\'s box is off: only the step boxes print.');
    }

    public function store(Request $request)
    {
        abort_unless(PraStep::isAvailable(), 404);

        $data = $this->validated($request);
        $this->assertBoxLimit($data, null);

        DB::transaction(function () use ($data) {
            $this->clearFlagsFor($data, null);

            PraStep::create($data + [
                'sequence' => (int) PraStep::max('sequence') + 1,
                'position' => (int) PraStep::max('position') + 10,
                'is_active' => true,
            ]);
        });

        return redirect()->route('admin.pra-steps.index')->with('success', 'Step "' . $data['label'] . '" added at the end of the chain.');
    }

    public function update(Request $request, PraStep $praStep)
    {
        $data = $this->validated($request);
        $this->assertBoxLimit($data, $praStep);

        DB::transaction(function () use ($data, $praStep) {
            $this->clearFlagsFor($data, $praStep);
            $praStep->update($data);
        });

        return redirect()->route('admin.pra-steps.index')->with('success', 'Step "' . $praStep->label . '" saved. It applies to PRAs from now on, including ones already waiting.');
    }

    /** Move a step one place up or down the chain. */
    public function move(Request $request, PraStep $praStep)
    {
        $direction = $request->input('direction') === 'up' ? 'up' : 'down';

        $ordered = PraStep::query()->orderBy('position')->orderBy('sequence')->get()->values();
        $index = $ordered->search(fn (PraStep $step) => $step->id === $praStep->id);
        $neighbour = $ordered->get($direction === 'up' ? $index - 1 : $index + 1);

        if ($index === false || ! $neighbour || ($direction === 'up' && $index === 0)) {
            return redirect()->route('admin.pra-steps.index');
        }

        DB::transaction(function () use ($praStep, $neighbour) {
            [$a, $b] = [$praStep->position, $neighbour->position];

            // Equal positions would not swap; spread them first.
            if ($a === $b) {
                $b = $a + ($praStep->sequence < $neighbour->sequence ? 1 : -1);
            }

            $praStep->update(['position' => $b]);
            $neighbour->update(['position' => $a]);
        });

        return redirect()->route('admin.pra-steps.index')->with('success', 'Step order updated.');
    }

    /** Switch a step off (out of the chain) or back on. */
    public function toggle(PraStep $praStep)
    {
        if ($praStep->is_active) {
            $waiting = PaymentRequest::query()
                ->where('flow_version', PaymentRequest::FLOW_DEPARTMENTAL)
                ->where('current_sequence', $praStep->sequence)
                ->count();

            if ($waiting > 0) {
                return redirect()->route('admin.pra-steps.index')
                    ->with('warning', $waiting . ' PRA(s) are waiting at "' . $praStep->label . '". Let them move on (or reject them) before switching this step off.');
            }

            if (PraStep::where('is_active', true)->count() <= 1) {
                return redirect()->route('admin.pra-steps.index')->with('warning', 'The chain needs at least one step.');
            }
        } else {
            $this->assertBoxLimit(['signature_label' => $praStep->signature_label], $praStep);
        }

        $praStep->update(['is_active' => ! $praStep->is_active]);

        return redirect()->route('admin.pra-steps.index')
            ->with('success', 'Step "' . $praStep->label . '" is now ' . ($praStep->is_active ? 'in' : 'out of') . ' the chain.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'department' => ['nullable', Rule::in(array_keys(DepartmentRoles::map()))],
            'actor_kind' => ['required', Rule::in(array_keys(PraStep::KINDS))],
            'stage' => ['required', Rule::in(array_keys(PraStep::STAGES))],
            'signature_label' => ['nullable', 'string', 'max:120'],
        ]);

        $data['label'] = trim($data['label']);
        $data['department'] = ($data['department'] ?? '') === '' ? null : $data['department'];
        $data['signature_label'] = trim((string) ($data['signature_label'] ?? '')) === '' ? null : trim($data['signature_label']);
        $data['buyer_scoped'] = $request->boolean('buyer_scoped');
        $data['prints_ocr_check'] = $request->boolean('prints_ocr_check');
        $data['prints_completion'] = $request->boolean('prints_completion');

        return $data;
    }

    /** Prepared By plus the steps' boxes must fit on the printed sheet. */
    protected function assertBoxLimit(array $data, ?PraStep $editing, bool $creatorBoxOn = false): void
    {
        $others = PraStep::query()
            ->where('is_active', true)
            ->when($editing, fn ($q) => $q->where('id', '!=', $editing->id))
            ->whereNotNull('signature_label')
            ->where('signature_label', '!=', '')
            ->count();

        $mine = trim((string) ($data['signature_label'] ?? '')) !== '' ? 1 : 0;

        // The creator's box counts when it is on (or is being turned on).
        $creator = ($creatorBoxOn || PaymentRequestSettings::preparedBoxEnabled()) ? 1 : 0;

        if ($creator + $others + $mine > self::MAX_BOXES) {
            abort(redirect()->route('admin.pra-steps.index')->with('warning',
                'A PRA can print at most ' . self::MAX_BOXES . ' signature boxes (Prepared By + ' . (self::MAX_BOXES - 1) . ' steps). Clear the box title of another step first.'));
        }
    }

    /** Only one step fills the OCR box and one the completion line. */
    protected function clearFlagsFor(array $data, ?PraStep $keep): void
    {
        foreach (['prints_ocr_check', 'prints_completion'] as $flag) {
            if (! empty($data[$flag])) {
                PraStep::query()->when($keep, fn ($q) => $q->where('id', '!=', $keep->id))->update([$flag => false]);
            }
        }
    }
}

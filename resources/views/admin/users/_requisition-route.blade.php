{{--
    Requisition approval route — says, as the admin fills in the form, who will
    check this person's requisitions.

    Display only. It mirrors RequisitionReviewChain (section head, then
    department head, then the Store; a head's own sheet skips their own step)
    from the same data the chain reads, so the admin can see a wrong
    department, role or section BEFORE saving instead of finding out when a
    requisition goes to the wrong person.

    The department comes from the chosen ROLE, exactly as it does on the server:
    a role mapped to no department (Requisition Raiser) has no heads at all.
--}}
@php
    $routeDepartments = \App\Models\Department::query()->active()->with('head:id,name')->get(['id', 'key', 'label', 'head_user_id'])
        ->mapWithKeys(fn ($d) => [$d->key => ['label' => $d->label, 'head' => $d->head ? ['id' => $d->head->id, 'name' => $d->head->name] : null]]);
    $routeSections = \App\Models\IndentSection::selectable()->with('head:id,name')->get(['id', 'name', 'department_key', 'head_user_id'])
        ->mapWithKeys(fn ($s) => [$s->id => ['name' => $s->name, 'department' => $s->department_key, 'head' => $s->head ? ['id' => $s->head->id, 'name' => $s->head->name] : null]]);

    $routePayload = [
        'departments' => $routeDepartments,
        'sections' => $routeSections,
        'selfId' => isset($user) ? $user->id : null,
        'storeSide' => ['store', 'management'],
    ];

    $heads = collect();
    if (isset($user)) {
        $routeDepartments->each(fn ($d, $key) => ($d['head']['id'] ?? null) === $user->id ? $heads->push('Department Head of '.$d['label']) : null);
        $routeSections->each(fn ($s) => ($s['head']['id'] ?? null) === $user->id ? $heads->push('Section Head of '.$s['name']) : null);
    }
@endphp

<section class="gx-route mb-4" aria-labelledby="routeTitle"
         data-route='@json($routePayload)'>
    <header class="gx-route-head">
        <h6 id="routeTitle" class="gx-route-title">Requisition approval route</h6>
        <p class="gx-route-sub">Who checks this person's requisitions, from their department, role and section.</p>
    </header>

    <div class="gx-route-body">
        <ol class="gx-route-flow js-route-flow" aria-live="polite"></ol>
        <p class="gx-route-note js-route-note mb-0"></p>

        @if($heads->isNotEmpty())
            <p class="gx-route-heads mb-0">
                <strong>{{ $heads->implode(' · ') }}.</strong>
                Their own requisitions skip that step and go to the next one.
            </p>
        @endif

        @if($scope->isSuperAdmin())
            <a href="{{ route('admin.departments.index') }}" class="gx-route-link">
                <i class="bi bi-diagram-3 me-1" aria-hidden="true"></i>Set Section and Department Heads
            </a>
        @endif
    </div>
</section>

<style>
    .gx-route { border: 1px solid var(--gx-surface-border, #e2e8f0); border-radius: var(--gx-radius-sm, 12px); background: var(--gx-card, #fff); overflow: hidden; }
    .gx-route-head { padding: .75rem 1rem; background: #f8fafc; border-bottom: 1px solid var(--gx-surface-border, #e2e8f0); }
    .gx-route-title { margin: 0; font-size: .85rem; font-weight: 700; color: var(--gx-text, #1E293B); }
    .gx-route-sub { margin: .1rem 0 0; font-size: .75rem; color: var(--gx-text-muted, #64748B); }
    .gx-route-body { padding: .9rem 1rem; display: grid; gap: .6rem; }
    .gx-route-flow { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; align-items: center; gap: .4rem; }
    .gx-route-step { display: inline-flex; flex-direction: column; padding: .35rem .7rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; line-height: 1.25; }
    .gx-route-step small { font-size: .66rem; text-transform: uppercase; letter-spacing: .04em; color: #94a3b8; font-weight: 600; }
    .gx-route-step span { font-size: .84rem; font-weight: 600; color: var(--gx-text, #1E293B); }
    .gx-route-step.is-head { border-color: var(--gx-secondary-border, #BFDBFE); background: var(--gx-secondary-bg, #DBEAFE); }
    .gx-route-step.is-missing span { color: #b45309; }
    .gx-route-arrow { color: #94a3b8; font-size: .9rem; }
    .gx-route-note { font-size: .78rem; color: var(--gx-text-muted, #64748B); }
    .gx-route-note.is-warning { color: #b45309; }
    .gx-route-heads { font-size: .78rem; color: var(--gx-text, #1E293B); }
    .gx-route-link { font-size: .78rem; font-weight: 600; text-decoration: none; }
</style>

<script>
    (function () {
        var box = document.querySelector('.gx-route');
        if (! box) { return; }

        var data = JSON.parse(box.dataset.route);
        var flow = box.querySelector('.js-route-flow');
        var note = box.querySelector('.js-route-note');
        var roleSelect = document.querySelector('.js-role');
        var sectionSelect = document.getElementById('requisitionSection');

        function step(label, text, cls) {
            var li = document.createElement('li');
            li.className = 'gx-route-step ' + (cls || '');
            var small = document.createElement('small');
            small.textContent = label;
            var span = document.createElement('span');
            span.textContent = text;
            li.appendChild(small);
            li.appendChild(span);
            return li;
        }

        function arrow() {
            var li = document.createElement('li');
            li.className = 'gx-route-arrow';
            li.setAttribute('aria-hidden', 'true');
            li.textContent = '→';
            return li;
        }

        function render(steps, message, warn) {
            flow.innerHTML = '';
            steps.forEach(function (s, i) {
                if (i) { flow.appendChild(arrow()); }
                flow.appendChild(s);
            });
            note.textContent = message || '';
            note.classList.toggle('is-warning', !! warn);
        }

        function update() {
            var option = roleSelect && roleSelect.selectedOptions[0];
            var department = option && option.value ? option.dataset.department : '';

            if (! option || ! option.value) {
                render([], 'Choose a department and role to see the route.');
                return;
            }

            var me = step('Raised by', 'This person');
            var store = step('Then', 'Store');

            // A role mapped to no department has no heads at all.
            if (! department || department === 'other') {
                render([me, store], 'This role belongs to no department, so requisitions skip every head and go straight to the Store. Pick the department’s own role to route them through its heads.', true);
                return;
            }

            if (data.storeSide.indexOf(department) !== -1) {
                render([me, step('Then', 'Store Head')], 'A Store or Management user raises the final requisition. It has no department head step.');
                return;
            }

            var dept = data.departments[department] || null;
            var section = sectionSelect && sectionSelect.value ? data.sections[sectionSelect.value] : null;
            var steps = [me];
            var message = '';
            var warn = false;

            if (section && section.department !== department) {
                message = section.name + ' is not a ' + (dept ? dept.label : 'this department') + ' section, so its head is ignored. Pick one of this department’s sections.';
                warn = true;
                section = null;
            }

            if (section && section.head && section.head.id !== data.selfId) {
                steps.push(step('Section Head', section.head.name + ' · ' + section.name, 'is-head'));
            } else if (section && ! section.head) {
                message = message || section.name + ' has no Section Head yet, so its requisitions go to the Department Head.';
            }

            if (dept && dept.head && dept.head.id !== data.selfId) {
                steps.push(step('Department Head', dept.head.name + ' · ' + dept.label, 'is-head'));
            } else if (dept && ! dept.head) {
                steps.push(step('Department Head', 'Not set', 'is-missing'));
                message = message || 'No Department Head is set for ' + dept.label + '.';
                warn = true;
            }

            steps.push(store);

            // The nearest head is the only one a sheet stops at.
            if (! message && steps.length > 3) {
                message = 'A requisition stops at the nearest head only: the Section Head when there is one, otherwise the Department Head.';
            }

            render(steps, message, warn);
        }

        ['.js-department', '.js-role', '#requisitionSection'].forEach(function (selector) {
            var el = document.querySelector(selector);
            if (el) { el.addEventListener('change', function () { setTimeout(update, 0); }); }
        });

        document.addEventListener('DOMContentLoaded', update);
        update();
    })();
</script>

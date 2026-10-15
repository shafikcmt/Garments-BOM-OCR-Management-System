{{--
    Requisition Section — the section a department user raises requisitions for.

    Shared by the Create and Edit screens (it used to exist on Create only, so a
    section could not be set or changed on an account that already existed).
    Super admin only, like Designation and Employee ID: the caller wraps it.

    It fixes the Section, User Dept. and requisition number on the person's
    form, AND it decides who checks their requisitions first: the Section Head
    of this section, named in Admin > Settings > Departments. Sections are
    grouped by department, and the Users form shows only the chosen
    department's sections (_department-roles).
--}}
@php
    $sectionRows = \App\Models\IndentSection::selectable()->get(['id', 'name', 'department_key']);
    $sectionDepartments = \App\Support\DepartmentRoles::departments();
    $currentSection = (string) old('requisition_section_id', $user->requisition_section_id ?? '');
@endphp
<div class="mb-3">
    <label class="form-label fw-semibold" for="requisitionSection">Requisition Section</label>
    <select name="requisition_section_id" id="requisitionSection" class="form-select">
        <option value="">— Not set —</option>
        @foreach($sectionRows->groupBy(fn ($s) => (string) $s->department_key) as $departmentKey => $group)
            <optgroup label="{{ $sectionDepartments[$departmentKey] ?? 'Other' }}">
                @foreach($group as $section)
                    <option value="{{ $section->id }}" data-department="{{ $section->department_key }}" @selected($currentSection === (string) $section->id)>{{ $section->name }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </select>
    <div class="form-text">
        The Section Head of this section checks this person's requisitions first. Leave it not set for a department
        with no sections (HARC, Maintenance): their requisitions go to the Department Head.
    </div>
</div>

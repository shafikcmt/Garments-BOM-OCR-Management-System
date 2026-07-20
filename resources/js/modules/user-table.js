/**
 * User list: search by name or email, filter by role and status.
 *
 * Filters rows the server already rendered, so nothing here decides who is
 * visible in a security sense — the controller does.
 */
function initTable(root) {
    const search = root.querySelector('[data-user-search]');
    const role = root.querySelector('[data-user-role]');
    const status = root.querySelector('[data-user-status]');
    const clear = root.querySelector('[data-user-clear]');
    const countEl = root.querySelector('[data-user-count]');
    const noMatch = root.querySelector('[data-user-no-match]');
    const rows = () => Array.from(root.querySelectorAll('[data-user-row]'));

    if (!search) {
        return;
    }

    function apply() {
        const term = search.value.trim().toLowerCase();
        const wantRole = role.value;
        const wantStatus = status.value;

        rows().forEach((row) => {
            const matches =
                (!term || (row.dataset.search || '').includes(term)) &&
                (!wantRole || row.dataset.role === wantRole) &&
                (!wantStatus || row.dataset.status === wantStatus);

            row.classList.toggle('d-none', !matches);
        });

        const shown = rows().filter((r) => !r.classList.contains('d-none')).length;
        const total = rows().length;

        countEl.textContent = total ? 'Showing ' + shown + ' of ' + total + ' user(s)' : '';
        noMatch?.classList.toggle('d-none', shown > 0 || total === 0);
    }

    search.addEventListener('input', apply);
    role.addEventListener('change', apply);
    status.addEventListener('change', apply);

    clear.addEventListener('click', () => {
        search.value = '';
        role.value = '';
        status.value = '';
        apply();
    });

    apply();
}

export function initUserTable() {
    document.querySelectorAll('[data-user-table]').forEach(initTable);
}

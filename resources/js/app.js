import './bootstrap';

import { initCountUp } from './modules/count-up';
import { initFileUpload } from './modules/file-upload';
import { initFileTable } from './modules/file-table';
import { initUserTable } from './modules/user-table';
import { initSubmitButtons } from './modules/submit-button';
import { initBulkIssueTable } from './modules/bulk-issue-table';
import { initStockLedgerTable } from './modules/stock-ledger-table';

import Alpine from 'alpinejs';
import AOS from 'aos';
import 'aos/dist/aos.css';
import { registerBulkIssueWizard } from './modules/bulk-issue-wizard';

window.Alpine = Alpine;

// Components must be registered before start() or their x-data never resolves.
registerBulkIssueWizard(Alpine);

Alpine.start();

// Scroll-reveal animations for elements carrying data-aos attributes (skipped
// when the user prefers reduced motion).
AOS.init({
    disable: () => window.matchMedia('(prefers-reduced-motion: reduce)').matches,
});


// Dashboard figures count up when scrolled into view (no-op when the user
// prefers reduced motion).
document.addEventListener('DOMContentLoaded', initCountUp);

// Upgrades the plain upload form to drag-and-drop with real byte progress.
document.addEventListener('DOMContentLoaded', initFileUpload);

// Workspace file list: search, filter, sort, select, export.
document.addEventListener('DOMContentLoaded', initFileTable);
document.addEventListener('DOMContentLoaded', initUserTable);
document.addEventListener('DOMContentLoaded', initSubmitButtons);

// Bulk Issue history: server-driven tabs/search/sort, bulk actions, slide-in.
document.addEventListener('DOMContentLoaded', initBulkIssueTable);

// Stock Report: live search, filters and paging without a page reload.
document.addEventListener('DOMContentLoaded', initStockLedgerTable);

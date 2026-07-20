import './bootstrap';

import { initCountUp } from './modules/count-up';
import { initFileUpload } from './modules/file-upload';
import { initFileTable } from './modules/file-table';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();


// Dashboard figures count up when scrolled into view (no-op when the user
// prefers reduced motion).
document.addEventListener('DOMContentLoaded', initCountUp);

// Upgrades the plain upload form to drag-and-drop with real byte progress.
document.addEventListener('DOMContentLoaded', initFileUpload);

// Workspace file list: search, filter, sort, select, export.
document.addEventListener('DOMContentLoaded', initFileTable);

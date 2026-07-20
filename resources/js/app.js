import './bootstrap';

import { initCountUp } from './modules/count-up';
import { initFileUpload } from './modules/file-upload';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();


// Dashboard figures count up when scrolled into view (no-op when the user
// prefers reduced motion).
document.addEventListener('DOMContentLoaded', initCountUp);

// Upgrades the plain upload form to drag-and-drop with real byte progress.
document.addEventListener('DOMContentLoaded', initFileUpload);

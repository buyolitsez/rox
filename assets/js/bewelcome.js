import * as bootstrap from 'bootstrap'

import '../../public/script/common/common.js';

import '../scss/bewelcome.scss';
import '../scss/cookie-consent.scss';
import '@fortawesome/fontawesome-free/js/all.js';
import './collapsemenu.js';
import './member-menu-dropdown.js';
import './tom-select.js';
import './analytics.js';

window.bootstrap = bootstrap;


document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.toast').forEach(toastNode => {
        const toast = new window.bootstrap.Toast(toastNode);
        toast.show();
    });
});

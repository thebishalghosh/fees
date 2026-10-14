(() => {
    'use strict';

    const nav = document.getElementById('site-nav');
    const updateNavOnScroll = () => {
        if (nav && nav.classList.contains('site-navbar-public')) {
            nav.classList.toggle('scrolled', window.scrollY > 40);
        }
    };

    updateNavOnScroll();
    window.addEventListener('scroll', updateNavOnScroll, { passive: true });

    document.querySelectorAll('#site-menu .nav-link[href^="#"]').forEach((link) => {
        link.addEventListener('click', () => {
            const menu = document.getElementById('site-menu');
            if (menu && menu.classList.contains('show') && window.bootstrap) {
                window.bootstrap.Collapse.getOrCreateInstance(menu).hide();
            }
        });
    });
})();

// TripVerse — shared front-end behaviour for the customer pages.

document.addEventListener('DOMContentLoaded', function () {

    /* ------------------------------------------------------------------
     * Scroll reveal for .tv-reveal / -scale / -left / -right
     * ---------------------------------------------------------------- */
    var targets = document.querySelectorAll(
        '.tv-reveal, .tv-reveal-scale, .tv-reveal-left, .tv-reveal-right'
    );

    if (targets.length) {
        if (!('IntersectionObserver' in window)) {
            targets.forEach(function (el) { el.classList.add('tv-in'); });
        } else {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('tv-in');
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });

            targets.forEach(function (el) { observer.observe(el); });
        }
    }

    /* ------------------------------------------------------------------
     * Account menu
     *
     * Driven here rather than by Bootstrap's dropdown data-API: the pages
     * load jQuery 3.4 and tempusdominus (a Bootstrap 4 plugin) next to
     * Bootstrap 5, and the competing handlers toggled the menu twice per
     * click, so it opened and closed again immediately.
     * ---------------------------------------------------------------- */
    var account = document.querySelector('[data-tv-account]');
    if (!account) return;

    var toggle = account.querySelector('.tv-account-toggle');
    var menu = account.querySelector('.tv-account-menu');
    if (!toggle || !menu) return;

    function openMenu() {
        account.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
    }

    function closeMenu() {
        account.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    function isOpen() {
        return account.classList.contains('is-open');
    }

    toggle.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        isOpen() ? closeMenu() : openMenu();
    });

    // click anywhere outside closes it
    document.addEventListener('click', function (e) {
        if (isOpen() && !account.contains(e.target)) closeMenu();
    });

    // Esc closes and returns focus to the trigger
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) {
            closeMenu();
            toggle.focus();
        }
    });

    // arrow keys walk the menu items
    var items = Array.prototype.slice.call(menu.querySelectorAll('.tv-account-item'));

    toggle.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            openMenu();
            if (items[0]) items[0].focus();
        }
    });

    menu.addEventListener('keydown', function (e) {
        var i = items.indexOf(document.activeElement);
        if (i === -1) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            (items[i + 1] || items[0]).focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            (items[i - 1] || items[items.length - 1]).focus();
        }
    });
});

/* ----------------------------------------------------------------------
 * Password change: live strength meter + requirement checklist.
 * Mirrors the server-side rules in profile_customer.php so the user is
 * never surprised by a rejection after submitting.
 * -------------------------------------------------------------------- */
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('passwordForm');
    if (!form) return;

    var pw = document.getElementById('new_password');
    var confirmPw = document.getElementById('confirm_password');
    var bar = document.getElementById('pwBar');
    var label = document.getElementById('pwLabel');
    var rules = document.getElementById('pwRules');
    var submit = document.getElementById('pwSubmit');
    if (!pw || !confirmPw || !bar || !rules) return;

    var LEVELS = [
        { text: 'Terlalu lemah', color: '#DC2626' },
        { text: 'Lemah', color: '#DC2626' },
        { text: 'Cukup', color: '#F59E0B' },
        { text: 'Kuat', color: '#FEA116' },
        { text: 'Sangat kuat', color: '#16A34A' }
    ];

    function evaluate() {
        var v = pw.value;
        var checks = {
            len: v.length >= 8,
            upper: /[A-Z]/.test(v),
            num: /[0-9]/.test(v),
            spec: /[^A-Za-z0-9]/.test(v),
            match: v !== '' && v === confirmPw.value
        };

        Object.keys(checks).forEach(function (key) {
            var li = rules.querySelector('[data-rule="' + key + '"]');
            if (!li) return;
            var icon = li.querySelector('i');
            li.classList.toggle('ok', checks[key]);
            if (icon) {
                icon.className = checks[key] ? 'fas fa-check-circle' : 'fas fa-circle';
            }
        });

        var score = ['len', 'upper', 'num', 'spec'].filter(function (k) {
            return checks[k];
        }).length;

        var level = LEVELS[v === '' ? 0 : score];
        bar.style.width = (v === '' ? 0 : (score / 4) * 100) + '%';
        bar.style.background = level.color;
        if (label) label.textContent = v === '' ? 'Kekuatan kata sandi' : level.text;

        if (submit) {
            var allOk = Object.keys(checks).every(function (k) { return checks[k]; });
            submit.disabled = !allOk;
            submit.style.opacity = allOk ? '1' : '.55';
            submit.style.cursor = allOk ? 'pointer' : 'not-allowed';
        }
    }

    pw.addEventListener('input', evaluate);
    confirmPw.addEventListener('input', evaluate);
    evaluate();
});

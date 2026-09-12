document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.querySelector('.menu-toggle');
  var mobileNav = document.querySelector('.mobile-nav');
  if (toggle && mobileNav) {
    toggle.addEventListener('click', function () {
      mobileNav.classList.toggle('open');
      toggle.setAttribute('aria-expanded', mobileNav.classList.contains('open'));
    });
  }

  // Mark active nav link based on current path
  var path = window.location.pathname.replace(/index\.html$/, '');
  document.querySelectorAll('.main-nav a, .mobile-nav a').forEach(function (a) {
    var href = a.getAttribute('href');
    if (!href) return;
    var normalized = href.replace(/index\.html$/, '');
    if (normalized !== '/' && normalized !== '' && (path.indexOf(normalized) === 0 || path === normalized)) {
      a.classList.add('active');
    } else if (normalized === '/' && (path === '/' || path === '')) {
      a.classList.add('active');
    }
  });

  // Copy-to-clipboard for code blocks with data-copy
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var target = document.querySelector(btn.getAttribute('data-copy'));
      if (!target) return;
      navigator.clipboard.writeText(target.innerText).then(function () {
        var original = btn.textContent;
        btn.textContent = 'Copied';
        setTimeout(function () { btn.textContent = original; }, 1500);
      });
    });
  });

  // Subtle parallax tilt on the hero inspector panel (single orchestrated moment)
  var stage = document.querySelector('.inspector-stage');
  var panel = document.querySelector('.inspector-panel');
  if (stage && panel && window.matchMedia('(min-width: 980px)').matches && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    stage.addEventListener('mousemove', function (e) {
      var r = stage.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - 0.5;
      var y = (e.clientY - r.top) / r.height - 0.5;
      panel.style.transform = 'rotateX(' + (8 - y * 10) + 'deg) rotateY(' + (-14 + x * 14) + 'deg)';
    });
    stage.addEventListener('mouseleave', function () {
      panel.style.transform = '';
    });
  }
});

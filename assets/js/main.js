/**
* Template Name: EstateAgency
* Template URL: https://bootstrapmade.com/real-estate-agency-bootstrap-template/
* Updated: Aug 09 2024 with Bootstrap v5.3.3
* Author: BootstrapMade.com
* License: https://bootstrapmade.com/license/
*/

(function() {
  "use strict";

  /**
   * Apply .scrolled class to the body as the page is scrolled down
   */
  function toggleScrolled() {
    const selectBody = document.querySelector('body');
    const selectHeader = document.querySelector('#header');
    // if there's no header, nothing to do
    if (!selectHeader) return;

    // toggle .scrolled on body when page is scrolled more than 100px
    if (window.scrollY > 100) {
      selectBody.classList.add('scrolled');
    } else {
      selectBody.classList.remove('scrolled');
    }
  }

  document.addEventListener('scroll', toggleScrolled);
  window.addEventListener('load', toggleScrolled);

  /**
   * Mobile nav toggle
   */
  const mobileNavToggleBtn = document.querySelector('.mobile-nav-toggle');

  function mobileNavToogle() {
    document.querySelector('body').classList.toggle('mobile-nav-active');
    mobileNavToggleBtn.classList.toggle('bi-list');
    mobileNavToggleBtn.classList.toggle('bi-x');
  }
  if (mobileNavToggleBtn) {
    mobileNavToggleBtn.addEventListener('click', mobileNavToogle);
  }

  /**
   * Hide mobile nav on same-page/hash links
   */
  document.querySelectorAll('#navmenu a').forEach(navmenu => {
    navmenu.addEventListener('click', () => {
      if (document.querySelector('.mobile-nav-active')) {
        mobileNavToogle();
      }
    });

  });

  /**
   * Toggle mobile nav dropdowns
   */
  document.querySelectorAll('.navmenu .toggle-dropdown').forEach(navmenu => {
    navmenu.addEventListener('click', function(e) {
      e.preventDefault();
      this.parentNode.classList.toggle('active');
      this.parentNode.nextElementSibling.classList.toggle('dropdown-active');
      e.stopImmediatePropagation();
    });
  });

  /**
   * Preloader
   */
  const preloader = document.querySelector('#preloader');
  if (preloader) {
    // Prefer hiding the preloader as soon as the page is interactive
    // (DOMContentLoaded or the first hero image ready) instead of waiting
    // for the full window "load" which can be delayed by large assets.
    let preloaderHidden = false;

    const doHidePreloader = () => {
      if (preloaderHidden) return;
      preloaderHidden = true;
      // add class for a short fade-out, then remove from DOM after transition
      preloader.classList.add('preloader-hidden');
      setTimeout(() => {
        try { preloader.remove(); } catch (e) {}
      }, 350);
    };

    // Hide on DOMContentLoaded (fast) or when the hero first image has loaded.
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', doHidePreloader, { once: true });
    } else {
      // already interactive
      setTimeout(doHidePreloader, 40);
    }

    // if hero image exists, hide when it's loaded (improves LCP perceived)
    const heroImg = document.querySelector('#hero-carousel .carousel-item.active img');
    if (heroImg) {
      if (heroImg.complete) {
        // already loaded
        doHidePreloader();
      } else {
        heroImg.addEventListener('load', doHidePreloader, { once: true });
        heroImg.addEventListener('error', doHidePreloader, { once: true });
      }
    }

    // Fallback: ensure it never stays forever (max wait)
    setTimeout(doHidePreloader, 1200);

    // Also keep the old window.load listener as a final fallback
    window.addEventListener('load', doHidePreloader);
  }

  /**
   * Scroll top button
   */
  let scrollTop = document.querySelector('.scroll-top');

  function toggleScrollTop() {
    if (scrollTop) {
      window.scrollY > 100 ? scrollTop.classList.add('active') : scrollTop.classList.remove('active');
    }
  }
  if (scrollTop) {
    scrollTop.addEventListener('click', (e) => {
      e.preventDefault();
      window.scrollTo({
        top: 0,
        behavior: 'smooth'
      });
    });
  }

  window.addEventListener('load', toggleScrollTop);
  document.addEventListener('scroll', toggleScrollTop);

  /**
   * Animation on scroll function and init
   */
  function aosInit() {
    AOS.init({
      duration: 600,
      easing: 'ease-in-out',
      once: true,
      mirror: false
    });
  }
  window.addEventListener('load', aosInit);

  /**
   * Auto generate the carousel indicators
   */
  document.querySelectorAll('.carousel-indicators').forEach((carouselIndicator) => {
    carouselIndicator.closest('.carousel').querySelectorAll('.carousel-item').forEach((carouselItem, index) => {
      if (index === 0) {
        carouselIndicator.innerHTML += `<li data-bs-target="#${carouselIndicator.closest('.carousel').id}" data-bs-slide-to="${index}" class="active"></li>`;
      } else {
        carouselIndicator.innerHTML += `<li data-bs-target="#${carouselIndicator.closest('.carousel').id}" data-bs-slide-to="${index}"></li>`;
      }
    });
  });

  /**
   * Init swiper sliders
   */
  function initSwiper() {
    document.querySelectorAll(".init-swiper").forEach(function(swiperElement) {
      let config = JSON.parse(
        swiperElement.querySelector(".swiper-config").innerHTML.trim()
      );

      if (swiperElement.classList.contains("swiper-tab")) {
        initSwiperWithCustomPagination(swiperElement, config);
      } else {
        new Swiper(swiperElement, config);
      }
    });
  }

  window.addEventListener("load", initSwiper);

  // ...existing code...
  function initCounters() {
    const counters = Array.from(document.querySelectorAll('.counter'));
    if (!counters.length) return;

  // Einstellungen: min/max Dauer (ms)
  const MIN_DURATION = 4000;   // kürzeste Laufzeit (schnell)
  const MAX_DURATION = 10000;  // längste Laufzeit (langsam)

    // helpers
    const easeOutCubic = t => 1 - Math.pow(1 - t, 3);
    const parseTarget = el => parseInt(String(el.getAttribute('data-to') || el.textContent).replace(/\D/g, ''), 10) || 0;

    // Max-Zielwert für Normalisierung
    const maxTarget = Math.max(...counters.map(parseTarget), 1);

    // Berechne Dauer pro Element (respektiert explizites data-speed)
    counters.forEach((el, idx) => {
      const explicit = parseInt(el.getAttribute('data-speed'), 10);
      if (!isNaN(explicit) && explicit > 0) {
        el._counterDuration = explicit;
        return;
      }
  const target = parseTarget(el);
  const pTarget = target / maxTarget; // 0..1
  // Dauer nur abhängig von der Zielzahl (höhere Zahl → längere Dauer)
  el._counterDuration = Math.round(MIN_DURATION + (MAX_DURATION - MIN_DURATION) * pTarget);
    });

    const animate = (el, duration) => {
      if (el.dataset.animated) return;
      const rawTarget = el.getAttribute('data-to') || el.textContent;
      const target = parseInt(String(rawTarget).replace(/\D/g, ''), 10) || 0;
      const start = 0;
      let startTime = null;

      const step = (ts) => {
        if (!startTime) startTime = ts;
        const elapsed = ts - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const eased = easeOutCubic(progress);
        const current = Math.ceil(start + (target - start) * eased);
        el.textContent = current;
        if (progress < 1) {
          requestAnimationFrame(step);
        } else {
          el.dataset.animated = 'true';
          el.textContent = target;
        }
      };
      requestAnimationFrame(step);
    };

    // IntersectionObserver (reagiert zuverlässiger auf mobile, rootMargin erleichtert Trigger)
    const io = new IntersectionObserver((entries, observer) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          const el = entry.target;
          const duration = el._counterDuration || MIN_DURATION;
          animate(el, duration);
          observer.unobserve(el);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -10% 0px' });

    // Starten / beobachten
    counters.forEach(c => {
      // falls schon sichtbar -> sofort animieren
      const rect = c.getBoundingClientRect();
      const inViewport = rect.top < window.innerHeight && rect.bottom >= 0;
      const duration = c._counterDuration || MIN_DURATION;
      if (inViewport) {
        animate(c, duration);
      } else {
        io.observe(c);
      }
    });

    // Fallback: nach Resize / Orientation neu prüfen (mobile)
    const refreshCheck = () => counters.forEach(c => {
      if (c.dataset.animated) return;
      const rect = c.getBoundingClientRect();
      if (rect.top < window.innerHeight && rect.bottom >= 0) {
        const duration = c._counterDuration || MIN_DURATION;
        animate(c, duration);
      }
    });
    window.addEventListener('resize', refreshCheck);
    window.addEventListener('orientationchange', () => setTimeout(refreshCheck, 150));
  }

  window.addEventListener('load', initCounters);

  // Contact form AJAX submission + toast + remember
  function initContactForm() {
    const form = document.querySelector('.php-email-form');
    if (!form) return;

    const loading = form.querySelector('.loading');
    const errorEl = form.querySelector('.error-message');
    const sentEl = form.querySelector('.sent-message');

    // restore saved name/email
    try {
      const saved = JSON.parse(localStorage.getItem('contact_form') || '{}');
      if (saved.name) form.querySelector('[name="name"]').value = saved.name;
      if (saved.email) form.querySelector('[name="email"]').value = saved.email;
    } catch (e) {}

    form.addEventListener('submit', function(e) {
      e.preventDefault();
      if (loading) loading.style.display = 'block';
      if (errorEl) errorEl.style.display = 'none';
      if (sentEl) sentEl.style.display = 'none';

      const fd = new FormData(form);

      fetch(form.action, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
          if (loading) loading.style.display = 'none';
          if (data && data.success) {
            if (sentEl) sentEl.style.display = 'block';
            showContactToast(data.message || 'Ihre Nachricht wurde gesendet.');
            // remember name/email
            try {
              const toSave = { name: form.querySelector('[name="name"]').value || '', email: form.querySelector('[name="email"]').value || '' };
              localStorage.setItem('contact_form', JSON.stringify(toSave));
            } catch (e) {}
            // clear message field
            const msg = form.querySelector('[name="message"]'); if (msg) msg.value = '';
          } else {
            const msg = (data && data.message) ? data.message : 'Fehler beim Senden';
            if (errorEl) { errorEl.textContent = msg; errorEl.style.display = 'block'; }
            showContactToast(msg, true);
          }
        })
        .catch(err => {
          if (loading) loading.style.display = 'none';
          const msg = 'Fehler beim Senden (Netzwerk)';
          if (errorEl) { errorEl.textContent = msg; errorEl.style.display = 'block'; }
          showContactToast(msg, true);
        });
    });
  }

  function showContactToast(message, isError) {
    const toastEl = document.getElementById('contactToast');
    if (!toastEl) return;
    const bsToast = bootstrap.Toast.getOrCreateInstance(toastEl);
    const body = toastEl.querySelector('.toast-body');
    if (body) body.textContent = message;
    toastEl.classList.remove('bg-success','bg-danger');
    toastEl.classList.add(isError ? 'bg-danger' : 'bg-success');
    bsToast.show();
  }
  
  window.addEventListener('load', initContactForm);

})();



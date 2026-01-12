// ------------------
// MENU BURGER
// ------------------
function toggleMenu() {
  const menu = document.getElementById('mobileMenu');
  menu.style.display = (menu.style.display === 'flex') ? 'none' : 'flex';
}

// Fermer le menu si on clique ailleurs
document.addEventListener('click', function(e) {
  const menu = document.getElementById('mobileMenu');
  const button = document.getElementById('hamburger');

  if (menu && menu.style.display === 'flex' && !menu.contains(e.target) && !button.contains(e.target)) {
    menu.style.display = 'none';
  }
});

const prefersReducedMotion = typeof window !== 'undefined' && window.matchMedia
  ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
  : false;

function initTricoloreSoundtrack() {
  const audio = document.getElementById('marseillaise');
  if (!audio) return;

  const gate = document.getElementById('sound-gate');
  const button = document.getElementById('soundGateBtn');
  audio.loop = true;
  audio.volume = 0.4;

  const hideGate = () => {
    if (!gate) return;
    gate.classList.add('hidden');
    window.setTimeout(() => {
      try {
        gate.setAttribute('hidden', '');
      } catch (err) {
        // ignore
      }
    }, 320);
  };

  const showGate = () => {
    if (!gate) return;
    gate.removeAttribute('hidden');
    gate.classList.remove('hidden');
  };

  const attemptPlay = () => {
    try {
      const playPromise = audio.play();
      if (playPromise && typeof playPromise.then === 'function') {
        playPromise.then(() => {
          audio.muted = false;
          hideGate();
        }).catch(() => {
          audio.pause();
          showGate();
        });
      } else {
        hideGate();
      }
    } catch (err) {
      showGate();
    }
  };

  attemptPlay();

  if (button) {
    button.addEventListener('click', () => {
      audio.muted = false;
      audio.currentTime = 0;
      audio.play().then(hideGate).catch(showGate);
    });
  }

  if (gate) {
    gate.addEventListener('click', (event) => {
      if (event.target === gate) {
        audio.muted = false;
        audio.play().then(hideGate).catch(showGate);
      }
    });
  }
}

function initSectionObserver() {
  if (typeof IntersectionObserver === 'undefined') {
    const sections = document.querySelectorAll('[data-section]');
    sections.forEach((section) => section.classList.add('is-visible'));
    return;
  }

  const observer = new IntersectionObserver((entries, instance) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        instance.unobserve(entry.target);
      }
    });
  }, { threshold: 0.2 });

  document.querySelectorAll('[data-section]').forEach((section) => observer.observe(section));
}

function initSpectacleCounters() {
  const counters = document.querySelectorAll('.flag-stat');
  if (!counters.length) return;

  const animateCounter = (node) => {
    const targetValue = parseFloat(node.getAttribute('data-counter') || '0');
    if (!targetValue) return;
    const valueNode = node.querySelector('.flag-stat__value');
    if (!valueNode) return;

    const suffix = node.getAttribute('data-suffix') || '';
    const prefix = node.getAttribute('data-prefix') || '';
    const duration = 1800;
    const start = performance.now();

    const update = (timestamp) => {
      const progress = Math.min((timestamp - start) / duration, 1);
      const eased = progress < 1 ? 1 - Math.pow(1 - progress, 3) : 1;
      const current = Math.round(eased * targetValue);
      valueNode.textContent = prefix + current.toLocaleString('fr-FR') + suffix;
      if (progress < 1) {
        requestAnimationFrame(update);
      }
    };

    requestAnimationFrame(update);
  };

  if (typeof IntersectionObserver === 'undefined') {
    counters.forEach(animateCounter);
    return;
  }

  const observer = new IntersectionObserver((entries, instance) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        animateCounter(entry.target);
        instance.unobserve(entry.target);
      }
    });
  }, { threshold: 0.6 });

  counters.forEach((node) => observer.observe(node));
}

function initParallaxLayers() {
  if (prefersReducedMotion) return;
  const elements = document.querySelectorAll('[data-depth]');
  if (!elements.length) return;

  let pointerX = 0.5;
  let pointerY = 0.5;
  let raf = null;

  const render = () => {
    elements.forEach((el) => {
      const depth = parseFloat(el.getAttribute('data-depth') || '0');
      if (!depth) return;
      const tx = (pointerX - 0.5) * depth * 60;
      const ty = (pointerY - 0.5) * depth * 40;
      el.style.transform = 'translate3d(' + tx.toFixed(2) + 'px, ' + ty.toFixed(2) + 'px, 0)';
    });
    raf = null;
  };

  const schedule = () => {
    if (raf) return;
    raf = requestAnimationFrame(render);
  };

  window.addEventListener('pointermove', (event) => {
    if (event.pointerType && event.pointerType !== 'mouse') return;
    pointerX = event.clientX / window.innerWidth;
    pointerY = event.clientY / window.innerHeight;
    schedule();
  }, { passive: true });
}

function initFlagTilt() {
  if (prefersReducedMotion) return;
  const items = document.querySelectorAll('[data-flag-tilt]');
  if (!items.length) return;

  const maxTilt = 8;

  items.forEach((item) => {
    const originalShadow = window.getComputedStyle(item).boxShadow;

    item.addEventListener('pointermove', (event) => {
      if (event.pointerType && event.pointerType !== 'mouse') return;
      const rect = item.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = (event.clientY - rect.top) / rect.height;
      const tiltX = (0.5 - y) * maxTilt;
      const tiltY = (x - 0.5) * maxTilt;
      item.style.transform = 'perspective(800px) rotateX(' + tiltX.toFixed(2) + 'deg) rotateY(' + tiltY.toFixed(2) + 'deg) translate3d(0,0,14px)';
      item.style.boxShadow = '0 22px 48px rgba(0, 18, 51, 0.45)';
    });

    item.addEventListener('pointerleave', () => {
      item.style.transform = '';
      item.style.boxShadow = originalShadow;
    });
  });
}

function initFlagCanvas() {
  if (prefersReducedMotion) return;
  const canvas = document.getElementById('flagCanvas');
  if (!canvas || !canvas.getContext) return;

  const ctx = canvas.getContext('2d');
  const colors = [
    'rgba(0, 38, 84, 0.55)',
    'rgba(255, 255, 255, 0.6)',
    'rgba(206, 17, 38, 0.55)'
  ];
  let particles = [];

  const resize = () => {
    const rect = canvas.getBoundingClientRect();
    canvas.width = rect.width;
    canvas.height = rect.height;
    const count = Math.max(60, Math.floor((rect.width * rect.height) / 42000));
    particles = [];
    for (let i = 0; i < count; i += 1) {
      particles.push({
        x: Math.random() * rect.width,
        y: Math.random() * rect.height,
        size: Math.random() * 3 + 1,
        vx: (Math.random() - 0.5) * 0.6,
        vy: (Math.random() - 0.5) * 0.6,
        alpha: Math.random() * 0.35 + 0.2,
        color: colors[i % colors.length]
      });
    }
  };

  const draw = () => {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    particles.forEach((particle) => {
      particle.x += particle.vx;
      particle.y += particle.vy;

      if (particle.x < -10) particle.x = canvas.width + 10;
      if (particle.x > canvas.width + 10) particle.x = -10;
      if (particle.y < -10) particle.y = canvas.height + 10;
      if (particle.y > canvas.height + 10) particle.y = -10;

      ctx.beginPath();
      ctx.globalAlpha = particle.alpha;
      ctx.fillStyle = particle.color;
      ctx.arc(particle.x, particle.y, particle.size, 0, Math.PI * 2);
      ctx.fill();
    });
    ctx.globalAlpha = 1;
    requestAnimationFrame(draw);
  };

  resize();
  window.addEventListener('resize', resize);
  requestAnimationFrame(draw);
}

function initFrenchSpectacle() {
  initTricoloreSoundtrack();
  initSectionObserver();
  initSpectacleCounters();
  initParallaxLayers();
  initFlagTilt();
  initFlagCanvas();
}

if (document.readyState !== 'loading') {
  initFrenchSpectacle();
} else {
  document.addEventListener('DOMContentLoaded', initFrenchSpectacle);
}


// ------------------
// CAROUSEL
// ------------------
(function(){
  const track = document.getElementById('carousel-track');
  const dotsRoot = document.getElementById('carousel-dots');
  if (!track || !dotsRoot) return; // sécurité si pas de carrousel

  const slides = Array.from(track.children);
  let index = 0;
  let autoScrollTimeout; // ✅ timer stocké

  // Générer les dots
slides.forEach((_, i) => {
  const dot = document.createElement('button');
  dot.setAttribute('aria-label', `Aller à la diapositive ${i + 1}`); // <-- accessible
  if (i === 0) dot.classList.add('active', `slide-${i+1}`);
  dot.addEventListener('click', () => { 
    index = i; 
    update(); 
  });
  dotsRoot.appendChild(dot);
});


  const dots = Array.from(dotsRoot.children);

  function updateDots(index) {
    dots.forEach(d => {
      d.classList.remove('active', 'slide-1', 'slide-2', 'slide-3');
    });
    dots[index].classList.add('active', `slide-${index+1}`);
  }

  // 🔥 relance les animations CSS
function resetAnimations(slide) {
  const animElems = Array.from(slide.querySelectorAll('.animatable'));
  
  if (animElems.length === 0) return;

  // 1️⃣ Désactivation de l'animation
  animElems.forEach(el => el.style.animation = 'none');

  // 2️⃣ Lecture forcée regroupée pour éviter plusieurs reflows
  // On lit toutes les hauteurs en une seule fois
  animElems.forEach(el => el.getBoundingClientRect());

  // 3️⃣ Réactivation de l'animation
  animElems.forEach(el => el.style.animation = '');
}


  function update() {
    track.style.transform = `translateX(-${index * 100}%)`;
    updateDots(index);

    slides.forEach(s => s.classList.remove('active'));
    slides[index].classList.add('active');

    const carousel = document.querySelector('.carousel');
    carousel.classList.remove('active-slide-1', 'active-slide-2', 'active-slide-3');
    carousel.classList.add(`active-slide-${index + 1}`);

    resetAnimations(slides[index]);

    // ✅ reset auto scroll
    resetAutoScroll();
  }

  // Navigation
  const prevBtn = document.getElementById('prev-btn');
  if (prevBtn) {
    prevBtn.addEventListener('click', () => {
      index = (index - 1 + slides.length) % slides.length;
      update();
    });
  }

  const nextBtn = document.getElementById('next-btn');
  if (nextBtn) {
    nextBtn.addEventListener('click', () => {
      index = (index + 1) % slides.length;
      update();
    });
  }

  // ✅ Auto scroll avec reset
  function resetAutoScroll() {
    clearTimeout(autoScrollTimeout);
    autoScrollTimeout = setTimeout(() => { 
      index = (index + 1) % slides.length; 
      update(); 
    }, 6000); // 8s
  }

document.addEventListener("DOMContentLoaded", () => {
  const slides = document.querySelectorAll(".promo-slide");
  let current = 0;
  const duration = 5000; // 5s par slide

  // Duplication auto pour remplir (≥ 2x viewport)
  slides.forEach(slide => {
    const track = slide.querySelector(".promo-track");
    if (!track) return;

    // 1) Normalise : on met le contenu dans .promo-item s'il n'existe pas
    let item = track.querySelector(".promo-item");
    if (!item) {
      const content = track.textContent.trim();
      track.textContent = "";
      item = document.createElement("span");
      item.className = "promo-item";
      item.textContent = content;
      track.appendChild(item);
    }

    // 2) Duplique jusqu'à couvrir 2× la largeur de l'écran
    while (track.scrollWidth < window.innerWidth * 3) {
      track.appendChild(item.cloneNode(true));
    }
  });

  function showSlide(index) {
    slides.forEach((slide, i) => {
      slide.classList.toggle("active", i === index);
      slide.style.backgroundColor = slide.dataset.color;

      const bar = slide.querySelector(".progress-bar");
      bar.style.transition = "none";
      bar.style.width = "0%";
      if (i === index) {
        requestAnimationFrame(() => {
          bar.style.transition = `width ${duration}ms linear`;
          bar.style.width = "100%";
        });
      }
    });
  }

  function nextSlide() {
    current = (current + 1) % slides.length;
    showSlide(current);
  }

  showSlide(current);
  setInterval(nextSlide, duration);

  // (Optionnel) si on redimensionne, on ajoute des clones si besoin
  let t;
  window.addEventListener("resize", () => {
    clearTimeout(t);
    t = setTimeout(() => {
      slides.forEach(slide => {
        const track = slide.querySelector(".promo-track");
        const item = track?.querySelector(".promo-item");
        if (!track || !item) return;
        while (track.scrollWidth < window.innerWidth * 2) {
          track.appendChild(item.cloneNode(true));
        }
      });
    }, 150);
  });
});

// ------------------
// CAROUSEL SIMPLE (témoignages)
// ------------------
const testimonials = document.querySelectorAll(".testimonial");
const navDots = document.querySelectorAll(".carousel-nav .dot");
let currentTestimonial = 0;

function showTestimonial(index) {
  testimonials.forEach((t, i) => {
    t.classList.toggle("active", i === index);
    navDots[i]?.classList.toggle("active", i === index);
  });
}

// clic sur un dot
navDots.forEach((dot, i) => {
  dot.addEventListener("click", () => {
    currentTestimonial = i;
    showTestimonial(currentTestimonial);
  });
});

// auto défilement toutes les 5s
setInterval(() => {
  currentTestimonial = (currentTestimonial + 1) % testimonials.length;
  showTestimonial(currentTestimonial);
}, 5000);

// initialisation
if (testimonials.length && navDots.length) {
  showTestimonial(0);
}


  // ✅ Initialisation
  update();

})();

// ------------------
// OPTIONS DATA LOADER (cached)
// ------------------
(function(){
  const API_URL = '/api/get_options_full.php';
  const VERSION_URL = '/api/options_cache_version.php';
  const CACHE_NAMESPACE = 'ripair_options_cache_v';
  const CACHE_TTL = 12 * 60 * 60 * 1000; // 12h
  const collator = typeof Intl !== 'undefined'
    ? new Intl.Collator('fr', { sensitivity: 'base', ignorePunctuation: true })
    : null;

  let cacheVersion = null;
  let cacheKey = `${CACHE_NAMESPACE}1`;
  let facadeCache = null;
  let pendingRequest = null;
  let versionPromise = null;

  const compare = (a, b) => {
    if (collator) {
      return collator.compare(a, b);
    }
    return String(a).toLowerCase().localeCompare(String(b).toLowerCase());
  };

  const sanitize = (value) => {
    if (value === null || value === undefined) return '';
    return String(value).trim();
  };

  const cleanupLocalCache = (keepKey = null) => {
    if (typeof localStorage === 'undefined') return;
    try {
      const toDelete = [];
      for (let i = 0; i < localStorage.length; i += 1) {
        const key = localStorage.key(i);
        if (key && key.startsWith(CACHE_NAMESPACE) && key !== keepKey) {
          toDelete.push(key);
        }
      }
      toDelete.forEach(key => localStorage.removeItem(key));
    } catch (err) {
      console.warn('[RipairOptions] cleanup failed', err);
    }
  };

  const setCacheVersion = (value) => {
    const normalized = Number.isFinite(value) && value > 0 ? Math.floor(value) : 1;
    if (cacheVersion !== normalized) {
      cacheVersion = normalized;
      cacheKey = `${CACHE_NAMESPACE}${cacheVersion}`;
      facadeCache = null;
      cleanupLocalCache(cacheKey);
    }
  };

  const ensureVersion = async () => {
    if (cacheVersion !== null) {
      return cacheVersion;
    }
    if (versionPromise) {
      return versionPromise;
    }

    versionPromise = fetch(VERSION_URL, { cache: 'no-store' })
      .then(async response => {
        if (!response.ok) {
          throw new Error(`HTTP ${response.status}`);
        }
        const payload = await response.json().catch(() => null);
        const candidate = payload && Number.parseInt(payload.version, 10);
        setCacheVersion(candidate);
        return cacheVersion;
      })
      .catch(err => {
        console.warn('[RipairOptions] version fetch failed', err);
        setCacheVersion(cacheVersion ?? 1);
        return cacheVersion;
      })
      .finally(() => {
        versionPromise = null;
      });

    return versionPromise;
  };

  const readCache = () => {
    if (cacheVersion === null || typeof localStorage === 'undefined') return null;
    try {
      const raw = localStorage.getItem(cacheKey);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (!parsed || parsed.version !== cacheVersion || !Array.isArray(parsed.records)) return null;
      return parsed;
    } catch (err) {
      console.warn('[RipairOptions] cache read failed', err);
      return null;
    }
  };

  const persistCache = (records, meta, now) => {
    if (cacheVersion === null || typeof localStorage === 'undefined') return;
    try {
      const payload = {
        version: cacheVersion,
        fetchedAt: meta?.fetchedAt ?? now,
        generatedAt: meta?.generatedAt ?? null,
        lastUpdate: meta?.lastUpdate ?? null,
        expires: now + CACHE_TTL,
        records
      };
      localStorage.setItem(cacheKey, JSON.stringify(payload));
      cleanupLocalCache(cacheKey);
    } catch (err) {
      console.warn('[RipairOptions] cache write failed', err);
    }
  };

  const buildFacade = (records, meta = {}) => {
    const tree = Object.create(null);
    const categories = [];

    records.forEach(row => {
      if (!row) return;
      const category = sanitize(row.category);
      const brand = sanitize(row.brand);
      const model = sanitize(row.model);
      const problem = sanitize(row.problem);

      if (!category || !brand || !model || !problem) return;

      if (!tree[category]) {
        tree[category] = {
          brands: [],
          map: Object.create(null)
        };
        categories.push(category);
      }
      const catNode = tree[category];

      if (!catNode.map[brand]) {
        catNode.map[brand] = {
          models: [],
          map: Object.create(null)
        };
        catNode.brands.push(brand);
      }
      const brandNode = catNode.map[brand];

      if (!brandNode.map[model]) {
        brandNode.map[model] = {
          problems: [],
          entries: Object.create(null)
        };
        brandNode.models.push(model);
      }
      const modelNode = brandNode.map[model];

      if (!modelNode.entries[problem]) {
        modelNode.entries[problem] = Object.freeze({
          category,
          brand,
          model,
          problem,
          price: row.price ?? null,
          duration: row.duration ?? null
        });
      }

      if (!modelNode.problems.includes(problem)) {
        modelNode.problems.push(problem);
      }
    });

    categories.sort(compare);
    categories.forEach(category => {
      const catNode = tree[category];
      catNode.brands.sort(compare);
      catNode.brands.forEach(brand => {
        const brandNode = catNode.map[brand];
        brandNode.models.sort(compare);
        brandNode.models.forEach(model => {
          const modelNode = brandNode.map[model];
          modelNode.problems.sort(compare);
        });
      });
    });

    return {
      categories,
      meta: { ...meta, version: cacheVersion },
      records,
      getBrands(category) {
        const node = tree[category];
        return node ? node.brands.slice() : [];
      },
      getModels(category, brand) {
        const node = tree[category]?.map?.[brand];
        return node ? node.models.slice() : [];
      },
      getProblems(category, brand, model) {
        const node = tree[category]?.map?.[brand]?.map?.[model];
        return node ? node.problems.slice() : [];
      },
      getEntry(category, brand, model, problem) {
        return tree[category]?.map?.[brand]?.map?.[model]?.entries?.[problem] || null;
      }
    };
  };

  const fetchRemote = async () => {
    const response = await fetch(API_URL, { cache: 'no-store' });
    if (!response.ok) {
      throw new Error('HTTP ' + response.status);
    }
    const text = await response.text();
    let payload;
    try {
      payload = JSON.parse(text);
    } catch (err) {
      throw new Error('Invalid JSON from options API');
    }
    if (!payload || !payload.success || !Array.isArray(payload.data)) {
      throw new Error(payload?.error || 'options_payload_error');
    }
    const now = Date.now();
    return {
      records: payload.data,
      meta: {
        version: cacheVersion,
        fetchedAt: now,
        generatedAt: payload.generated_at || null,
        lastUpdate: payload.last_update || null,
        source: 'remote',
        stale: false
      }
    };
  };

  const requestFacade = async () => {
    if (pendingRequest) {
      return pendingRequest;
    }
    pendingRequest = fetchRemote()
      .then(({ records, meta }) => {
        const now = meta.fetchedAt || Date.now();
        persistCache(records, meta, now);
        facadeCache = buildFacade(records, meta);
        return facadeCache;
      })
      .catch(err => {
        pendingRequest = null;
        throw err;
      })
      .finally(() => {
        pendingRequest = null;
      });
    return pendingRequest;
  };

  const load = async (options = {}) => {
    await ensureVersion();
    const version = cacheVersion;
    const force = options.force === true;
    const now = Date.now();

    if (!force && facadeCache && facadeCache.meta?.version === version && !facadeCache.meta?.stale) {
      return facadeCache;
    }

    if (!force) {
      const cached = readCache();
      if (cached && Array.isArray(cached.records) && cached.records.length > 0) {
        const isFresh = typeof cached.expires === 'number' && cached.expires > now;
        facadeCache = buildFacade(cached.records, {
          fetchedAt: cached.fetchedAt,
          generatedAt: cached.generatedAt,
          lastUpdate: cached.lastUpdate,
          source: 'cache',
          stale: !isFresh
        });

        if (isFresh) {
          return facadeCache;
        }

        // cache exists but stale: try to refresh in background
        requestFacade().catch(err => {
          console.warn('[RipairOptions] background refresh failed', err);
        });
        return facadeCache;
      }
    }

    try {
      return await requestFacade();
    } catch (err) {
      const fallback = readCache();
      if (fallback && Array.isArray(fallback.records) && fallback.records.length > 0) {
        facadeCache = buildFacade(fallback.records, {
          fetchedAt: fallback.fetchedAt,
          generatedAt: fallback.generatedAt,
          lastUpdate: fallback.lastUpdate,
          source: 'cache',
          stale: true,
          error: err?.message || String(err)
        });
        return facadeCache;
      }
      throw err;
    }
  };

  const invalidate = () => {
    facadeCache = null;
    if (typeof localStorage !== 'undefined') {
      try {
        cleanupLocalCache();
      } catch (err) {
        console.warn('[RipairOptions] cache clear failed', err);
      }
    }
  };

  window.RipairOptions = {
    load,
    invalidate,
    getVersion: () => cacheVersion
  };

  if (typeof document !== 'undefined') {
    try {
      const detail = { loader: window.RipairOptions };
      const evt = (typeof CustomEvent === 'function')
        ? new CustomEvent('ripair:options-ready', { detail })
        : null;
      if (evt) {
        document.dispatchEvent(evt);
      } else if (typeof Event === 'function') {
        document.dispatchEvent(new Event('ripair:options-ready'));
      } else if (document.createEvent) {
        const legacy = document.createEvent('Event');
        legacy.initEvent('ripair:options-ready', true, true);
        document.dispatchEvent(legacy);
      }
    } catch (err) {
      console.warn('[RipairOptions] ready event failed', err);
    }
  }
})();

// ------------------
// AVIS (homepage grid)
// ------------------
(function () {
  function getApiBase() {
    return (location.protocol === 'file:' || location.origin === 'null') ? 'https://ripair.shop/api' : 'api';
  }

  function getStars(rating) {
    const safe = Math.max(1, Math.min(5, Number(rating) || 0));
    return '★'.repeat(safe);
  }

  function getInitial(name) {
    const safe = String(name || '').trim();
    if (!safe) return 'R';
    return safe[0].toUpperCase();
  }

  async function loadHomeReviews() {
    const grid = document.querySelector('.avis-grid');
    if (!grid) return;

    try {
      const API = getApiBase();
      const res = await fetch(API + '/get_reviews.php', { cache: 'no-store' });
      const data = await res.json();
      const reviews = Array.isArray(data?.reviews)
        ? data.reviews
        : (Array.isArray(data) ? data : (data?.result?.reviews || []));

      if (!Array.isArray(reviews) || reviews.length === 0) return;

      const chosen = reviews.slice(0, 3);
      grid.innerHTML = '';

      chosen.forEach((review) => {
        const author = review?.author_name || 'Client RIPAIR';
        const text = review?.text || '';
        const rating = review?.rating || 5;

        const figure = document.createElement('figure');
        figure.className = 'testimonial';

        const avatar = document.createElement('div');
        avatar.className = 'avatar';
        avatar.textContent = getInitial(author);

        const quote = document.createElement('blockquote');
        quote.textContent = `"${String(text).trim()}"`;

        const caption = document.createElement('figcaption');
        caption.textContent = `- ${author}`;

        const stars = document.createElement('div');
        stars.className = 'stars';
        stars.textContent = getStars(rating);

        figure.appendChild(avatar);
        figure.appendChild(quote);
        figure.appendChild(caption);
        figure.appendChild(stars);
        grid.appendChild(figure);
      });
    } catch (error) {
      console.warn('[RipairReviews] home reviews failed', error);
    }
  }

  if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => {
      loadHomeReviews();
    });
  }
})();

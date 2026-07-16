(() => {
  const PAGE_SIZE = 30;

  const grid = document.getElementById('grid');
  const emptyMessage = document.getElementById('empty-message');
  const sentinel = document.getElementById('sentinel');

  const loginDialog = document.getElementById('login-dialog');
  const loginForm = document.getElementById('login-form');
  const loginPassword = document.getElementById('login-password');
  const loginError = document.getElementById('login-error');

  const viewerDialog = document.getElementById('viewer-dialog');
  const viewerMedia = document.getElementById('viewer-media');
  const viewerClose = document.getElementById('viewer-close');
  const viewerPrev = document.getElementById('viewer-prev');
  const viewerNext = document.getElementById('viewer-next');
  const viewerDelete = document.getElementById('viewer-delete');

  let items = [];
  let offset = 0;
  let hasMore = true;
  let isLoading = false;
  let currentIndex = -1;
  let observer = null;

  function apiFetch(path, options = {}) {
    return fetch(path, { ...options, credentials: 'same-origin' });
  }

  function renderCard(item) {
    const card = document.createElement('div');
    card.className = 'card';
    card.dataset.hash = item.hash;

    if (item.is_video) {
      const video = document.createElement('video');
      video.src = item.file_url;
      video.muted = true;
      video.preload = 'metadata';
      card.appendChild(video);

      const badge = document.createElement('span');
      badge.className = 'video-badge';
      badge.textContent = 'VIDEO';
      card.appendChild(badge);
    } else {
      const img = document.createElement('img');
      img.src = item.file_url;
      img.loading = 'lazy';
      img.alt = '';
      card.appendChild(img);
    }

    card.addEventListener('click', () => openViewer(items.indexOf(item)));
    grid.appendChild(card);
  }

  async function loadNextPage() {
    if (isLoading || !hasMore) {
      return;
    }
    isLoading = true;

    const response = await apiFetch(`../api/gallery.php?limit=${PAGE_SIZE}&offset=${offset}`);

    if (response.status === 401) {
      isLoading = false;
      showLogin();
      return;
    }

    const data = await response.json();
    isLoading = false;

    if (!data.success) {
      return;
    }

    for (const item of data.items) {
      items.push(item);
      renderCard(item);
    }

    offset += data.items.length;
    hasMore = data.has_more;
    emptyMessage.hidden = items.length > 0;

    if (!hasMore && observer) {
      observer.disconnect();
    }
  }

  function setupInfiniteScroll() {
    observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        loadNextPage();
      }
    });
    observer.observe(sentinel);
  }

  function showLogin() {
    loginError.hidden = true;
    loginPassword.value = '';
    if (typeof loginDialog.showModal === 'function') {
      loginDialog.showModal();
    }
    loginPassword.focus();
  }

  async function handleLoginSubmit(event) {
    event.preventDefault();

    const response = await apiFetch('../api/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password: loginPassword.value }),
    });
    const data = await response.json();

    if (!data.success) {
      loginError.textContent = data.message || 'ログインに失敗しました。';
      loginError.hidden = false;
      return;
    }

    loginDialog.close();
    loadNextPage();
  }

  function renderViewerMedia(item) {
    viewerMedia.innerHTML = '';

    if (item.is_video) {
      const video = document.createElement('video');
      video.src = item.file_url;
      video.controls = true;
      video.autoplay = true;
      video.loop = true;
      viewerMedia.appendChild(video);
    } else {
      const img = document.createElement('img');
      img.src = item.file_url;
      img.alt = '';
      viewerMedia.appendChild(img);
    }
  }

  function openViewer(index) {
    if (index < 0 || index >= items.length) {
      return;
    }
    currentIndex = index;
    renderViewerMedia(items[currentIndex]);
    if (typeof viewerDialog.showModal === 'function') {
      viewerDialog.showModal();
    }
  }

  function showViewerAt(index) {
    if (index < 0 || index >= items.length) {
      return;
    }
    currentIndex = index;
    renderViewerMedia(items[currentIndex]);
  }

  function closeViewer() {
    viewerMedia.innerHTML = '';
    viewerDialog.close();
    currentIndex = -1;
  }

  async function deleteCurrentItem() {
    if (currentIndex < 0) {
      return;
    }
    const item = items[currentIndex];
    const response = await apiFetch(`../api/gallery.php?hash=${item.hash}`, { method: 'DELETE' });
    const data = await response.json();

    if (!data.success) {
      return;
    }

    const card = grid.querySelector(`[data-hash="${item.hash}"]`);
    if (card) {
      card.remove();
    }
    items.splice(currentIndex, 1);
    offset -= 1;
    emptyMessage.hidden = items.length > 0;

    if (items.length === 0) {
      closeViewer();
      return;
    }

    showViewerAt(Math.min(currentIndex, items.length - 1));
  }

  loginForm.addEventListener('submit', handleLoginSubmit);
  viewerClose.addEventListener('click', closeViewer);
  viewerPrev.addEventListener('click', () => showViewerAt(currentIndex - 1));
  viewerNext.addEventListener('click', () => showViewerAt(currentIndex + 1));
  viewerDelete.addEventListener('click', deleteCurrentItem);

  document.addEventListener('keydown', (event) => {
    if (!viewerDialog.open) {
      return;
    }
    if (event.key === 'ArrowLeft') {
      showViewerAt(currentIndex - 1);
    } else if (event.key === 'ArrowRight') {
      showViewerAt(currentIndex + 1);
    } else if (event.key === 'Escape') {
      closeViewer();
    }
  });

  setupInfiniteScroll();
  loadNextPage();
})();

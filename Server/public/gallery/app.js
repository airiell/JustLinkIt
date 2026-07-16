(() => {
  const PAGE_SIZE = 30;

  const grid = document.getElementById('grid');
  const emptyMessage = document.getElementById('empty-message');
  const loadingMessage = document.getElementById('loading-message');
  const sentinel = document.getElementById('sentinel');

  const loginDialog = document.getElementById('login-dialog');
  const loginForm = document.getElementById('login-form');
  const loginPassword = document.getElementById('login-password');
  const loginError = document.getElementById('login-error');

  const viewerDialog = document.getElementById('viewer-dialog');
  const viewerMedia = document.getElementById('viewer-media');
  const viewerDate = document.getElementById('viewer-date');
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

  function formatDate(sqlDateTime) {
    // SQLiteのdatetime('now')はUTCの "YYYY-MM-DD HH:MM:SS"。
    // ISO形式に変換してから解釈させることでローカル時刻に正しく変換する。
    const date = new Date(sqlDateTime.replace(' ', 'T') + 'Z');
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}/${pad(date.getMonth() + 1)}/${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
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

    const dateLabel = document.createElement('div');
    dateLabel.className = 'upload-date';
    dateLabel.textContent = formatDate(item.created_at);
    card.appendChild(dateLabel);

    const copyButton = document.createElement('button');
    copyButton.type = 'button';
    copyButton.className = 'copy-button';
    copyButton.textContent = '🔗';
    copyButton.setAttribute('aria-label', 'URLをコピー');
    copyButton.addEventListener('click', (event) => {
      // カード全体のクリック（ビューアーを開く動作）を発火させない
      event.stopPropagation();
      copyUrlToClipboard(item.url, copyButton);
    });
    card.appendChild(copyButton);

    card.addEventListener('click', () => openViewer(items.indexOf(item)));
    grid.appendChild(card);
  }

  async function copyUrlToClipboard(url, button) {
    try {
      await navigator.clipboard.writeText(url);
      const original = button.textContent;
      button.textContent = '✓';
      button.classList.add('copied');
      setTimeout(() => {
        button.textContent = original;
        button.classList.remove('copied');
      }, 1200);
    } catch (error) {
      // クリップボードAPIが使えない環境（非HTTPS等）では何もしない。
    }
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
    loadingMessage.hidden = !hasMore;

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

    viewerDate.textContent = formatDate(item.created_at);
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

  // アイコン類（ナビゲーションゾーン・閉じる・削除ボタン）は明示的に除外。
  // 画像/動画については、DOM要素の当たり判定（event.target）に頼らず、
  // クリック座標がメディア要素の実測サイズ(getBoundingClientRect)の内側かどうかを
  // 直接判定する。ラッパーdivのサイズやflexboxのshrink-to-fit挙動のズレに
  // 影響されないようにするため。
  viewerDialog.addEventListener('click', (event) => {
    if (event.target.closest('.viewer-nav-zone, .viewer-close, .viewer-delete')) {
      return;
    }

    const mediaEl = viewerMedia.querySelector('img, video');
    if (mediaEl) {
      const rect = mediaEl.getBoundingClientRect();
      const withinMedia =
        event.clientX >= rect.left &&
        event.clientX <= rect.right &&
        event.clientY >= rect.top &&
        event.clientY <= rect.bottom;
      if (withinMedia) {
        return;
      }
    }

    closeViewer();
  });

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

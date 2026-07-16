(() => {
  const PAGE_SIZE = 30;

  const grid = document.getElementById('grid');
  const emptyMessage = document.getElementById('empty-message');
  const loadingMessage = document.getElementById('loading-message');
  const sentinel = document.getElementById('sentinel');

  const filterBar = document.getElementById('filter-bar');
  const currentFilterTag = document.getElementById('current-filter-tag');
  const clearFilterButton = document.getElementById('clear-filter');

  const loginDialog = document.getElementById('login-dialog');
  const loginForm = document.getElementById('login-form');
  const loginPassword = document.getElementById('login-password');
  const loginError = document.getElementById('login-error');

  const viewerDialog = document.getElementById('viewer-dialog');
  const viewerMedia = document.getElementById('viewer-media');
  const viewerDate = document.getElementById('viewer-date');
  const viewerTagList = document.getElementById('viewer-tag-list');
  const viewerClose = document.getElementById('viewer-close');
  const viewerPrev = document.getElementById('viewer-prev');
  const viewerNext = document.getElementById('viewer-next');
  const viewerDelete = document.getElementById('viewer-delete');

  const tagPopover = document.getElementById('tag-popover');
  const tagPopoverList = document.getElementById('tag-popover-list');
  const tagPopoverForm = document.getElementById('tag-popover-form');
  const tagPopoverInput = document.getElementById('tag-popover-input');

  let items = [];
  let offset = 0;
  let hasMore = true;
  let isLoading = false;
  let currentIndex = -1;
  let observer = null;
  let popoverItem = null;
  let currentTagFilter = null;

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

    const caption = document.createElement('div');
    caption.className = 'caption';

    const dateLabel = document.createElement('div');
    dateLabel.className = 'upload-date';
    dateLabel.textContent = formatDate(item.created_at);
    caption.appendChild(dateLabel);

    const tagList = document.createElement('div');
    tagList.className = 'card-tag-list';
    renderCardTags(tagList, item.tags);
    caption.appendChild(tagList);

    card.appendChild(caption);

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

    const tagButton = document.createElement('button');
    tagButton.type = 'button';
    tagButton.className = 'tag-button';
    tagButton.textContent = '🏷️';
    tagButton.setAttribute('aria-label', 'タグを編集');
    tagButton.addEventListener('click', (event) => {
      // カード全体のクリック（ビューアーを開く動作）を発火させない
      event.stopPropagation();
      toggleTagPopover(item, tagButton);
    });
    card.appendChild(tagButton);

    card.addEventListener('click', () => openViewer(items.indexOf(item)));
    grid.appendChild(card);
  }

  function renderCardTags(container, tags) {
    container.innerHTML = '';
    for (const tag of tags || []) {
      const chip = document.createElement('span');
      chip.className = 'card-tag-chip';
      chip.textContent = tag;
      chip.addEventListener('click', (event) => {
        // カード全体のクリック(ビューアーを開く動作)を発火させない
        event.stopPropagation();
        applyTagFilter(tag);
      });
      container.appendChild(chip);
    }
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

    const tagQuery = currentTagFilter ? `&tag=${encodeURIComponent(currentTagFilter)}` : '';
    const response = await apiFetch(`../api/gallery.php?limit=${PAGE_SIZE}&offset=${offset}${tagQuery}`);

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

  function applyTagFilter(tag) {
    if (viewerDialog.open) {
      closeViewer();
    }
    if (!tagPopover.hidden) {
      closeTagPopover();
    }

    currentTagFilter = tag || null;
    filterBar.hidden = !currentTagFilter;
    currentFilterTag.textContent = currentTagFilter || '';

    items = [];
    offset = 0;
    hasMore = true;
    grid.innerHTML = '';
    emptyMessage.hidden = true;
    loadingMessage.hidden = false;
    if (observer) {
      observer.disconnect();
      observer.observe(sentinel);
    }

    loadNextPage();
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
    renderViewerTagChips(item.tags);
  }

  function renderViewerTagChips(tags) {
    viewerTagList.innerHTML = '';
    for (const tag of tags || []) {
      const chip = document.createElement('span');
      chip.className = 'viewer-tag-chip-ro';
      chip.textContent = tag;
      chip.addEventListener('click', (event) => {
        // ダイアログの背景クリック(閉じる動作)を発火させない
        event.stopPropagation();
        applyTagFilter(tag);
      });
      viewerTagList.appendChild(chip);
    }
  }

  async function addTag(item, tagName) {
    const tagValue = tagName.trim();
    if (tagValue === '') {
      return;
    }

    const response = await apiFetch(`../api/gallery.php?hash=${item.hash}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ action: 'add_tag', tag: tagValue }),
    });
    const data = await response.json();

    if (!data.success) {
      return;
    }

    item.tags = data.tags;
    onTagsUpdated(item);
  }

  async function removeTag(item, tagName) {
    const response = await apiFetch(`../api/gallery.php?hash=${item.hash}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ action: 'remove_tag', tag: tagName }),
    });
    const data = await response.json();

    if (!data.success) {
      return;
    }

    item.tags = data.tags;
    onTagsUpdated(item);
  }

  function onTagsUpdated(item) {
    const card = grid.querySelector(`[data-hash="${item.hash}"]`);
    if (card) {
      renderCardTags(card.querySelector('.card-tag-list'), item.tags);
    }
    if (popoverItem === item) {
      renderPopoverTags();
    }
  }

  function renderPopoverTags() {
    tagPopoverList.innerHTML = '';

    for (const tag of popoverItem.tags || []) {
      const chip = document.createElement('span');
      chip.className = 'tag-chip';

      const label = document.createElement('span');
      label.textContent = tag;
      chip.appendChild(label);

      const removeButton = document.createElement('button');
      removeButton.type = 'button';
      removeButton.textContent = '×';
      removeButton.setAttribute('aria-label', `タグ「${tag}」を削除`);
      removeButton.addEventListener('click', () => removeTag(popoverItem, tag));
      chip.appendChild(removeButton);

      tagPopoverList.appendChild(chip);
    }
  }

  function openTagPopover(item, anchorButton) {
    popoverItem = item;
    renderPopoverTags();

    tagPopover.hidden = false;

    const anchorRect = anchorButton.getBoundingClientRect();
    const popoverRect = tagPopover.getBoundingClientRect();
    const maxLeft = window.scrollX + document.documentElement.clientWidth - popoverRect.width - 8;
    const left = Math.min(anchorRect.left + window.scrollX, maxLeft);
    const top = anchorRect.bottom + window.scrollY + 6;

    tagPopover.style.left = `${left}px`;
    tagPopover.style.top = `${top}px`;

    tagPopoverInput.value = '';
    tagPopoverInput.focus();
  }

  function closeTagPopover() {
    tagPopover.hidden = true;
    popoverItem = null;
  }

  function toggleTagPopover(item, anchorButton) {
    if (popoverItem === item && !tagPopover.hidden) {
      closeTagPopover();
      return;
    }
    openTagPopover(item, anchorButton);
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
    const response = await apiFetch(`../api/gallery.php?hash=${item.hash}`, {
      method: 'DELETE',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const data = await response.json();

    if (!data.success) {
      return;
    }

    const card = grid.querySelector(`[data-hash="${item.hash}"]`);
    if (card) {
      card.remove();
    }
    if (popoverItem === item) {
      closeTagPopover();
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
  clearFilterButton.addEventListener('click', () => applyTagFilter(null));
  tagPopoverForm.addEventListener('submit', (event) => {
    event.preventDefault();
    if (popoverItem) {
      addTag(popoverItem, tagPopoverInput.value);
      tagPopoverInput.value = '';
    }
  });
  document.addEventListener('click', (event) => {
    if (tagPopover.hidden) {
      return;
    }
    if (event.target.closest('#tag-popover, .tag-button')) {
      return;
    }
    closeTagPopover();
  });
  viewerClose.addEventListener('click', closeViewer);
  viewerPrev.addEventListener('click', () => showViewerAt(currentIndex - 1));
  viewerNext.addEventListener('click', () => showViewerAt(currentIndex + 1));
  viewerDelete.addEventListener('click', deleteCurrentItem);

  // アイコン類（前後送りボタン本体・閉じる・削除ボタン）は明示的に除外。
  // ナビゲーションゾーン（ホバー用の15%幅の当たり判定エリア）自体はここでは除外しない。
  // ボタン以外の空欄をクリックした場合は背景クリックと同様に閉じる扱いとする。
  // 画像/動画については、DOM要素の当たり判定（event.target）に頼らず、
  // クリック座標がメディア要素の実測サイズ(getBoundingClientRect)の内側かどうかを
  // 直接判定する。ラッパーdivのサイズやflexboxのshrink-to-fit挙動のズレに
  // 影響されないようにするため。
  viewerDialog.addEventListener('click', (event) => {
    if (event.target.closest('.viewer-nav-arrow, .viewer-close, .viewer-delete')) {
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
    if (event.key === 'Escape' && !tagPopover.hidden) {
      closeTagPopover();
      return;
    }
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

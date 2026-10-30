"use strict";

// Приёмка техники: VIN → шесть фото камерой → дефекты текстом или голосом → «Отправить».
// Данные портала — через index.php?action=… (lib.php). Оформление и приёмы — как у «Перегона техники».
// «Отправить» пока заглушка: проверяет, что всё заполнено, и никуда ничего не отправляет.

const BOOT = window.PRIEMKA || {};
const USER = BOOT.user || {id: 0, name: "", admin: false};
const IS_ADMIN = Boolean(USER.admin);
const MIN_VIN = BOOT.minQuery || 6;
const VIN_HINT = "Введите крайние 6 цифр VIN номера";
const MIN_PROBE = BOOT.minProbe || 3;

const $ = (id) => document.getElementById(id);
const escapeHtml = (value) => String(value ?? "").replace(/[&<>"']/g,
  (c) => ({"&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"}[c]));

function debounce(fn, ms) {
  let timer = null;
  return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), ms); };
}

function plural(n, one, few, many) {
  const m10 = n % 10, m100 = n % 100;
  return m10 === 1 && m100 !== 11 ? one : m10 >= 2 && m10 <= 4 && (m100 < 12 || m100 > 14) ? few : many;
}

// VIN для сверки: без регистра, пробелов и дефисов.
const vinKey = (value) => String(value || "").toUpperCase().replace(/[^0-9A-ZА-ЯЁ]+/g, "");

// Набранные знаки VIN — подсветкой; конец VIN подсвечивается в первую очередь.
function highlightVin(text, query) {
  const source = String(text ?? "");
  const key = vinKey(query);
  if (!key) return escapeHtml(source);
  // Позиции знаков VIN в исходной строке: в ней бывают пробелы и дефисы.
  const map = [];
  let plain = "";
  for (let i = 0; i < source.length; i++) {
    const char = source[i].toUpperCase();
    if (/[0-9A-ZА-ЯЁ]/.test(char)) { map.push(i); plain += char; }
  }
  let at = plain.endsWith(key) ? plain.length - key.length : plain.lastIndexOf(key);
  if (at < 0) return escapeHtml(source);
  const from = map[at], to = map[at + key.length - 1] + 1;
  return escapeHtml(source.slice(0, from)) + `<mark>${escapeHtml(source.slice(from, to))}</mark>` + escapeHtml(source.slice(to));
}

// Удобства этого браузера (черновик приёмки). Недоступно хранилище — просто не помним.
const store = {
  get(key, fallback) {
    try { const value = localStorage.getItem(key); return value == null ? fallback : JSON.parse(value); } catch (_) { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem(key, JSON.stringify(value)); } catch (_) { /* хранилище недоступно */ }
  },
  remove(key) {
    try { localStorage.removeItem(key); } catch (_) { /* хранилище недоступно */ }
  },
};

const ICON = {
  store: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 6.5 8 2.5l6 4V13.5H2z"/><path d="M5.5 13.5V9h5v4.5"/></svg>',
  check: '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="3.5 8.5 6.5 11.5 12.5 5"/></svg>',
};


// ---------- транспорт ----------

async function api(action, body = {}) {
  let response;
  let text;
  try {
    response = await fetch(`${BOOT.api}?action=${encodeURIComponent(action)}`, {
      method: "POST",
      credentials: "same-origin",
      headers: {"Content-Type": "application/json"},
      // ticket — пропуск страницы, открытой по ключу входа (мобильный Битрикс24 без сессии портала).
      body: JSON.stringify({...body, sessid: BOOT.sessid, ticket: BOOT.ticket || undefined}),
    });
    text = await response.text();
  } catch (_) {
    throw new Error("Нет связи с порталом — проверьте сеть");
  }
  let data = null;
  try { data = text ? JSON.parse(text) : null; } catch (_) { data = null; }
  if (!data) {
    const plain = text.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ").trim().slice(0, 200);
    throw new Error(`Портал ответил не JSON (HTTP ${response.status})${plain ? `: ${plain}` : ""}`);
  }
  if (!data.ok) throw new Error(data.error || "Ошибка запроса");
  return data.data;
}

function toast(message, kind = "") {
  document.querySelector(".toast")?.remove();
  const node = document.createElement("div");
  node.className = `toast ${kind}`;
  node.setAttribute("role", kind === "err" ? "alert" : "status");
  node.textContent = message;
  node.addEventListener("click", () => node.remove());
  document.body.appendChild(node);
  setTimeout(() => node.remove(), kind === "err" ? 7000 : 4500);
}

// Показать шаг: прокрутка к нему и короткая подсветка рамки.
function showStep(id, focus = null) {
  const node = $(id);
  if (!node) return;
  node.scrollIntoView({behavior: matchMedia("(prefers-reduced-motion: reduce)").matches ? "auto" : "smooth", block: "start"});
  node.classList.remove("flash");
  void node.offsetWidth;
  node.classList.add("flash");
  if (focus) setTimeout(() => focus.focus({preventScroll: true}), 350);
}


// ---------- вкладки ----------

const tabLoaders = {access: loadAccess, check: loadCheck};
const tabsLoaded = new Set();

function setupTabs() {
  document.querySelectorAll(".nav-item").forEach((button) => {
    button.addEventListener("click", () => {
      const tab = button.dataset.tab;
      document.querySelectorAll(".nav-item").forEach((item) => item.classList.toggle("active", item === button));
      document.querySelectorAll("main > section").forEach((section) => section.classList.toggle("hidden", section.id !== `tab-${tab}`));
      if (tabLoaders[tab] && !tabsLoaded.has(tab)) { tabsLoaded.add(tab); tabLoaders[tab](); }
    });
  });
}


// ---------- состояние приёмки ----------

const SLOTS = [...document.querySelectorAll(".photo-tile")].map((tile, index) => ({
  key: tile.dataset.slot,
  number: index + 1,
  label: tile.querySelector(".photo-label").textContent,
  tile,
  input: tile.querySelector(".photo-input"),
}));

/**
 * product — выбранная машина ({id, vin, name, label, stores…}).
 * photos[slot] — {status: "busy" | "done" | "error", blob, thumb, url, note}.
 */
const state = {product: null, photos: {}, done: false};
const DRAFT_KEY = `priemka:draft:${USER.id}`;

const photoDone = (key) => state.photos[key]?.status === "done";
const photosDone = () => SLOTS.filter((slot) => photoDone(slot.key)).length;
const photosBusy = () => SLOTS.some((slot) => state.photos[slot.key]?.status === "busy");
const defectsText = () => $("defects").value.trim();

/** Что ещё не заполнено — первое по порядку шагов. null — всё готово. */
function missing() {
  if (!state.product) return {step: "step-vin", focus: $("vin-input"), text: "Выберите VIN: наберите последние 6 цифр и нажмите на машину в списке."};
  if (photosBusy()) return {step: "step-photos", text: "Дождитесь обработки фото."};
  const left = SLOTS.filter((slot) => !photoDone(slot.key));
  if (left.length) {
    return {step: "step-photos", text: `Не хватает ${left.length} ${plural(left.length, "фото", "фото", "фото")}: `
      + left.map((slot) => `${slot.number}. ${slot.label.charAt(0).toLowerCase()}${slot.label.slice(1)}`.replace(/^(\d+\. )vIN/, "$1VIN")).join(", ") + "."};
  }
  if (Voice.active) return {step: "step-defects", text: "Остановите запись голоса."};
  if (defectsText().length < 3) return {step: "step-defects", focus: $("defects"), text: "Опишите дефекты автомобиля — текстом или голосом."};
  return null;
}

/** Замки шагов, счётчики и кнопка «Отправить» — по текущему состоянию. */
function refresh() {
  const vinOk = Boolean(state.product);
  const photosOk = vinOk && photosDone() === SLOTS.length && !photosBusy();
  const defectsOk = photosOk && defectsText().length >= 3;

  setStep("step-vin", false, vinOk);
  setStep("step-photos", !vinOk, photosOk);
  setStep("step-defects", !photosOk, defectsOk);
  SLOTS.forEach((slot) => { slot.input.disabled = !vinOk; });
  $("defects").disabled = !photosOk;
  $("voice-btn").disabled = !photosOk;
  if (!photosOk && Voice.active) Voice.stop();
  $("photos-lock").textContent = "Сначала выберите VIN.";
  $("defects-lock").textContent = !vinOk ? "Сначала выберите VIN и сделайте все 6 фото."
    : `Сначала сделайте все 6 фото — готово ${photosDone()} из ${SLOTS.length}.`;
  $("photo-count").textContent = `${photosDone()} из ${SLOTS.length}`;

  const need = missing();
  const button = $("submit-btn");
  // Не disabled: нажатие на незаполненную приёмку показывает, чего не хватает.
  button.classList.toggle("off", Boolean(need));
  const status = $("submit-status");
  status.classList.remove("ok", "err");
  if (state.done && !need) {
    status.innerHTML = `${ICON.check} Всё заполнено. Отправка пока не подключена — данные никуда не ушли.`;
    status.classList.add("ok");
  } else {
    state.done = false;
    status.textContent = need ? need.text : "Всё заполнено — можно отправлять.";
  }
}

function setStep(id, locked, done) {
  const node = $(id);
  node.classList.toggle("locked", locked);
  node.classList.toggle("done", done && !locked);
  node.setAttribute("aria-disabled", locked ? "true" : "false");
}

function saveDraft() {
  store.set(DRAFT_KEY, {product: state.product, defects: $("defects").value});
}


// ---------- 1. VIN ----------

const vin = {seq: 0, items: []};

function storesHtml(item) {
  const stores = item.stores || [];
  if (item.storesNote) return `<span class="stores warn">${ICON.store}<span>${escapeHtml(item.storesNote)}</span></span>`;
  if (!stores.length) return `<span class="stores none">${ICON.store}<span>Нет на складах</span></span>`;
  const names = stores.map((store) => escapeHtml(store.title) + (store.amount > 1 ? ` <small>(${store.amount})</small>` : ""));
  return `<span class="stores">${ICON.store}<span>${stores.length > 1 ? "Склады" : "Склад"}: ${names.join(", ")}</span></span>`;
}

function vinHint(text, kind = "") {
  const hint = $("vin-hint");
  hint.textContent = text;
  hint.className = `vin-hint ${kind}`;
}

function renderVinResults(query, data) {
  const box = $("vin-results");
  vin.items = data.items || [];
  if (!vin.items.length) {
    box.innerHTML = `<div class="vin-empty">Машина с VIN, где есть «${escapeHtml(query)}», не найдена. Проверьте цифры.</div>`;
    box.hidden = false;
    vinHint("Ничего не найдено.", "err");
    return;
  }
  box.innerHTML = vin.items.map((item, index) => `
    <button type="button" class="vin-option" role="option" data-index="${index}">
      <span class="vin-option-vin">${highlightVin(item.label, query)}</span>
      ${item.vin && item.name ? `<span class="vin-option-name">${escapeHtml(item.name)}</span>` : ""}
      ${storesHtml(item)}
    </button>`).join("")
    + (data.more ? `<div class="vin-empty">Показаны первые ${vin.items.length}. Наберите больше знаков VIN.</div>` : "");
  box.hidden = false;
  box.querySelectorAll(".vin-option").forEach((node) => {
    node.addEventListener("click", () => pickVin(vin.items[Number(node.dataset.index)]));
  });
  vinHint(`Найдено: ${vin.items.length}${data.more ? "+" : ""}. Нажмите на нужную машину.`);
}

async function searchVin() {
  const query = vinKey($("vin-input").value);
  const mine = ++vin.seq;
  if (query.length < MIN_VIN) {
    $("vin-results").hidden = true;
    vinHint(VIN_HINT);
    return;
  }
  vinHint("Ищу…");
  try {
    const data = await api("vin.search", {q: query});
    if (mine !== vin.seq) return;
    renderVinResults(query, data);
  } catch (error) {
    if (mine !== vin.seq) return;
    $("vin-results").hidden = true;
    vinHint(error.message, "err");
  }
}
const searchVinSoon = debounce(searchVin, 250);

function pickVin(item) {
  if (!item) return;
  state.product = {id: item.id, vin: item.vin || "", name: item.name || "", label: item.label || item.vin || item.name,
    section: item.section || "", stores: item.stores || [], storesNote: item.storesNote || ""};
  ++vin.seq;
  $("vin-results").hidden = true;
  $("vin-input").blur();
  renderVin();
  saveDraft();
  refresh();
  showStep("step-photos");
}

function renderVin() {
  const product = state.product;
  $("vin-field").hidden = Boolean(product);
  $("vin-results").hidden = true;
  const box = $("vin-picked");
  box.hidden = !product;
  if (!product) return;
  box.innerHTML = `
    <div class="vin-picked-main">
      <span class="vin-picked-vin">${escapeHtml(product.label)}</span>
      ${product.vin && product.name ? `<span class="vin-picked-name">${escapeHtml(product.name)}</span>` : ""}
      ${storesHtml(product)}
    </div>
    <button class="btn ghost small" type="button" id="vin-change">Изменить</button>`;
  $("vin-change").addEventListener("click", changeVin);
}

async function changeVin() {
  const hasData = SLOTS.some((slot) => state.photos[slot.key]) || defectsText();
  if (hasData && !confirm("Сменить машину? Сделанные фото и описание дефектов будут удалены.")) return;
  Voice.stop();
  await clearPhotos();
  $("defects").value = "";
  state.product = null;
  state.done = false;
  saveDraft();
  renderVin();
  refresh();
  const input = $("vin-input");
  input.value = "";
  $("vin-clear").hidden = true;
  vinHint(VIN_HINT);
  input.focus();
}

function setupVin() {
  const input = $("vin-input");
  input.addEventListener("input", () => {
    // Для поиска вводят цифры VIN: буквы, пробелы и знаки из ввода и вставки убираем.
    const clean = input.value.replace(/\D/g, "").slice(0, 17);
    if (clean !== input.value) input.value = clean;
    $("vin-clear").hidden = !clean;
    searchVinSoon();
  });
  input.addEventListener("keydown", (event) => {
    if (event.key !== "Enter") return;
    event.preventDefault();
    if (vin.items.length === 1 && !$("vin-results").hidden) pickVin(vin.items[0]);
    else searchVin();
  });
  $("vin-clear").addEventListener("click", () => {
    input.value = "";
    $("vin-clear").hidden = true;
    vin.items = [];
    searchVin();
    input.focus();
  });
}


// ---------- 2. Фото ----------

/** Фото старше этого — не с камеры только что, а из галереи. */
const FRESH_MS = 10 * 60 * 1000;
const PHOTO_SIDE = 1920;
const THUMB_SIDE = 360;
const photoSeq = {};

/** Фото в этом браузере — переживают перезагрузку (телефон может выгрузить страницу, пока открыта камера). */
const PhotoStore = (() => {
  let opening = null;
  function db() {
    if (!opening) {
      opening = new Promise((resolve) => {
        try {
          const request = indexedDB.open("gross-priemka", 1);
          request.onupgradeneeded = () => request.result.createObjectStore("photos");
          request.onsuccess = () => resolve(request.result);
          request.onerror = () => resolve(null);
          request.onblocked = () => resolve(null);
        } catch (_) { resolve(null); }
      });
    }
    return opening;
  }
  async function run(mode, action) {
    const base = await db();
    if (!base) return null;
    return new Promise((resolve) => {
      try {
        const tx = base.transaction("photos", mode);
        const request = action(tx.objectStore("photos"));
        tx.oncomplete = () => resolve(request ? request.result : null);
        tx.onerror = () => resolve(null);
        tx.onabort = () => resolve(null);
      } catch (_) { resolve(null); }
    });
  }
  const key = (slot) => `${USER.id}:${slot}`;
  return {
    put: (slot, value) => run("readwrite", (s) => s.put(value, key(slot))),
    get: (slot) => run("readonly", (s) => s.get(key(slot))),
    remove: (slot) => run("readwrite", (s) => s.delete(key(slot))),
  };
})();

function loadImage(file) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error("Не удалось открыть фото — переснимите")); };
    img.src = url;
  });
}

// Уменьшенная копия: большие снимки телефона весят 5–10 МБ, для приёмки хватает 1920 точек.
function scaled(img, side, quality) {
  const ratio = Math.min(1, side / Math.max(img.naturalWidth, img.naturalHeight));
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(img.naturalWidth * ratio));
  canvas.height = Math.max(1, Math.round(img.naturalHeight * ratio));
  canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
  return new Promise((resolve, reject) => canvas.toBlob(
    (blob) => (blob ? resolve(blob) : reject(new Error("Не удалось обработать фото — переснимите"))), "image/jpeg", quality));
}

async function processPhoto(file) {
  if (file.type && !file.type.startsWith("image/")) throw new Error("Это не фото — сделайте снимок камерой");
  // Снимок с камеры только что создан. Старый файл — выбран из галереи.
  if (file.lastModified && Date.now() - file.lastModified > FRESH_MS) {
    throw new Error("Фото из галереи не принимается — сделайте новый снимок камерой");
  }
  const img = await loadImage(file);
  const blob = await scaled(img, PHOTO_SIDE, 0.85);
  const thumb = await scaled(img, THUMB_SIDE, 0.75);
  return {blob, thumb};
}

function paintTile(slot) {
  const photo = state.photos[slot.key];
  const tile = slot.tile;
  const img = tile.querySelector(".photo-img");
  const note = tile.querySelector(".photo-note");
  const shown = photo && photo.url;
  tile.dataset.state = !photo ? "empty" : photo.status;
  tile.classList.toggle("has-photo", Boolean(shown));
  if (shown) { if (img.src !== photo.url) img.src = photo.url; img.hidden = false; } else { img.removeAttribute("src"); img.hidden = true; }
  note.className = "photo-note";
  if (photo?.note) { note.textContent = photo.note; note.classList.add("err"); }
  else if (photo?.status === "done") note.textContent = "Переснять";
  else if (photo?.status === "busy") note.textContent = "";
  else note.textContent = "";
  slot.input.setAttribute("aria-label", `${slot.number}. ${slot.label} — ${photo?.status === "done" ? "переснять" : "сделать фото"}`);
}

async function takePhoto(slot, file) {
  const before = state.photos[slot.key];
  const mine = (photoSeq[slot.key] = (photoSeq[slot.key] || 0) + 1);
  state.photos[slot.key] = {...(before || {}), status: "busy", note: ""};
  paintTile(slot);
  refresh();
  try {
    const {blob, thumb} = await processPhoto(file);
    if (mine !== photoSeq[slot.key]) return;
    if (before?.url) URL.revokeObjectURL(before.url);
    state.photos[slot.key] = {status: "done", blob, thumb, url: URL.createObjectURL(thumb), note: ""};
    PhotoStore.put(slot.key, {blob, thumb, vin: state.product?.id || 0, at: Date.now()});
  } catch (error) {
    if (mine !== photoSeq[slot.key]) return;
    // Прежнее хорошее фото остаётся: не удалась только пересъёмка.
    state.photos[slot.key] = before?.status === "done"
      ? {...before, note: error.message}
      : {status: "error", note: error.message};
    toast(error.message, "err");
  }
  paintTile(slot);
  const wasComplete = before?.status === "done" && photosDone() === SLOTS.length;
  refresh();
  if (!wasComplete && photosDone() === SLOTS.length && !photosBusy()) showStep("step-defects");
}

async function clearPhotos() {
  for (const slot of SLOTS) {
    photoSeq[slot.key] = (photoSeq[slot.key] || 0) + 1;
    const photo = state.photos[slot.key];
    if (photo?.url) URL.revokeObjectURL(photo.url);
    delete state.photos[slot.key];
    paintTile(slot);
    await PhotoStore.remove(slot.key);
  }
}

function setupPhotos() {
  SLOTS.forEach((slot) => {
    slot.input.addEventListener("change", () => {
      const file = slot.input.files && slot.input.files[0];
      slot.input.value = "";
      if (file) takePhoto(slot, file);
    });
  });
}


// ---------- 3. Дефекты и голос ----------

/**
 * Голос в текст.
 * https — запись прямо в программе (Web Speech API: Chrome, Safari, Edge).
 * http — браузеры не дают сайту микрофон, обойти это из страницы нельзя. Тогда кнопка
 * ставит курсор в поле и показывает, где диктовка на клавиатуре телефона (или Win+H на ПК):
 * клавиатура набирает речь текстом на любом адресе.
 */
const Voice = (() => {
  const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  let mode = Recognition && window.isSecureContext ? "record" : "dictate";
  let recognition = null;
  let active = false;
  let finalText = "";
  let interim = "";
  let started = 0;
  let timer = null;

  const ua = navigator.userAgent;
  const platform = /android/i.test(ua) ? "android"
    : /iphone|ipad|ipod/i.test(ua) || (/macintosh/i.test(ua) && navigator.maxTouchPoints > 1) ? "ios"
      : /windows/i.test(ua) ? "windows" : /macintosh/i.test(ua) ? "mac" : "other";

  function label() {
    $("voice-label").textContent = active ? "Остановить" : mode === "record" ? "Записать голосом" : "Надиктовать";
    $("voice-btn").classList.toggle("recording", active);
  }

  function helpHtml(reason) {
    const steps = {
      android: "Нажмите <b>значок микрофона 🎤 на клавиатуре</b> (у Gboard — справа над буквами) и говорите. Текст появится в поле.",
      ios: "Нажмите <b>значок микрофона 🎤 на клавиатуре</b> (внизу справа) и говорите. Текст появится в поле. Нет значка — Настройки → Основные → Клавиатура → «Включение диктовки».",
      windows: "Нажмите <b>Win + H</b> и говорите — Windows наберёт текст в поле. Ещё удобнее — с телефона: значок микрофона на клавиатуре.",
      mac: "Дважды нажмите <b>Fn</b> (или Control) и говорите — macOS наберёт текст в поле.",
      other: "Включите <b>голосовой ввод клавиатуры</b> — значок микрофона 🎤 — и говорите. Текст появится в поле.",
    };
    return `${reason ? `<p class="voice-reason">${escapeHtml(reason)}</p>` : ""}<p>${steps[platform]}</p>`;
  }

  function showHelp(reason = "") {
    $("voice-help").innerHTML = helpHtml(reason);
    $("voice-help").hidden = false;
  }

  function putCursorAtEnd() {
    const area = $("defects");
    area.focus();
    const end = area.value.length;
    try { area.setSelectionRange(end, end); } catch (_) { /* старый браузер */ }
  }

  function tick() {
    const seconds = Math.floor((Date.now() - started) / 1000);
    $("voice-state").innerHTML = `<span class="rec-dot" aria-hidden="true"></span>Идёт запись ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, "0")} — говорите`;
    if (seconds >= 300) stop();
  }

  function renderLive() {
    const live = $("voice-live");
    live.hidden = !active;
    live.innerHTML = finalText.trim() || interim
      ? escapeHtml(finalText).trim() + (interim ? ` <span class="interim">${escapeHtml(interim)}</span>` : "")
      : '<span class="interim">Слушаю…</span>';
  }

  function append(text) {
    const clean = String(text || "").replace(/\s+/g, " ").trim();
    if (!clean) return;
    const area = $("defects");
    const sentence = clean.charAt(0).toUpperCase() + clean.slice(1);
    area.value = area.value.trim() ? `${area.value.replace(/\s+$/, "")}\n${sentence}` : sentence;
    area.dispatchEvent(new Event("input", {bubbles: true}));
  }

  // Один раз на запись: после ошибки браузер ещё присылает onend.
  function finish(message = "") {
    if (!recognition) return;
    clearInterval(timer);
    active = false;
    append(`${finalText} ${interim}`);
    finalText = "";
    interim = "";
    recognition = null;
    $("voice-live").hidden = true;
    $("voice-state").textContent = message;
    label();
    refresh();
  }

  function listen() {
    recognition = new Recognition();
    recognition.lang = "ru-RU";
    recognition.continuous = true;
    recognition.interimResults = true;
    recognition.onresult = (event) => {
      interim = "";
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const result = event.results[i];
        if (result.isFinal) finalText += `${result[0].transcript} `;
        else interim += result[0].transcript;
      }
      renderLive();
    };
    recognition.onerror = (event) => {
      if (event.error === "no-speech" || event.error === "aborted") return;
      const reasons = {
        "not-allowed": "Браузер не дал доступ к микрофону.",
        "service-not-allowed": "Браузер не дал доступ к распознаванию речи.",
        "audio-capture": "Микрофон не найден.",
        network: "Нет связи с сервисом распознавания речи.",
      };
      const reason = reasons[event.error] || `Запись не удалась (${event.error}).`;
      active = false;
      if (event.error === "not-allowed" || event.error === "service-not-allowed" || event.error === "audio-capture") {
        mode = "dictate";
        showHelp(`${reason} Надиктуйте с клавиатуры:`);
      }
      finish(reason);
    };
    // Телефон обрывает распознавание после паузы — пока запись не остановлена, слушаем дальше.
    recognition.onend = () => {
      if (active) {
        try { recognition.start(); return; } catch (_) { /* не перезапустился — заканчиваем */ }
      }
      finish();
    };
    recognition.start();
  }

  function start() {
    if (mode !== "record") {
      putCursorAtEnd();
      showHelp(window.isSecureContext ? "" : "На этом адресе (http) браузер не даёт программе микрофон — текст наберёт клавиатура телефона.");
      return;
    }
    $("voice-help").hidden = true;
    finalText = "";
    interim = "";
    active = true;
    started = Date.now();
    try {
      listen();
    } catch (error) {
      active = false;
      recognition = null;
      mode = "dictate";
      showHelp("Запись в этом браузере не запустилась. Надиктуйте с клавиатуры:");
      label();
      refresh();
      return;
    }
    tick();
    timer = setInterval(tick, 1000);
    renderLive();
    label();
    refresh();
  }

  function stop() {
    if (!active) return;
    active = false;
    const current = recognition;
    try { current?.stop(); } catch (_) { finish(); }
    // onend придёт сам и допишет текст; на случай, если браузер промолчит:
    setTimeout(() => { if (recognition && recognition === current) finish(); }, 1500);
  }

  label();
  return {
    get active() { return active; },
    get mode() { return mode; },
    toggle() { active ? stop() : start(); },
    stop,
  };
})();

function setupDefects() {
  const area = $("defects");
  // Черновик пишется сразу: телефон может выгрузить страницу в любой момент.
  area.addEventListener("input", () => { saveDraft(); refresh(); });
  document.addEventListener("visibilitychange", () => { if (document.visibilityState === "hidden" && state.product) saveDraft(); });
  $("voice-btn").addEventListener("click", () => Voice.toggle());
}


// ---------- отправка (пока заглушка) ----------

function setupSubmit() {
  $("submit-btn").addEventListener("click", () => {
    const need = missing();
    if (need) {
      toast(need.text, "err");
      showStep(need.step, need.focus);
      return;
    }
    // Отправка ещё не подключена: только подтверждаем, что всё заполнено.
    state.done = true;
    refresh();
    toast("Всё заполнено. Отправка пока не подключена.", "ok");
  });
}


// ---------- черновик ----------

/** Вернуть приёмку после перезагрузки страницы: телефон мог выгрузить её, пока была открыта камера. */
async function restoreDraft() {
  const draft = store.get(DRAFT_KEY, null);
  if (!draft || !draft.product || !draft.product.id) { refresh(); return; }
  state.product = draft.product;
  renderVin();
  await Promise.all(SLOTS.map(async (slot) => {
    const saved = await PhotoStore.get(slot.key);
    if (saved && saved.blob && saved.thumb && saved.vin === state.product.id) {
      state.photos[slot.key] = {status: "done", blob: saved.blob, thumb: saved.thumb, url: URL.createObjectURL(saved.thumb), note: ""};
      paintTile(slot);
    }
  }));
  $("defects").value = String(draft.defects || "");
  refresh();
}


// ---------- доступ (администраторы) ----------

let accessUsers = [];
let foundUsers = [];
const hasAccess = (id) => accessUsers.some((user) => user.id === id);

function userChip(user) {
  const classes = ["chip", hasAccess(user.id) ? "on" : "", user.active ? "" : "gone"].filter(Boolean).join(" ");
  const title = hasAccess(user.id) ? "Снять доступ" : "Выдать доступ";
  return `<div class="${classes}" data-user="${user.id}" title="${title}" role="button" tabindex="0">${escapeHtml(user.name)}`
    + `${user.position ? `<span class="chip-sub">${escapeHtml(user.position)}</span>` : ""}</div>`;
}

function renderAccess() {
  $("access-count").textContent = accessUsers.length;
  $("access-chips").innerHTML = accessUsers.map(userChip).join("")
    || `<div class="combo-empty">Доступ пока ни у кого — программой пользуются только администраторы портала.</div>`;
  $("user-chips").innerHTML = foundUsers.map(userChip).join("") || `<div class="combo-empty">Никого не найдено.</div>`;
  document.querySelectorAll("[data-user]").forEach((node) => {
    node.onclick = () => toggleAccess(Number(node.dataset.user), node);
    node.onkeydown = (event) => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); node.click(); } };
  });
}

async function loadAccess() {
  try {
    const [access, users] = await Promise.all([api("admin.users"), api("admin.users.search", {q: ""})]);
    accessUsers = access.items;
    foundUsers = users.items;
    renderAccess();
  } catch (error) {
    $("access-chips").textContent = error.message;
    $("user-chips").textContent = "";
    tabsLoaded.delete("access");
    toast(error.message, "err");
  }
}

async function toggleAccess(id, node) {
  const had = hasAccess(id);
  node.classList.add("busy");
  try {
    accessUsers = (await api(had ? "admin.users.remove" : "admin.users.add", {id})).items;
    renderAccess();
    toast(had ? "Доступ снят." : "Доступ выдан.", "ok");
  } catch (error) {
    node.classList.remove("busy");
    toast(error.message, "err");
  }
}

let userSearchSeq = 0;
const searchUsers = debounce(async () => {
  const mine = ++userSearchSeq;
  try {
    const data = await api("admin.users.search", {q: $("user-search").value.trim()});
    if (mine !== userSearchSeq) return;
    foundUsers = data.items;
    renderAccess();
  } catch (error) {
    toast(error.message, "err");
  }
}, 300);


// ---------- проверка и настройки поиска (администраторы) ----------

const MARKS = {ok: ["ok-mark", "✓"], warn: ["warn-mark", "!"], error: ["err-mark", "✕"], info: ["info-mark", "•"]};

function loadCheck() {
  runCheck();
  loadSettings();
}

async function runCheck() {
  $("check-result").innerHTML = `<div class="card"><p class="desc" style="margin:0">Проверяю портал…</p></div>`;
  try {
    const data = await api("admin.check");
    $("check-result").innerHTML = data.sections.map((section) => `
      <div class="card check-card"><h3>${escapeHtml(section.title)}</h3><ul class="probe">
        ${section.items.map((item) => {
          const [mark, sign] = MARKS[item.status] || MARKS.info;
          return `<li><span class="${mark}">${sign}</span><span>${escapeHtml(item.text)}</span></li>`;
        }).join("")}
      </ul></div>`).join("");
  } catch (error) {
    $("check-result").innerHTML = `<div class="notice err">${escapeHtml(error.message)}</div>`;
  }
}

async function runProbe() {
  const query = vinKey($("probe-query").value);
  if (query.length < MIN_PROBE) { $("probe-result").innerHTML = `<p class="desc">Минимум ${MIN_PROBE} знака.</p>`; return; }
  $("probe-result").innerHTML = `<p class="desc">Ищу…</p>`;
  try {
    const data = await api("admin.search", {q: query});
    const rows = data.items.map((item) => `<div class="found">${highlightVin(item.label, query)}`
      + `<small>ID товара ${item.id}${item.vin && item.name ? ` · ${escapeHtml(item.name)}` : ""}${item.section ? ` · ${escapeHtml(item.section)}` : ""}</small>`
      + `<small>${storesHtml(item)}</small></div>`).join("")
      || `<div class="combo-empty">Ничего не найдено.</div>`;
    $("probe-result").innerHTML = rows + (data.more ? `<p class="desc">Показаны первые совпадения.</p>` : "");
  } catch (error) {
    $("probe-result").innerHTML = `<div class="notice err">${escapeHtml(error.message)}</div>`;
  }
}

let settingsData = null;

function renderSettings(data) {
  settingsData = data;
  const {settings, meta} = data;
  $("settings-notice").innerHTML = meta.errors.length
    ? `<div class="notice">Часть подсказок с портала не загрузилась — значения можно вписать руками:<br>${meta.errors.map(escapeHtml).join("<br>")}</div>`
    : "";
  const listed = meta.catalogFields || [];
  const options = [...listed, ...settings.vin_fields.filter((saved) => !listed.some((option) => option.field === saved))
    .map((saved) => ({field: saved, name: saved}))];
  const names = Object.fromEntries(options.map((option) => [option.field, option.name]));
  const auto = (meta.vinAuto || []).map((name) => names[name] || name).join(", ") || "Название";
  const sections = (meta.sections || []).map((section) => `${section.id} — «${section.name}»`).join(", ");
  $("settings-form").innerHTML = `
    <div class="grid">
      <div class="field"><label for="s-iblock">ID инфоблока каталога</label>
        <input id="s-iblock" type="number" min="0" inputmode="numeric" value="${escapeHtml(settings.catalog_iblock_id)}"></div>
      <div class="field"><label for="s-sections">ID папок с техникой</label>
        <input id="s-sections" autocomplete="off" value="${escapeHtml(settings.section_ids.join(", "))}" placeholder="пусто — весь каталог">
        <small>Через запятую, вложенные папки входят сами.${sections ? ` Сейчас: ${escapeHtml(sections)}.` : ""}</small></div>
      <div class="field" style="grid-column:1/-1"><label>Где искать VIN</label>
        <div class="chips" id="s-vin">${options.map((option) => `<div class="chip ${settings.vin_fields.includes(option.field) ? "on" : ""}" data-field="${escapeHtml(option.field)}" role="button" tabindex="0">${escapeHtml(option.name)}</div>`).join("")
          || `<div class="combo-empty">Поля каталога не загрузились.</div>`}</div>
        <small>Ничего не отмечено — автоматически: ${escapeHtml(auto)}.</small></div>
    </div>`;
  $("settings-form").querySelectorAll(".chip[data-field]").forEach((chip) => {
    chip.onclick = () => chip.classList.toggle("on");
  });
}

async function loadSettings() {
  try {
    renderSettings(await api("admin.settings"));
  } catch (error) {
    $("settings-form").innerHTML = `<div class="notice err">${escapeHtml(error.message)}</div>`;
  }
}

async function saveSettings() {
  const button = $("settings-save");
  button.disabled = true;
  try {
    renderSettings(await api("admin.settings.save", {settings: {
      catalog_iblock_id: $("s-iblock")?.value.trim() ?? settingsData?.settings.catalog_iblock_id,
      section_ids: $("s-sections")?.value.trim() ?? "",
      vin_fields: [...document.querySelectorAll("#s-vin .chip.on")].map((chip) => chip.dataset.field),
    }}));
    toast("Настройки сохранены.", "ok");
    runCheck();
  } catch (error) {
    toast(error.message, "err");
  }
  button.disabled = false;
}

async function resetSettings() {
  if (!confirm("Вернуть настройки поиска к значениям по умолчанию (каталог 15, папки 391 и 466)?")) return;
  try {
    renderSettings(await api("admin.settings.reset"));
    toast("Настройки сброшены.", "ok");
    runCheck();
  } catch (error) {
    toast(error.message, "err");
  }
}


// ---------- запуск ----------

setupTabs();
setupVin();
setupPhotos();
setupDefects();
setupSubmit();
restoreDraft();

if (IS_ADMIN) {
  $("user-search").addEventListener("input", searchUsers);
  $("check-run").addEventListener("click", runCheck);
  $("probe-run").addEventListener("click", runProbe);
  $("probe-query").addEventListener("keydown", (event) => { if (event.key === "Enter") runProbe(); });
  $("settings-save").addEventListener("click", saveSettings);
  $("settings-reset").addEventListener("click", resetSettings);
}

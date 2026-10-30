<?php
/**
 * «Приёмка техники» — «Путь вашего обработчика» локального приложения.
 *
 * Без ?action= — страница программы, с ?action= — JSON для неё (см. Api в lib.php).
 * Пускает сотрудников из списка доступа и администраторов портала.
 *
 * Кто открыл: из сессии портала. Мобильный Битрикс24 может открыть программу без неё —
 * тогда по ключу входа AUTH_ID, который портал передаёт обработчику при открытии.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NO_AGENT_STATISTIC', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
require __DIR__ . '/lib.php';

use Gross\Priemka\Access;
use Gross\Priemka\Api;
use Gross\Priemka\AppError;
use Gross\Priemka\Catalog;
use Gross\Priemka\Text;
use Gross\Priemka\Visits;
use function Gross\Priemka\e;
use function Gross\Priemka\js;
use function Gross\Priemka\url;

$user = Access::current();

if (isset($_GET['action']))
{
	Api::handle($user);
	die();
}

// Без сессии портала. Из портала (и из мобильного Битрикс24) обработчик получает ключ входа:
// по нему узнаём сотрудника и выдаём странице пропуск. Ключа нет — файл открыли напрямую.
$ticket = '';
$loginError = '';
$via = 'вошёл по сессии портала';
if ($user === null)
{
	$token = Text::scalar($_POST['AUTH_ID'] ?? $_GET['AUTH_ID'] ?? '');
	if ($token === '')
	{
		Visits::add(null, false, 'нет ни сессии портала, ни ключа входа — отправлен на страницу входа портала');
		LocalRedirect('/auth/?backurl=' . urlencode((string)$_SERVER['REQUEST_URI']));
	}
	try
	{
		$user = Access::fromAppToken($token);
		$ticket = Access::ticket($user);
		$via = 'вошёл по ключу входа (без сессии портала)';
	}
	catch (AppError $e)
	{
		$loginError = $e->getMessage();
	}
	catch (\Throwable $e)
	{
		error_log('gross.priemka: ' . $e);
		$loginError = 'Сбой проверки входа — подробности в журнале сервера';
	}
}

$canUse = $user !== null && Access::canUse($user);
$admin = $user !== null && $user['admin'];
Visits::add($user, $canUse, $user === null ? 'не вошёл: ' . $loginError : ($canUse ? $via : $via . ', но нет доступа к программе'));

$boot = [
	'api' => url('index.php'),
	'sessid' => bitrix_sessid(),
	'ticket' => $ticket,
	'user' => $user,
	'minQuery' => Catalog::MIN_QUERY,
	'minProbe' => Catalog::MIN_PROBE,
];

// Шесть фото: четыре стороны машины и две детали.
$photos = [
	'Внешний вид' => [
		['front', 'Вид спереди'],
		['back', 'Вид сзади'],
		['left', 'Вид слева'],
		['right', 'Вид справа'],
	],
	'Детали' => [
		['vin', 'VIN номер (шильдик)'],
		['dash', 'Приборная панель заведённого автомобиля'],
	],
];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="format-detection" content="telephone=no">
<title>Приёмка техники</title>
<link rel="stylesheet" href="<?= e(url('style.css')) ?>">
</head>
<body>

<?php if ($user === null): ?>
<div class="login-view">
  <div class="login-card">
    <h1>Приёмка техники</h1>
    <p class="sub"><b>Не получилось войти.</b> <?= e($loginError) ?>.</p>
    <p class="sub">Закройте программу и откройте её снова из меню портала. Не помогло — откройте портал
      в браузере и войдите в программу оттуда, а администратору перешлите текст выше.</p>
  </div>
</div>
<?php elseif (!$canUse): ?>
<div class="login-view">
  <div class="login-card">
    <h1>Приёмка техники</h1>
    <p class="sub">Программой пользуются сотрудники, которым администратор портала выдал доступ.
      Обратитесь к администратору — он добавит вас во вкладке «Доступ».</p>
  </div>
</div>
<?php else: ?>
<div id="app" class="app-shell<?= $admin ? '' : ' single-tab' ?>">
  <aside class="sidebar">
    <div class="sidebar-brand"><strong>Приёмка техники</strong><span>VIN, фото и дефекты</span></div>
    <nav class="nav">
      <button class="nav-item active" type="button" data-tab="intake">Приёмка</button>
      <?php if ($admin): ?>
      <button class="nav-item" type="button" data-tab="access">Доступ</button>
      <button class="nav-item" type="button" data-tab="check">Проверка</button>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <strong><?= e($user['name']) ?></strong>
      <span><?= $admin ? 'Администратор портала' : 'Сотрудник' ?></span>
    </div>
  </aside>

  <main class="main">
    <!-- ПРИЁМКА -->
    <section id="tab-intake" class="intake">
      <div class="page-head"><div><h2>Приёмка техники</h2>
        <p class="hint">Три шага по порядку: VIN, шесть фото, дефекты. Следующий шаг открывается,
          когда заполнен предыдущий.</p></div></div>

      <!-- 1. VIN -->
      <div class="card step" id="step-vin">
        <div class="step-head">
          <span class="step-num" aria-hidden="true">1</span>
          <div class="step-title"><h3>VIN</h3>
            <p class="desc">Наберите последние 6 цифр VIN — программа предложит машины и покажет, на каких складах они стоят.</p></div>
        </div>
        <div class="field" id="vin-field">
          <label for="vin-input">Введите VIN</label>
          <div class="vin-box">
            <input id="vin-input" class="vin-input" type="text" inputmode="numeric" autocomplete="off" autocorrect="off"
                   autocapitalize="off" spellcheck="false" maxlength="17" pattern="[0-9]*" enterkeyhint="search"
                   placeholder="последние 6 цифр" aria-describedby="vin-hint" aria-controls="vin-results">
            <button class="vin-clear" type="button" id="vin-clear" aria-label="Стереть VIN" hidden>×</button>
          </div>
          <small id="vin-hint" class="vin-hint" role="status">Введите крайние 6 цифр VIN номера</small>
        </div>
        <div class="vin-results" id="vin-results" role="listbox" aria-label="Найденные машины" hidden></div>
        <div class="vin-picked" id="vin-picked" hidden></div>
      </div>

      <!-- 2. ФОТО -->
      <div class="card step locked" id="step-photos">
        <div class="step-head">
          <span class="step-num" aria-hidden="true">2</span>
          <div class="step-title"><h3>Фото автомобиля</h3>
            <p class="desc">Только камерой, не из галереи. Нажмите на рамку — откроется камера.</p></div>
          <span class="step-count" id="photo-count">0 из 6</span>
        </div>
        <p class="step-lock" id="photos-lock">Сначала выберите VIN.</p>
        <?php $n = 0; foreach ($photos as $group => $slots): ?>
        <div class="photo-group">
          <h4><?= e($group) ?></h4>
          <div class="photo-grid">
            <?php foreach ($slots as [$slot, $label]): $n++; ?>
            <label class="photo-tile" data-slot="<?= e($slot) ?>" data-state="empty">
              <input class="photo-input" type="file" accept="image/*" capture="environment" disabled
                     aria-label="<?= e($n . '. ' . $label) ?> — сделать фото">
              <span class="photo-media">
                <img class="photo-img" alt="" hidden>
                <span class="photo-empty"><svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 8.5A1.5 1.5 0 0 1 5.5 7h2.2l1.4-2h5.8l1.4 2h2.2A1.5 1.5 0 0 1 20 8.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 17.5z"/><circle cx="12" cy="13" r="3.4"/></svg><span>Сделать фото</span></span>
                <span class="photo-busy"><span class="spinner" aria-hidden="true"></span>Обработка…</span>
                <span class="photo-ok" aria-hidden="true"><svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="3.5 8.5 6.5 11.5 12.5 5"/></svg></span>
              </span>
              <span class="photo-caption"><span class="photo-num"><?= $n ?></span><span class="photo-label"><?= e($label) ?></span></span>
              <span class="photo-note" aria-live="polite"></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- 3. ДЕФЕКТЫ -->
      <div class="card step locked" id="step-defects">
        <div class="step-head">
          <span class="step-num" aria-hidden="true">3</span>
          <div class="step-title"><h3>Дефекты автомобиля</h3>
            <p class="desc">Напишите текстом или надиктуйте голосом — речь превратится в текст в этом поле.</p></div>
        </div>
        <p class="step-lock" id="defects-lock">Сначала сделайте все 6 фото.</p>
        <div class="field">
          <label for="defects">Дефекты</label>
          <textarea id="defects" rows="5" disabled placeholder="Например: скол на лобовом стекле, царапина на заднем бампере справа. Нет дефектов — так и напишите."></textarea>
        </div>
        <div class="voice">
          <button class="btn ghost voice-btn" type="button" id="voice-btn" disabled>
            <svg class="voice-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11a6.5 6.5 0 0 0 13 0M12 17.5V21"/></svg>
            <span id="voice-label">Надиктовать</span>
          </button>
          <span class="voice-state" id="voice-state" role="status"></span>
        </div>
        <div class="voice-live" id="voice-live" hidden></div>
        <div class="voice-help" id="voice-help" hidden></div>
      </div>

      <div class="submit-bar" id="submit-bar">
        <button class="btn big submit-btn off" type="button" id="submit-btn" aria-describedby="submit-status">Отправить</button>
        <span class="status" id="submit-status" role="status"></span>
      </div>
    </section>

    <?php if ($admin): ?>
    <!-- ДОСТУП -->
    <section id="tab-access" class="hidden">
      <div class="page-head"><div><h2>Доступ</h2>
        <p class="hint">Кто может пользоваться программой. Администраторы портала входят всегда,
          их добавлять не нужно. Изменения действуют сразу.</p></div></div>
      <div class="card"><h3>Сотрудники с доступом</h3>
        <p class="desc">Нажмите на сотрудника, чтобы снять доступ.</p>
        <div class="picker-head"><div class="picker-count">Выдано <b id="access-count">0</b></div></div>
        <div class="chips" id="access-chips">Загрузка…</div>
      </div>
      <div class="card"><h3>Выдать доступ</h3>
        <p class="desc">Список сотрудников — из портала. Нажмите на сотрудника, чтобы выдать или снять доступ.</p>
        <div class="picker-head">
          <div class="field"><input id="user-search" placeholder="Поиск по фамилии, имени, должности, e-mail" autocomplete="off"></div>
        </div>
        <div class="chips" id="user-chips">Загрузка…</div>
      </div>
    </section>

    <!-- ПРОВЕРКА -->
    <section id="tab-check" class="hidden">
      <div class="page-head">
        <div><h2>Проверка</h2>
          <p class="hint">Что программа видит на портале: модули, каталог техники, поля с VIN и склады.</p></div>
        <div class="actions" style="margin:0"><button class="btn ghost" type="button" id="check-run">Проверить заново</button></div>
      </div>
      <div id="check-result">Загрузка…</div>
      <div class="card"><h3>Пробный поиск по VIN</h3>
        <p class="desc">Тот же поиск, что в приёмке: видно, находится ли машина, где у неё VIN и на каких складах она стоит.</p>
        <div class="picker-head">
          <div class="field"><input id="probe-query" placeholder="цифры VIN" autocomplete="off"></div>
          <button class="btn ghost" type="button" id="probe-run">Найти</button>
        </div>
        <div id="probe-result"></div>
      </div>
      <div class="card"><h3>Настройки поиска</h3>
        <p class="desc">Где искать технику. Значения по умолчанию — как в «Перегоне техники»; если на портале каталог или папки другие, поменяйте их здесь, код трогать не нужно.</p>
        <div id="settings-notice"></div>
        <div id="settings-form">Загрузка…</div>
        <div class="actions">
          <button class="btn" type="button" id="settings-save">Сохранить</button>
          <button class="btn ghost" type="button" id="settings-reset">Вернуть значения по умолчанию</button>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </main>
</div>

<script>window.PRIEMKA = <?= js($boot) ?>;</script>
<script src="<?= e(url('app.js')) ?>"></script>
<?php endif; ?>
</body>
</html>

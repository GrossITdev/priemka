<?php
/**
 * «Приёмка техники» — «Путь для первоначальной установки» локального приложения.
 *
 * Пока приложение не сообщило порталу installFinish, Битрикс считает его
 * неустановленным и не показывает пункт в меню. Больше здесь ничего не нужно.
 */

$base = htmlspecialchars(rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/'), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Установка «Приёмки техники»</title>
<link rel="stylesheet" href="<?= $base ?>/style.css">
</head>
<body>
<div class="login-view">
  <div class="login-card">
    <h1>Приёмка техники</h1>
    <p class="sub" id="priemka-state">Устанавливаем…</p>
    <p class="sub">Дальше: откройте «Приёмку техники» в меню слева → вкладка «Проверка».
      Если на этом портале другой каталог товаров — там же, в «Настройках поиска».</p>
  </div>
</div>
<script src="//api.bitrix24.com/api/v1/"></script>
<script>
  (function () {
    var state = document.getElementById('priemka-state');
    if (typeof BX24 === 'undefined') {
      // Файл открыли напрямую, а не из портала: устанавливать нечего.
      state.textContent = 'Эту страницу открывает Битрикс24 при установке приложения.';
      return;
    }
    BX24.init(function () {
      BX24.installFinish();
      state.textContent = 'Приёмка техники установлена. Пункт появится в меню слева.';
    });
  })();
</script>
</body>
</html>

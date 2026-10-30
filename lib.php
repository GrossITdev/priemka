<?php
/**
 * «Приёмка техники» — серверная часть программы.
 *
 * Программа лежит на самом портале и работает через его ядро: каталог товаров
 * и складские остатки — напрямую, без вебхука и REST. Кто открыл программу —
 * из сессии портала. Настройки и доступы хранятся в настройках портала
 * (b_option, модуль gross.priemka): файлов программа не пишет.
 *
 * Фото и текст дефектов на сервер пока не уходят: «Отправить» — заглушка.
 */

namespace Gross\Priemka;

use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;

defined('B_PROLOG_INCLUDED') || exit;

// Предупреждение PHP посреди JSON сломало бы ответ: ошибки — только в журнал сервера.
ini_set('display_errors', '0');


// ---------- общее ----------

/** Текст в HTML без сюрпризов. */
function e(string $text): string
{
	return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Данные для скрипта страницы: безопасно внутри <script>. */
function js($data): string
{
	return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
		| JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Адрес файла программы от корня сайта. Относительный адрес во фрейме портала
 * мог бы разрешиться не от папки программы. Метка времени файла — чтобы после
 * замены файла браузер взял новый.
 */
function url(string $file): string
{
	$base = rtrim(str_replace('\\', '/', dirname((string)$_SERVER['SCRIPT_NAME'])), '/');
	$path = __DIR__ . '/' . $file;
	$version = substr($file, -4) !== '.php' && is_file($path) ? '?v=' . filemtime($path) : '';

	return $base . '/' . $file . $version;
}

/** Ошибка, которую пользователь видит как есть: в тексте нет ничего внутреннего. */
class AppError extends \RuntimeException
{
}

final class Text
{
	/** Значение из браузера — только скаляр: массив вместо строки дал бы «Array». */
	public static function scalar($value): string
	{
		return is_scalar($value) ? (string)$value : '';
	}

	/** Схлопывает пробелы, включая неразрывные, и обрезает края. */
	public static function clean(string $text): string
	{
		return trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
	}

	/**
	 * Текст значения свойства: строка, HTML-свойство {TEXT, TYPE} (бывает и
	 * сериализованным) или список значений — первое непустое.
	 */
	public static function value($value): string
	{
		if (is_string($value) && strncmp($value, 'a:', 2) === 0)
		{
			$data = @unserialize($value, ['allowed_classes' => false]);
			$value = is_array($data) ? $data : $value;
		}
		if (is_array($value))
		{
			if (array_key_exists('TEXT', $value))
			{
				return self::value($value['TEXT']);
			}
			foreach ($value as $item)
			{
				$text = self::value($item);
				if ($text !== '')
				{
					return $text;
				}
			}

			return '';
		}
		$text = (string)preg_replace('/<br\s*\/?>/i', ' ', self::scalar($value));

		return self::clean(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
	}

	/** Флаг: 1, "1", "Y", true — да. */
	public static function flag($value): bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		return in_array(mb_strtolower(self::scalar($value)), ['1', 'y', 'yes', 'true', 'on'], true);
	}
}

final class Portal
{
	/** Модули портала, без которых шаг не сделать. */
	public static function need(string ...$modules): void
	{
		foreach ($modules as $module)
		{
			if (!Loader::includeModule($module))
			{
				throw new AppError('На портале не установлен модуль «' . $module . '»');
			}
		}
	}
}

/** Данные программы в настройках портала: переживают замену файлов. */
final class Store
{
	public const MODULE = 'gross.priemka';

	public static function get(string $name): array
	{
		$data = json_decode((string)Option::get(self::MODULE, $name, ''), true);

		return is_array($data) ? $data : [];
	}

	public static function set(string $name, array $data): void
	{
		$json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false)
		{
			throw new AppError('Не удалось сохранить данные программы: ' . json_last_error_msg());
		}
		Option::set(self::MODULE, $name, $json);
	}
}


// ---------- настройки и доступ ----------

/**
 * Где искать технику. Правится во вкладке «Проверка»; значения по умолчанию —
 * как в «Перегоне техники». На другом портале каталог и папки могут быть другими.
 */
final class Settings
{
	private const DEFAULTS = [
		'catalog_iblock_id' => 15,
		// Пусто — весь каталог.
		'section_ids' => [391, 466],
		// Пусто — «Название» плюс строковые свойства, где в названии или коде есть VIN.
		'vin_fields' => [],
	];

	public static function get(): array
	{
		return self::normalize(array_merge(self::DEFAULTS, Store::get('settings')));
	}

	public static function save(array $input): array
	{
		$s = self::normalize(array_merge(self::get(), array_intersect_key($input, self::DEFAULTS)));
		Store::set('settings', $s);

		return $s;
	}

	public static function reset(): array
	{
		Store::set('settings', []);

		return self::get();
	}

	/** Список положительных ID из массива или строки «391, 466». */
	public static function ids($value): array
	{
		if (is_string($value))
		{
			$value = preg_split('/[\s,;]+/', $value);
		}
		$ids = [];
		foreach ((array)$value as $item)
		{
			$id = (int)Text::scalar($item);
			if ($id > 0)
			{
				$ids[$id] = $id;
			}
		}

		return array_values($ids);
	}

	private static function normalize(array $s): array
	{
		return [
			'catalog_iblock_id' => max(0, (int)Text::scalar($s['catalog_iblock_id'])),
			'section_ids' => self::ids($s['section_ids']),
			'vin_fields' => self::fields($s['vin_fields']),
		];
	}

	/** Поле элемента: стандартное или PROPERTY_<номер>. Остальное — мусор, его не храним. */
	private static function fields($value): array
	{
		$out = [];
		foreach ((array)$value as $field)
		{
			$field = strtoupper(trim(Text::scalar($field)));
			if (preg_match('/^(NAME|CODE|XML_ID|PROPERTY_\d+)$/', $field))
			{
				$out[$field] = $field;
			}
		}

		return array_values($out);
	}
}

/**
 * Кто открыл программу и пускать ли его. Администраторы портала входят всегда.
 *
 * Обычно сотрудник известен из сессии портала (cookie). Мобильный Битрикс24 может
 * открыть программу без этой cookie — тогда сотрудник узнаётся по ключу входа
 * (AUTH_ID), который портал передаёт программе при каждом открытии, а страница
 * получает свой пропуск (ticket) для запросов к программе.
 */
final class Access
{
	/** Сколько живёт пропуск страницы, открытой по ключу входа: смена с запасом. */
	private const TICKET_TTL = 12 * 3600;

	public static function current(): ?array
	{
		global $USER;
		if (!is_object($USER) || !$USER->IsAuthorized())
		{
			return null;
		}
		$name = Text::clean((string)$USER->GetFullName());

		return [
			'id' => (int)$USER->GetID(),
			'admin' => (bool)$USER->IsAdmin(),
			'name' => $name !== '' ? $name : (string)$USER->GetLogin(),
		];
	}

	/** Сотрудник по ID: только активный. Администратор — в группе 1, как у $USER->IsAdmin(). */
	public static function byId(int $id): ?array
	{
		if ($id <= 0)
		{
			return null;
		}
		$row = UserTable::getList([
			'select' => ['ID', 'LOGIN', 'NAME', 'LAST_NAME'],
			'filter' => ['=ID' => $id, '=ACTIVE' => 'Y'],
		])->fetch();
		if (!$row)
		{
			return null;
		}
		$name = Text::clean($row['NAME'] . ' ' . $row['LAST_NAME']);

		return [
			'id' => (int)$row['ID'],
			'admin' => in_array(1, array_map('intval', (array)\CUser::GetUserGroup($id)), true),
			'name' => $name !== '' ? $name : (string)$row['LOGIN'],
		];
	}

	/**
	 * Сотрудник по ключу входа, который портал передал программе (AUTH_ID).
	 * Ключ проверяет сам портал, и выдан он должен быть именно этой программе:
	 * ключ другого приложения портала сюда не пускает.
	 */
	public static function fromAppToken(string $token): array
	{
		Portal::need('rest');
		$auth = [];
		$scope = defined('CRestUtil::GLOBAL_SCOPE') ? \CRestUtil::GLOBAL_SCOPE : '_global';
		if (\CRestUtil::checkAuth(['auth' => $token], $scope, $auth) !== true || !empty($auth['error']))
		{
			$reason = Text::scalar($auth['error_description'] ?? '') ?: Text::scalar($auth['error'] ?? '');
			throw new AppError('Портал не принял ключ входа' . ($reason !== '' ? ' (' . $reason . ')' : ''));
		}
		if (!App::isOurClient(Text::scalar($auth['client_id'] ?? '')))
		{
			throw new AppError('Ключ входа выдан другому приложению портала');
		}
		$user = self::byId((int)Text::scalar($auth['user_id'] ?? 0));
		if ($user === null)
		{
			throw new AppError('Сотрудник по ключу входа не найден или отключён');
		}

		return $user;
	}

	/** Пропуск страницы: ID сотрудника и срок, подписанные ключом программы. */
	public static function ticket(array $user): string
	{
		$payload = self::b64((string)json_encode(['u' => $user['id'], 'e' => time() + self::TICKET_TTL]));

		return $payload . '.' . self::sign($payload);
	}

	/** Сотрудник по пропуску страницы. Подпись не сошлась или срок вышел — null. */
	public static function fromTicket(string $ticket): ?array
	{
		$parts = explode('.', $ticket);
		if (count($parts) !== 2 || !hash_equals(self::sign($parts[0]), $parts[1]))
		{
			return null;
		}
		$data = json_decode((string)base64_decode(strtr($parts[0], '-_', '+/')), true);
		if (!is_array($data) || (int)($data['e'] ?? 0) < time())
		{
			return null;
		}

		return self::byId((int)($data['u'] ?? 0));
	}

	private static function sign(string $payload): string
	{
		$key = (string)Option::get(Store::MODULE, 'ticket_key', '');
		if (strlen($key) < 64)
		{
			// Ключ создаётся один раз и живёт в настройках портала; файлы программы его не содержат.
			$key = bin2hex(random_bytes(32));
			Option::set(Store::MODULE, 'ticket_key', $key);
		}

		return self::b64(hash_hmac('sha256', $payload, $key, true));
	}

	private static function b64(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	public static function allowed(): array
	{
		return Settings::ids(Store::get('users'));
	}

	public static function canUse(array $user): bool
	{
		return $user['admin'] || in_array($user['id'], self::allowed(), true);
	}

	public static function grant(int $id): void
	{
		Store::set('users', array_values(array_unique(array_merge(self::allowed(), [$id]))));
	}

	public static function revoke(int $id): void
	{
		Store::set('users', array_values(array_diff(self::allowed(), [$id])));
	}
}

/**
 * Последние открытия программы — для вкладки «Проверка». Видно, дошёл ли телефон
 * до программы вообще (белый экран без строки здесь — запрос не дошёл) и чем кончился вход.
 */
final class Visits
{
	private const KEEP = 12;

	public static function add(?array $user, bool $ok, string $result): void
	{
		try
		{
			$request = Application::getInstance()->getContext()->getRequest();
			$ua = Text::clean((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
			$mobile = (bool)preg_match('/BitrixMobile|Bitrix24/i', $ua);
			$os = preg_match('/iphone|ipad|ipod/i', $ua) ? 'iPhone' : (preg_match('/android/i', $ua) ? 'Android'
				: (preg_match('/windows/i', $ua) ? 'Windows' : (preg_match('/macintosh/i', $ua) ? 'Mac' : '')));
			$items = Store::get('visits');
			array_unshift($items, [
				'at' => time(),
				'user' => $user['name'] ?? '',
				'client' => ($mobile ? 'мобильный Битрикс24' : 'браузер') . ($os !== '' ? ', ' . $os : ''),
				'mobile' => $mobile,
				'url' => ($request->isHttps() ? 'https' : 'http') . '://' . $request->getHttpHost(),
				'ok' => $ok,
				'result' => $result,
				'ua' => mb_substr($ua, 0, 300),
			]);
			Store::set('visits', array_slice($items, 0, self::KEEP));
		}
		catch (\Throwable $e)
		{
			// Журнал — подсказка для проверки: его сбой не должен мешать открыть программу.
			error_log('gross.priemka: ' . $e);
		}
	}

	public static function list(): array
	{
		return array_values(array_filter(Store::get('visits'), 'is_array'));
	}
}

/**
 * Эта программа в «Приложениях» портала (b_rest_app). Своя запись узнаётся по адресу
 * обработчика, а не по коду client_id: на тестовом и боевом портале коды разные.
 */
final class App
{
	/** Записи приложений, чей обработчик — эта программа. */
	public static function records(): array
	{
		Portal::need('rest');
		$out = [];
		$rows = \Bitrix\Rest\AppTable::getList(['select' => ['*']]);
		while ($row = $rows->fetch())
		{
			if (self::isOurUrl((string)($row['URL'] ?? '')))
			{
				$out[] = $row;
			}
		}

		return $out;
	}

	public static function isOurClient(string $clientId): bool
	{
		if ($clientId === '')
		{
			return false;
		}
		$row = \Bitrix\Rest\AppTable::getList([
			'select' => ['ID', 'URL'],
			'filter' => ['=CLIENT_ID' => $clientId],
		])->fetch();

		return $row && self::isOurUrl((string)$row['URL']);
	}

	public static function isOurUrl(string $url): bool
	{
		return $url !== '' && self::path($url) === self::path(url('index.php'));
	}

	/** Путь без index.php и косой в конце: «…/priemka/», «…/priemka/index.php» — одно и то же. */
	private static function path(string $url): string
	{
		$path = str_replace('\\', '/', (string)parse_url($url, PHP_URL_PATH));

		return mb_strtolower((string)preg_replace('~/+(index\.php)?$~i', '', $path));
	}
}

/** Сотрудники портала — для выбора во вкладке «Доступ». */
final class Users
{
	private const LIMIT = 50;

	public static function search(string $query): array
	{
		$filter = ['=ACTIVE' => 'Y', self::people()];
		foreach (preg_split('/\s+/u', Text::clean($query), -1, PREG_SPLIT_NO_EMPTY) as $word)
		{
			$filter[] = [
				'LOGIC' => 'OR',
				'%LAST_NAME' => $word,
				'%NAME' => $word,
				'%SECOND_NAME' => $word,
				'%WORK_POSITION' => $word,
				'%EMAIL' => $word,
				'%LOGIN' => $word,
			];
		}

		return self::load($filter, self::LIMIT);
	}

	/** Карточки по ID. Уволенный остаётся в списке доступа — его видно и можно убрать. */
	public static function describe(array $ids): array
	{
		$cards = $ids ? self::load(['@ID' => $ids], 0) : [];
		foreach (array_diff($ids, array_column($cards, 'id')) as $id)
		{
			$cards[] = ['id' => $id, 'name' => 'Пользователь #' . $id, 'position' => 'удалён с портала', 'active' => false];
		}

		return $cards;
	}

	private static function load(array $filter, int $limit): array
	{
		$query = [
			'select' => ['ID', 'LOGIN', 'EMAIL', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'WORK_POSITION', 'ACTIVE'],
			'filter' => $filter,
			'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC', 'ID' => 'ASC'],
		];
		if ($limit > 0)
		{
			$query['limit'] = $limit;
		}
		$format = \CSite::GetNameFormat(false);
		$out = [];
		$rows = UserTable::getList($query);
		while ($row = $rows->fetch())
		{
			$name = Text::clean((string)\CUser::FormatName($format, $row, true, false));
			$out[] = [
				'id' => (int)$row['ID'],
				'name' => $name !== '' ? $name : '#' . $row['ID'],
				'position' => Text::clean((string)$row['WORK_POSITION']),
				'active' => Text::flag($row['ACTIVE']),
			];
		}

		return $out;
	}

	/** Живые люди: без ботов, почтовых и прочих служебных пользователей. */
	private static function people(): array
	{
		$external = method_exists(UserTable::class, 'getExternalUserTypes')
			? UserTable::getExternalUserTypes()
			: ['bot', 'email', 'imconnector', 'replica', 'controller', 'shop', 'sale', 'saleanonymous'];

		return ['LOGIC' => 'OR', '=EXTERNAL_AUTH_ID' => null, '!@EXTERNAL_AUTH_ID' => $external];
	}
}


// ---------- каталог и склады ----------

/** Поля элементов инфоблока, где имеет смысл искать VIN. */
final class Fields
{
	public const CATALOG_BASE = ['NAME' => 'Название', 'CODE' => 'Символьный код', 'XML_ID' => 'Внешний код'];

	private static array $cache = [];

	/** Строковые свойства инфоблока: привязки, списки и файлы текста не несут. */
	public static function properties(int $iblockId): array
	{
		if (!isset(self::$cache[$iblockId]))
		{
			Portal::need('iblock');
			$out = [];
			$rows = PropertyTable::getList([
				'select' => ['ID', 'NAME', 'CODE', 'USER_TYPE'],
				'filter' => ['=IBLOCK_ID' => $iblockId, '=ACTIVE' => 'Y', '=PROPERTY_TYPE' => 'S'],
				'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
			]);
			while ($row = $rows->fetch())
			{
				if (in_array((string)$row['USER_TYPE'], ['', 'HTML'], true))
				{
					$out[] = [
						'field' => 'PROPERTY_' . (int)$row['ID'],
						'name' => Text::clean((string)$row['NAME']),
						'code' => (string)$row['CODE'],
					];
				}
			}
			self::$cache[$iblockId] = $out;
		}

		return self::$cache[$iblockId];
	}

	/** Поля для выбора в настройках: стандартные и строковые свойства. */
	public static function options(int $iblockId): array
	{
		$out = [];
		foreach (self::CATALOG_BASE as $field => $name)
		{
			$out[] = ['field' => $field, 'name' => $name];
		}
		foreach (self::properties($iblockId) as $property)
		{
			$code = $property['code'] !== '' ? ' [' . $property['code'] . ']' : '';
			$out[] = ['field' => $property['field'], 'name' => $property['name'] . $code];
		}

		return $out;
	}

	/** Свойства, у которых название или код подходят под шаблон: так находится VIN. */
	public static function matching(int $iblockId, string $pattern): array
	{
		$out = [];
		foreach (self::properties($iblockId) as $property)
		{
			if (preg_match($pattern, $property['name'] . ' ' . $property['code']))
			{
				$out[] = $property['field'];
			}
		}

		return $out;
	}

	/** Значение поля из строки CIBlockElement::GetList: свойство приходит как PROPERTY_<ID>_VALUE. */
	public static function value(array $row, string $field): string
	{
		return Text::value($row[strpos($field, 'PROPERTY_') === 0 ? $field . '_VALUE' : $field] ?? '');
	}
}

/**
 * Техника из товарного каталога CRM: поиск по концу VIN и склады, где она стоит.
 *
 * Сотрудник набирает последние 6 знаков VIN — в выдаче машины, у которых VIN
 * этим кончается (они первыми), и те, где эти знаки просто встречаются. К
 * каждой — склады с положительным остатком.
 */
final class Catalog
{
	/** Столько знаков VIN набирает сотрудник: последние шесть. */
	public const MIN_QUERY = 6;
	/** Пробный поиск во вкладке «Проверка» начинается раньше. */
	public const MIN_PROBE = 3;
	/** Столько машин показываем: больше человек на телефоне не просмотрит. */
	private const LIMIT = 20;
	/** Столько читаем из каталога, чтобы было из чего отобрать лучшие. */
	private const FETCH = 60;

	public static function search(array $s, string $query, int $min = self::MIN_QUERY): array
	{
		$key = self::vinKey($query);
		if (mb_strlen($key) < $min)
		{
			return ['items' => [], 'more' => false];
		}
		$items = self::find($s, $key);
		usort($items, static function (array $a, array $b) {
			return [$a['rank'], $a['label'], $a['id']] <=> [$b['rank'], $b['label'], $b['id']];
		});
		$more = count($items) > self::LIMIT;
		$items = array_slice($items, 0, self::LIMIT);
		foreach ($items as &$item)
		{
			unset($item['rank']);
		}
		unset($item);

		return ['items' => self::withStores($s, $items), 'more' => $more];
	}

	/** Поля, где ищется VIN: из настроек, иначе «Название» и свойства со словом VIN. */
	public static function vinFields(array $s): array
	{
		return $s['vin_fields']
			?: array_merge(['NAME'], Fields::matching($s['catalog_iblock_id'], '/vin|(^|[^\p{L}])вин([^\p{L}]|$)/iu'));
	}

	/** VIN для сверки: без регистра, пробелов и дефисов. */
	public static function vinKey(string $text): string
	{
		return (string)preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper($text));
	}

	/** @return array<int, string> ID папки → название; папки другого инфоблока не в счёт */
	public static function sectionNames(int $iblockId, array $ids): array
	{
		$ids = array_values(array_filter(array_unique(array_map('intval', $ids))));
		if (!$ids)
		{
			return [];
		}
		Portal::need('iblock');
		$out = [];
		$rows = SectionTable::getList([
			'select' => ['ID', 'NAME'],
			'filter' => ['@ID' => $ids, '=IBLOCK_ID' => $iblockId],
		]);
		while ($row = $rows->fetch())
		{
			$out[(int)$row['ID']] = Text::clean((string)$row['NAME']);
		}

		return $out;
	}

	private static function find(array $s, string $key): array
	{
		Portal::need('iblock');
		if ($s['catalog_iblock_id'] <= 0)
		{
			throw new AppError('В настройках программы не задан каталог товаров — администратор указывает его во вкладке «Проверка»');
		}
		$fields = self::vinFields($s);
		$any = ['LOGIC' => 'OR'];
		foreach ($fields as $field)
		{
			$any[] = ['%' . $field => $key];
		}
		$filter = [
			'IBLOCK_ID' => $s['catalog_iblock_id'],
			'ACTIVE' => 'Y',
			// Права смотрим сами — вкладкой «Доступ»; каталог сотруднику открывать не нужно.
			'CHECK_PERMISSIONS' => 'N',
			$any,
		];
		if ($s['section_ids'])
		{
			$filter['SECTION_ID'] = $s['section_ids'];
			$filter['INCLUDE_SUBSECTIONS'] = 'Y';
		}
		$properties = array_values(array_diff($fields, ['NAME']));
		$rows = \CIBlockElement::GetList(
			['ID' => 'DESC'],
			$filter,
			false,
			['nTopCount' => self::FETCH],
			array_merge(['ID', 'IBLOCK_ID', 'NAME', 'IBLOCK_SECTION_ID'], $properties)
		);
		$items = [];
		while ($row = $rows->Fetch())
		{
			$id = (int)$row['ID'];
			if ((int)$row['IBLOCK_ID'] !== $s['catalog_iblock_id'])
			{
				continue;
			}
			// Свойство с несколькими значениями размножает строку товара — собираем в одну карточку.
			if (!isset($items[$id]))
			{
				$items[$id] = [
					'id' => $id,
					'name' => Text::clean(Text::scalar($row['NAME'])),
					'section' => (int)$row['IBLOCK_SECTION_ID'],
					'vins' => [],
				];
			}
			foreach ($properties as $field)
			{
				// В одной ячейке бывает несколько VIN: «4583/X89777222R0EV8014», «VIN1, VIN2».
				foreach (preg_split('/[\s,;\/]+/u', Fields::value($row, $field), -1, PREG_SPLIT_NO_EMPTY) as $value)
				{
					$items[$id]['vins'][$value] = $value;
				}
			}
		}
		$sections = self::sectionNames($s['catalog_iblock_id'], array_column($items, 'section'));
		$out = [];
		foreach ($items as $item)
		{
			[$rank, $vin] = self::rank($item, $key, in_array('NAME', $fields, true));
			$out[] = [
				'id' => $item['id'],
				'name' => $item['name'],
				'vin' => $vin,
				// Что встаёт в поле VIN: сам VIN, а если его нет в карточке — название товара.
				'label' => $vin !== '' ? $vin : $item['name'],
				'section' => $sections[$item['section']] ?? '',
				'rank' => $rank,
			];
		}

		return $out;
	}

	/**
	 * Насколько VIN товара подходит к набранному: 0 — VIN этим кончается,
	 * 1 — знаки встречаются внутри VIN, 2 — нашлось только в названии.
	 *
	 * @return array{0: int, 1: string} ранг и VIN, который показать
	 */
	private static function rank(array $item, string $key, bool $byName): array
	{
		$best = [3, ''];
		$candidates = array_values($item['vins']);
		if ($byName)
		{
			// VIN часто записан прямо в названии товара отдельным словом.
			foreach (preg_split('/[^\p{L}\p{N}]+/u', $item['name'], -1, PREG_SPLIT_NO_EMPTY) as $word)
			{
				if (preg_match('/^[A-Z0-9]{11,17}$/i', $word))
				{
					$candidates[] = $word;
				}
			}
		}
		foreach ($candidates as $value)
		{
			$vin = self::vinKey($value);
			$length = strlen($key);
			if ($vin === '' || strpos($vin, $key) === false)
			{
				continue;
			}
			$rank = strlen($vin) >= $length && substr($vin, -$length) === $key ? 0 : 1;
			if ($rank < $best[0])
			{
				$best = [$rank, $value];
			}
		}
		if ($best[0] === 3)
		{
			$fallback = array_values($item['vins'])[0] ?? '';

			return [2, $fallback];
		}

		return $best;
	}

	/** ID для складского учёта: выбранный товар и связанные с ним предложения/родитель. */
	private static function stockProductIds(array $s, array $ids): array
	{
		$linked = [];
		foreach ($ids as $id)
		{
			$linked[$id] = [$id => $id];
		}
		if (!class_exists('CCatalogSku'))
		{
			return $linked;
		}
		$parents = \CCatalogSku::getProductList($ids) ?: [];
		foreach ($parents as $offerId => $parent)
		{
			$parentId = (int)($parent['ID'] ?? 0);
			if (isset($linked[$offerId]) && $parentId > 0 && (int)($parent['IBLOCK_ID'] ?? 0) === $s['catalog_iblock_id'])
			{
				$linked[$offerId][$parentId] = $parentId;
			}
		}
		// Для выбранного предложения читаем только его остаток и родителя:
		// соседнее предложение может быть другой машиной с собственным VIN.
		$products = array_values(array_diff($ids, array_keys($parents)));
		$offers = $products ? (\CCatalogSku::getOffersList($products, $s['catalog_iblock_id'], ['ACTIVE' => 'Y'], ['ID', 'IBLOCK_ID']) ?: []) : [];
		foreach ($products as $productId)
		{
			foreach ($offers[$productId] ?? [] as $offer)
			{
				$offerId = (int)($offer['ID'] ?? 0);
				if ($offerId > 0)
				{
					$linked[$productId][$offerId] = $offerId;
				}
			}
		}

		return $linked;
	}

	/**
	 * Где машина стоит сейчас: склады с положительным остатком товара и его
	 * связанной карточки SKU (вкладка «Склады» товара). Остатки только читаются.
	 */
	private static function withStores(array $s, array $items): array
	{
		if (!$items)
		{
			return [];
		}
		$byProduct = [];
		$stockIds = [];
		$failure = '';
		try
		{
			Portal::need('catalog');
			$stockIds = self::stockProductIds($s, array_column($items, 'id'));
			$requested = [];
			foreach ($stockIds as $ids)
			{
				$requested += array_fill_keys($ids, true);
			}
			$rows = \Bitrix\Catalog\StoreProductTable::getList([
				'select' => ['PRODUCT_ID', 'STORE_ID', 'AMOUNT', 'STORE_TITLE' => 'STORE.TITLE', 'STORE_ADDRESS' => 'STORE.ADDRESS'],
				'filter' => ['@PRODUCT_ID' => array_keys($requested), '>AMOUNT' => 0, '=STORE.ACTIVE' => 'Y'],
				'order' => ['STORE_ID' => 'ASC', 'PRODUCT_ID' => 'ASC'],
			]);
			while ($row = $rows->fetch())
			{
				$productId = (int)$row['PRODUCT_ID'];
				$storeId = (int)$row['STORE_ID'];
				if (!isset($requested[$productId]) || $storeId <= 0 || (float)$row['AMOUNT'] <= 0)
				{
					continue;
				}
				$title = Text::clean(Text::scalar($row['STORE_TITLE'] ?? ''));
				if ($title === '')
				{
					$title = Text::clean(Text::scalar($row['STORE_ADDRESS'] ?? ''));
				}
				$byProduct[$productId][$storeId] = [
					'id' => $storeId,
					'title' => $title !== '' ? $title : 'Склад #' . $storeId,
					'amount' => (float)$row['AMOUNT'],
				];
			}
		}
		catch (\Throwable $e)
		{
			error_log('gross.priemka stores: ' . $e);
			$failure = 'Складские остатки не прочитались';
		}
		foreach ($items as &$item)
		{
			$stores = [];
			foreach ($stockIds[$item['id']] ?? [$item['id']] as $productId)
			{
				// Один склад в остатках товара и его предложения — один раз, количество суммируем.
				foreach ($byProduct[$productId] ?? [] as $storeId => $store)
				{
					if (isset($stores[$storeId]))
					{
						$stores[$storeId]['amount'] += $store['amount'];
					}
					else
					{
						$stores[$storeId] = $store;
					}
				}
			}
			$item['stores'] = array_values($stores);
			$item['storesNote'] = $failure;
		}
		unset($item);

		return $items;
	}
}


// ---------- проверка ----------

final class Diagnostics
{
	public static function run(array $s): array
	{
		$blocks = [
			'portal' => 'Портал',
			'catalog' => 'Каталог техники',
			'stores' => 'Склады',
			'mobile' => 'Мобильный Битрикс24',
			'visits' => 'Последние открытия программы',
		];
		$out = [];
		foreach ($blocks as $method => $title)
		{
			$report = new Report();
			try
			{
				self::$method($s, $report);
			}
			catch (\Throwable $e)
			{
				$report->error($e->getMessage());
			}
			$out[] = ['title' => $title, 'items' => $report->items];
		}

		return $out;
	}

	private static function portal(array $s, Report $r): void
	{
		foreach (['iblock' => 'каталог товаров', 'catalog' => 'складские остатки'] as $module => $what)
		{
			$r->check(Loader::includeModule($module), 'Модуль «' . $module . '» (' . $what . ')',
				'Модуль «' . $module . '» не установлен — ' . $what . ' недоступны');
		}
		$r->info('Данные портала программа берёт напрямую через его ядро — вебхук не нужен');
		$r->info('Сотрудников с доступом: ' . count(Access::allowed()) . ', плюс все администраторы портала');
		$https = Application::getInstance()->getContext()->getRequest()->isHttps();
		$https
			? $r->ok('Портал открыт по https: кнопка «Записать голосом» пишет речь прямо в программе (Chrome, Safari, Edge)')
			: $r->warn('Портал открыт по http: браузеры не дают сайтам на http микрофон. Голос — через диктовку клавиатуры'
				. ' телефона (кнопка «Надиктовать» подскажет, где она). На https запись заработает в самой программе');
		$r->info('Фото и дефекты пока никуда не отправляются: «Отправить» — заглушка, проверяет только заполнение');
	}

	private static function visits(array $s, Report $r): void
	{
		$items = Visits::list();
		if (!$items)
		{
			$r->info('Открытий пока не было. Откройте программу на телефоне и нажмите «Проверить заново»');

			return;
		}
		$r->info('Открыли программу на телефоне, а строки с телефона здесь нет — запрос до программы не дошёл.'
			. ' Чаще всего обработчик на http: смотрите блок «Мобильный Битрикс24» выше');
		foreach ($items as $visit)
		{
			$line = date('d.m H:i:s', (int)($visit['at'] ?? 0))
				. ' · ' . (Text::scalar($visit['user'] ?? '') ?: 'сотрудник не известен')
				. ' · ' . Text::scalar($visit['client'] ?? '')
				. ' · ' . Text::scalar($visit['url'] ?? '')
				. ' · ' . Text::scalar($visit['result'] ?? '');
			$ok = Text::flag($visit['ok'] ?? false);
			$ok ? $r->ok($line) : $r->warn($line);
			if (Text::flag($visit['mobile'] ?? false) && Text::scalar($visit['ua'] ?? '') !== '')
			{
				$r->info('Браузер: ' . Text::scalar($visit['ua']));
			}
		}
	}

	private static function mobile(array $s, Report $r): void
	{
		$apps = App::records();
		if (!$apps)
		{
			$r->warn('Среди приложений портала нет программы с обработчиком ' . url('index.php')
				. ' — проверьте «Путь вашего обработчика» в настройках приложения');

			return;
		}
		$request = Application::getInstance()->getContext()->getRequest();
		$host = mb_strtolower((string)$request->getHttpHost());
		foreach ($apps as $app)
		{
			$handler = (string)$app['URL'];
			$r->info('Обработчик: ' . $handler);
			Text::flag($app['MOBILE'] ?? '')
				? $r->ok('Отмечено «Поддерживает BitrixMobile» — пункт есть в мобильном Битрикс24')
				: $r->warn('Не отмечено «Поддерживает BitrixMobile» — в мобильном Битрикс24 программы не будет');
			$handlerHost = mb_strtolower((string)parse_url($handler, PHP_URL_HOST));
			$handlerPort = parse_url($handler, PHP_URL_PORT);
			if ($handlerPort)
			{
				$handlerHost .= ':' . $handlerPort;
			}
			if ($handlerHost !== '' && $handlerHost !== $host)
			{
				$r->warn('Обработчик на ' . $handlerHost . ', а портал открыт на ' . $host
					. ': телефон откроет программу без сессии портала. Адрес обработчика должен быть на том же домене');
			}
			mb_strtolower((string)parse_url($handler, PHP_URL_SCHEME)) === 'https'
				? $r->ok('Обработчик на https — мобильный Битрикс24 открывает его и на Android, и на iPhone')
				: $r->error('Обработчик на http: мобильный Битрикс24 на iPhone такой адрес не открывает — белый экран,'
					. ' запрос до программы не доходит. Укажите адрес обработчика и установщика на https');
		}
		$r->info('В мобильном Битрикс24 программа узнаёт сотрудника по ключу входа, который портал передаёт при открытии,'
			. ' — даже если окно открылось без сессии портала');
	}

	private static function catalog(array $s, Report $r): void
	{
		Portal::need('iblock');
		$iblockId = $s['catalog_iblock_id'];
		$iblock = \CIBlock::GetArrayByID($iblockId);
		if (!is_array($iblock))
		{
			$r->error('Инфоблок каталога #' . $iblockId . ' не найден — поправьте в «Настройках поиска» ниже');

			return;
		}
		$r->ok('Инфоблок #' . $iblockId . ' «' . $iblock['NAME'] . '»');
		if (Loader::includeModule('catalog'))
		{
			$sku = \CCatalogSku::GetInfoByIBlock($iblockId);
			$type = is_array($sku) ? (string)$sku['CATALOG_TYPE'] : '';
			if ($type === 'O' || $type === 'X')
			{
				$r->error('Это инфоблок торговых предложений, а нужен инфоблок товаров');
			}
			elseif ($type === '')
			{
				$r->warn('Инфоблок не отмечен как торговый каталог — проверьте, что ID верный');
			}
		}

		if (!$s['section_ids'])
		{
			$count = (int)\CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'CHECK_PERMISSIONS' => 'N'], []);
			$r->info('Папки не заданы — поиск по всему каталогу: активных товаров ' . $count);
		}
		$names = Catalog::sectionNames($iblockId, $s['section_ids']);
		foreach ($s['section_ids'] as $root)
		{
			if (!isset($names[$root]))
			{
				$r->error('Папка #' . $root . ' не найдена в инфоблоке #' . $iblockId);
				continue;
			}
			$count = (int)\CIBlockElement::GetList([], [
				'IBLOCK_ID' => $iblockId,
				'SECTION_ID' => $root,
				'INCLUDE_SUBSECTIONS' => 'Y',
				'ACTIVE' => 'Y',
				'CHECK_PERMISSIONS' => 'N',
			], []);
			$title = 'Папка #' . $root . ' «' . $names[$root] . '» с вложенными';
			$count > 0 ? $r->ok($title . ': активных товаров ' . $count) : $r->warn($title . ': активных товаров нет');
		}

		$titles = [];
		foreach (Fields::options($iblockId) as $option)
		{
			$titles[$option['field']] = $option['name'];
		}
		$fields = Catalog::vinFields($s);
		$r->info('VIN ищется в полях: ' . implode(', ', array_map(static function ($field) use ($titles) {
			return $titles[$field] ?? $field;
		}, $fields)) . ($s['vin_fields'] ? '' : ' (подобраны автоматически)'));
		if ($fields === ['NAME'])
		{
			$r->warn('Свойство с VIN не найдено — поиск идёт только по названию товара. Если VIN хранится'
				. ' в свойстве, отметьте его в «Настройках поиска» и проверьте пробным поиском');
		}
		$r->info('Сотрудник набирает последние ' . Catalog::MIN_QUERY . ' знаков VIN: первыми идут машины, у которых VIN ими кончается');
	}

	private static function stores(array $s, Report $r): void
	{
		Portal::need('catalog');
		$rows = \Bitrix\Catalog\StoreTable::getList([
			'select' => ['ID', 'TITLE', 'ADDRESS'],
			'filter' => ['=ACTIVE' => 'Y'],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		]);
		$names = [];
		while ($row = $rows->fetch())
		{
			$title = Text::clean(Text::scalar($row['TITLE'] ?? ''));
			if ($title === '')
			{
				$title = Text::clean(Text::scalar($row['ADDRESS'] ?? ''));
			}
			$names[] = $title !== '' ? $title : 'Склад #' . $row['ID'];
		}
		if (!$names)
		{
			$r->warn('Активных складов нет — у всех машин в подсказках будет «нет на складах»');

			return;
		}
		$r->ok('Активных складов: ' . count($names) . '. Например: ' . implode('; ', array_slice($names, 0, 4))
			. (count($names) > 4 ? '…' : ''));
		$r->info('Под каждой машиной в подсказках — склады с положительным остатком (вкладка «Склады» товара).'
			. ' Остатки только читаются, ничего не меняется');
	}
}

final class Report
{
	public array $items = [];

	public function ok(string $text): void
	{
		$this->items[] = ['status' => 'ok', 'text' => $text];
	}

	public function info(string $text): void
	{
		$this->items[] = ['status' => 'info', 'text' => $text];
	}

	public function warn(string $text): void
	{
		$this->items[] = ['status' => 'warn', 'text' => $text];
	}

	public function error(string $text): void
	{
		$this->items[] = ['status' => 'error', 'text' => $text];
	}

	public function check(bool $condition, string $ok, string $error): void
	{
		$condition ? $this->ok($ok) : $this->error($error);
	}
}


// ---------- ответы странице ----------

/**
 * JSON для страницы (index.php?action=…). Кто спрашивает — из сессии портала
 * плюс сверка ключа сессии Битрикса, а если страница открыта по ключу входа —
 * из её пропуска (Access::ticket). Доступ проверяется на КАЖДОМ запросе:
 * снятый сотрудник теряет его сразу, а не когда обновит страницу.
 */
final class Api
{
	public static function handle(?array $user): void
	{
		global $APPLICATION;
		try
		{
			if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
			{
				throw new AppError('Нужен POST-запрос');
			}
			$input = json_decode((string)file_get_contents('php://input'), true);
			$input = is_array($input) ? $input : [];
			$ticket = Text::scalar($input['ticket'] ?? '');
			if ($ticket !== '')
			{
				// Страница открыта по ключу входа (мобильный Битрикс24 без сессии портала).
				// Пропуск идёт в теле запроса, не в cookie, — чужой сайт его не подставит.
				$user = Access::fromTicket($ticket);
				if ($user === null)
				{
					throw new AppError('Вход устарел — закройте программу и откройте её заново');
				}
			}
			else
			{
				if ($user === null)
				{
					throw new AppError('Сессия портала закончилась — откройте программу заново');
				}
				if (!hash_equals((string)bitrix_sessid(), Text::scalar($input['sessid'] ?? '')))
				{
					throw new AppError('Страница устарела — обновите её');
				}
			}
			$answer = ['ok' => true, 'data' => self::dispatch(Text::scalar($_GET['action'] ?? ''), $input, $user)];
		}
		catch (AppError $e)
		{
			$answer = ['ok' => false, 'error' => $e->getMessage()];
		}
		catch (\Throwable $e)
		{
			// Текст внутренней ошибки — пути и классы — нужен тому, кто чинит, а не сотруднику.
			error_log('gross.priemka: ' . $e);
			$details = $user !== null && $user['admin'] ? ': ' . $e->getMessage() : ' — подробности в журнале сервера';
			$answer = ['ok' => false, 'error' => 'Сбой программы' . $details];
		}
		// Лишний вывод портала до нас (пробел или BOM в init.php) сломал бы JSON.
		if (is_object($APPLICATION))
		{
			$APPLICATION->RestartBuffer();
		}
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Content-Type-Options: nosniff');
		echo json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	}

	private static function dispatch(string $action, array $in, array $user)
	{
		$isAdminAction = strpos($action, 'admin.') === 0;
		if ($isAdminAction && !$user['admin'])
		{
			throw new AppError('Это действие доступно только администраторам портала');
		}
		if (!$isAdminAction && !Access::canUse($user))
		{
			throw new AppError('У вас нет доступа к программе — обратитесь к администратору портала');
		}

		$s = Settings::get();
		$id = (int)Text::scalar($in['id'] ?? 0);
		switch ($action)
		{
			case 'vin.search':
				return Catalog::search($s, Text::scalar($in['q'] ?? ''));

			case 'admin.search':
				return Catalog::search($s, Text::scalar($in['q'] ?? ''), Catalog::MIN_PROBE);
			case 'admin.users':
				return ['items' => Users::describe(Access::allowed())];
			case 'admin.users.search':
				return ['items' => Users::search(Text::scalar($in['q'] ?? ''))];
			case 'admin.users.add':
				$found = $id > 0 ? Users::describe([$id]) : [];
				if (!$found || !$found[0]['active'])
				{
					throw new AppError('Пользователь #' . $id . ' не найден или уволен');
				}
				Access::grant($id);

				return ['items' => Users::describe(Access::allowed())];
			case 'admin.users.remove':
				Access::revoke($id);

				return ['items' => Users::describe(Access::allowed())];
			case 'admin.settings':
				return self::settings($s);
			case 'admin.settings.save':
				return self::settings(Settings::save(is_array($in['settings'] ?? null) ? $in['settings'] : []));
			case 'admin.settings.reset':
				return self::settings(Settings::reset());
			case 'admin.check':
				return ['sections' => Diagnostics::run($s)];
		}
		throw new AppError('Неизвестное действие «' . $action . '»');
	}

	/** Настройки с подсказками: какие поля есть у товара — чтобы выбирать, а не угадывать коды. */
	private static function settings(array $s): array
	{
		$meta = ['catalogFields' => [], 'vinAuto' => [], 'sections' => [], 'errors' => []];
		$steps = [
			'catalogFields' => static function () use ($s) {
				return Fields::options($s['catalog_iblock_id']);
			},
			'vinAuto' => static function () use ($s) {
				return Catalog::vinFields(['vin_fields' => []] + $s);
			},
			'sections' => static function () use ($s) {
				$names = Catalog::sectionNames($s['catalog_iblock_id'], $s['section_ids']);
				$out = [];
				foreach ($s['section_ids'] as $id)
				{
					$out[] = ['id' => $id, 'name' => $names[$id] ?? ('папка #' . $id . ' не найдена')];
				}

				return $out;
			},
		];
		// Настройки как раз и правят, когда портал отвечает не то: подсказка,
		// которую не удалось получить, не должна закрывать саму форму.
		foreach ($steps as $key => $step)
		{
			try
			{
				$meta[$key] = $step();
			}
			catch (\Throwable $e)
			{
				$meta['errors'][] = $e->getMessage();
			}
		}

		return ['settings' => $s, 'meta' => $meta];
	}
}

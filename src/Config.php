<?php namespace Model\Config;

use Proyect\Root\Root;

class Config
{
	private static array $cache = [];

	// TODO: external caching (symfony/cache)

	public static function get(string $key, callable $default): array
	{
		if (isset(self::$cache[$key]))
			return self::$cache[$key];

		$root = Root::root();

		if (!is_dir($root . '/app/config'))
			mkdir($root . '/app/config', 0777, true);
		if (!is_writable($root . '/app/config'))
			throw new \Exception('Config directory is not writable');

		$filepath = $root . '/app/config/' . $key . '.php';

		if (!file_exists($filepath)) {
			$default = call_user_func($default);
			if (!is_string($default))
				$default = var_export($default, true);

			file_put_contents($filepath, "<?php\nreturn " . $default);
		}

		self::$cache[$key] = require($filepath);
		return self::$cache[$key];
	}
}

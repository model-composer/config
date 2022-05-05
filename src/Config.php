<?php namespace Model\Config;

use Proyect\Root\Root;

class Config
{
	// TODO: external caching (symfony/cache)

	private static array $cache = [];
	private static string $env = 'production';

	/**
	 * Env type setter
	 *
	 * @param string $env
	 * @return void
	 */
	public static function setEnv(string $env): void
	{
		self::$env = $env;
	}

	/**
	 * Get specified config; if not present, gets the default
	 *
	 * @param string $key
	 * @param callable $default - Must return default config as an array
	 * @param callable|null $migrateFunction - Migration function from ModEl v3 - Takes the file path as a string and returns a new string
	 * @return array
	 * @throws \Exception
	 */
	public static function get(string $key, callable $default, ?callable $migrateFunction = null): array
	{
		if (!isset(self::$cache[$key])) {
			$configPath = self::getConfigPath();

			if (!is_dir($configPath))
				mkdir($configPath, 0777, true);
			if (!is_writable($configPath))
				throw new \Exception('Config directory is not writable');

			$filepath = $configPath . DIRECTORY_SEPARATOR . $key . '.php';

			if (!file_exists($filepath)) {
				if (!self::migrateOldConfig($key, $migrateFunction)) {
					$default = call_user_func($default);
					if (!is_array($default))
						throw new \Exception('Default config must be an array');

					if (!file_put_contents($filepath, "<?php\nreturn " . var_export(['production' => $default], true) . ";\n"))
						throw new \Exception('Error while writing config file');
				}
			}

			self::$cache[$key] = require($filepath);
		}

		if (isset(self::$cache[$key][self::$env]))
			return self::$cache[$key];
		elseif (count(self::$cache[$key]) > 0)
			return reset(self::$cache[$key]);
		else
			return [];
	}

	/**
	 * Utility function to build the config directory path
	 *
	 * @return string
	 */
	private static function getConfigPath(): string
	{
		$root = Root::root();
		return $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'config';
	}

	/**
	 * Migration utility from ModEl v3 config files
	 *
	 * @param string $key
	 * @param callable|null $migrateFunction
	 * @return bool
	 */
	private static function migrateOldConfig(string $key, ?callable $migrateFunction = null): bool
	{
		if ($migrateFunction === null) {
			$migrateFunction = function (string $configPath) {
				require($configPath);
				if (isset($config)) {
					$config = [
						'production' => $config,
					];

					return "<?php\nreturn " . var_export($config, true) . ";\n";
				} else {
					return null;
				}
			};
		}

		$configPath = self::getConfigPath();
		$oldKey = str_replace(' ', '', ucwords(str_replace('-', ' ', $key)));
		if (file_exists($configPath . DIRECTORY_SEPARATOR . $oldKey . DIRECTORY_SEPARATOR . 'config.php')) {
			$migratedConfig = $migrateFunction($configPath . DIRECTORY_SEPARATOR . $oldKey . DIRECTORY_SEPARATOR . 'config.php');
			if ($migratedConfig !== null)
				return (bool)file_put_contents($configPath . DIRECTORY_SEPARATOR . $key . '.php', $migratedConfig);

			return false;
		}

		return false;
	}
}

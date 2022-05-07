<?php namespace Model\Config;

use Composer\InstalledVersions;
use Proyect\Root\Root;
use Symfony\Component\Dotenv\Dotenv;

class Config
{
	private static bool $envLoaded = false;
	private static array $internalCache = [];

	/**
	 * Env type getter
	 *
	 * @return string
	 */
	private static function getEnv(): string
	{
		if (!self::$envLoaded) {
			$root = Root::root();
			if (file_exists($root . DIRECTORY_SEPARATOR . '.env')) {
				$dotenv = new Dotenv();
				$dotenv->load($root . DIRECTORY_SEPARATOR . '.env');
			}

			self::$envLoaded = true;
		}

		return $_ENV['APP_ENV'] ?? $_ENV['ENVIRONMENT'] ?? 'production';
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
		// If it's the first time for this run I'm requesting this config
		if (!isset(self::$internalCache[$key])) {
			if (!in_array($key, ['redis', 'config']) and InstalledVersions::isInstalled('model/cache') and InstalledVersions::isInstalled('model/redis') and \Model\Redis\Redis::isEnabled()) {
				// If there is a redis caching library installed, I use it to retrieve it (or store it) from there
				// I do not cache config for "redis" and "config" libraries, or it would results in an infinite recursion

				$cache = \Model\Cache\Cache::getCacheAdapter('redis');
				self::$internalCache[$key] = $cache->get('model.config.' . $key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($key, $default, $migrateFunction) {
					$item->expiresAfter(300);
					$item->tag('config');
					return self::retrieveConfig($key, $default, $migrateFunction);
				});
			} else {
				// Otherwise I just retrieve it from file
				self::$internalCache[$key] = self::retrieveConfig($key, $default, $migrateFunction);
			}
		}

		$env = self::getEnv();
		if (isset(self::$internalCache[$key][$env]))
			return self::$internalCache[$key][$env];
		elseif (count(self::$internalCache[$key]) > 0)
			return reset(self::$internalCache[$key]);
		else
			return [];
	}

	/**
	 * Internal method for retrieves config directly without cache
	 *
	 * @param string $key
	 * @param callable $default
	 * @param callable|null $migrateFunction
	 * @return array
	 * @throws \Exception
	 */
	private static function retrieveConfig(string $key, callable $default, ?callable $migrateFunction = null): array
	{
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

		return require($filepath);
	}

	/**
	 * Utility function to build the config directory path
	 *
	 * @return string
	 */
	private static function getConfigPath(): string
	{
		$root = Root::root();
		return $root . DIRECTORY_SEPARATOR . ($_ENV['CONFIG_PATH'] ?? 'config');
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

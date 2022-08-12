<?php namespace Model\Config;

use Composer\InstalledVersions;
use Model\Cache\Cache as Cache;
use Symfony\Component\Dotenv\Dotenv;

class Config
{
	private static bool $envLoaded = false;
	private static array $internalCache = [];

	/**
	 * Load variables from .env file, if present
	 *
	 * @return void
	 */
	public static function loadEnv(): void
	{
		if (!self::$envLoaded) {
			if (file_exists(self::getProjectRoot() . '.env')) {
				$dotenv = new Dotenv();
				$dotenv->overload(self::getProjectRoot() . '.env');
			}

			self::$envLoaded = true;
		}
	}

	/**
	 * Env type getter
	 *
	 * @return string
	 */
	public static function getEnv(): string
	{
		self::loadEnv();
		$env = $_ENV['APP_ENV'] ?? $_ENV['ENVIRONMENT'] ?? 'production';
		if ($env === 'meta')
			throw new \Exception('"meta" is a reserved keyword of model/config library, cannot be used as env type');
		return $env;
	}

	/**
	 * Get specified config; if not present, gets the default
	 *
	 * @param string $key
	 * @param array $migrations
	 * @return array
	 * @throws \Exception
	 */
	public static function get(string $key, array $migrations = [], array $enableTemplating = []): array
	{
		// If it's the first time for this run I'm requesting this config
		if (!isset(self::$internalCache[$key])) {
			if (!in_array($key, ['redis', 'cache']) and self::isCacheEnabled()) {
				// If there is a redis caching library installed, I use it to retrieve it (or store it) from there
				// I do not cache config for "redis" and "cache" libraries, or it would result in an infinite recursion

				$cache = Cache::getCacheAdapter('redis');
				self::$internalCache[$key] = $cache->get('model.config.' . $key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($key, $migrations) {
					$item->expiresAfter(3600 * 24);
					$item->tag('config');

					Cache::registerInvalidation('tag', ['config'], 'redis');

					return self::retrieveConfig($key, $migrations);
				});
			} else {
				// Otherwise I just retrieve it from file
				self::$internalCache[$key] = self::retrieveConfig($key, $migrations);
			}
		}

		$env = self::getEnv();
		$config = self::$internalCache[$key][$env] ?? self::$internalCache[$key]['production'] ?? [];

		foreach ($enableTemplating as $path => $valueType) {
			if (is_numeric($path)) {
				$path = $valueType;
				$valueType = 'string';
			}

			$path = trim($path);
			if ($path === '')
				continue;

			$config = self::checkTemplating($config, $path, $valueType);
		}

		return $config;
	}

	/**
	 * @return bool
	 */
	private static function isCacheEnabled(): bool
	{
		return (InstalledVersions::isInstalled('model/cache') and InstalledVersions::isInstalled('model/redis') and \Model\Redis\Redis::isEnabled());
	}

	/**
	 * Stores new config
	 *
	 * @param string $key
	 * @param array $config
	 * @return void
	 */
	public static function set(string $key, array $config): void
	{
		$filepath = self::getConfigFilePath($key);
		$fullConfig = self::retrieveConfig($key);
		$fullConfig[self::getEnv()] = $config;
		self::saveConfigFile($filepath, $fullConfig);
		if (self::isCacheEnabled())
			Cache::invalidate();
	}

	/**
	 * @param string $filepath
	 * @param array $config
	 * @return void
	 * @throws \Exception
	 */
	private static function saveConfigFile(string $filepath, array $config): void
	{
		if (!file_put_contents($filepath, "<?php\nreturn " . var_export($config, true) . ";\n"))
			throw new \Exception('Error while writing config file');
	}

	/**
	 * Internal method for retrieving config directly without cache
	 *
	 * @param string $key
	 * @param array $migrations
	 * @return array
	 * @throws \Exception
	 */
	private static function retrieveConfig(string $key, array $migrations = []): array
	{
		$filepath = self::getConfigFilePath($key);

		if (file_exists($filepath)) {
			$config = require($filepath);

			if (!isset($config['meta'])) {
				$config['meta'] = [
					'version' => null,
				];
			}
		} else {
			$config = [
				'meta' => [
					'version' => null,
				],
				'production' => [],
			];
		}

		$latestVersion = null;
		foreach ($migrations as $migration) {
			if (!is_array($migration) or !isset($migration['version'], $migration['migration']))
				throw new \Exception('Wrong config migration format');

			if (version_compare($config['meta']['version'] ?? '0.0.0', $migration['version']) >= 0)
				continue;

			foreach ($config as $configEnv => $configReal) {
				if ($configEnv === 'meta')
					continue;

				$config[$configEnv] = call_user_func($migration['migration'], $configReal, $configEnv);
				if (!is_array($config[$configEnv]))
					throw new \Exception('Config must be an array');
			}

			$latestVersion = $migration['version'];
		}

		if ($latestVersion !== null and $config['meta']['version'] !== $latestVersion) {
			$config['meta']['version'] = $latestVersion;
			self::saveConfigFile($filepath, $config);
		}

		return $config;
	}

	/**
	 * Returns full path to a config file
	 *
	 * @param string $key
	 * @return string
	 */
	private static function getConfigFilePath(string $key): string
	{
		$configPath = self::getConfigPath();

		if (!is_dir($configPath))
			mkdir($configPath, 0777, true);
		if (!is_writable($configPath))
			throw new \Exception('Config directory is not writable');

		return $configPath . DIRECTORY_SEPARATOR . $key . '.php';
	}

	/**
	 * Replace config paths, if applicable
	 *
	 * @param array $config
	 * @param string $path
	 * @param string $valueType
	 * @return array
	 * @throws \Exception
	 */
	private static function checkTemplating(array $config, string $path, string $valueType): array
	{
		$path = explode('.', $path);

		$configItem = &$config;
		foreach ($path as $key) {
			if (isset($configItem[$key]))
				$configItem = &$configItem[$key];
			else
				return $config;
		}

		if (!is_string($configItem) or !preg_match('/^\{\{.+\}\}$/i', $configItem))
			return $config;

		$template = substr($configItem, 2, -2);
		$exploded = explode('|', $template);
		$template = explode('.', $exploded[0]);
		$default = $exploded[1] ?? null;

		$finalValue = null;
		switch ($template[0]) {
			case 'env':
				$finalValue = $_ENV;
				break;
			case 'server':
				$finalValue = $_SERVER;
				break;
			case 'session':
				$finalValue = $_SESSION;
				break;
		}

		array_shift($template);

		foreach ($template as $key) {
			if (array_key_exists($key, $finalValue)) {
				$finalValue = $finalValue[$key];
			} else {
				$finalValue = $default;
				break;
			}
		}

		switch ($valueType) {
			case 'string':
				$configItem = (string)$finalValue;
				break;

			case 'int':
				$configItem = (int)$finalValue;
				break;

			case 'float':
				$configItem = (float)$finalValue;
				break;

			case 'bool':
				$configItem = (bool)$finalValue;
				break;

			default:
				throw new \Exception('Unsupported value type in config template');
		}

		return $config;
	}

	/**
	 * Utility function to build the config directory path
	 *
	 * @return string
	 */
	private static function getConfigPath(): string
	{
		return self::getProjectRoot() . ($_ENV['CONFIG_PATH'] ?? 'config');
	}

	/**
	 * @return string
	 */
	private static function getProjectRoot(): string
	{
		return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..') . DIRECTORY_SEPARATOR;
	}
}

<?php namespace Model\Config;

use Composer\InstalledVersions;
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
				$dotenv->load(self::getProjectRoot() . '.env');
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
		return $_ENV['APP_ENV'] ?? $_ENV['ENVIRONMENT'] ?? 'production';
	}

	/**
	 * Get specified config; if not present, gets the default
	 *
	 * @param string $key
	 * @param array $migrations
	 * @return array
	 * @throws \Exception
	 */
	public static function get(string $key, array $migrations = []): array
	{
		// If it's the first time for this run I'm requesting this config
		if (!isset(self::$internalCache[$key])) {
			if (!in_array($key, ['redis', 'cache']) and InstalledVersions::isInstalled('model/cache') and InstalledVersions::isInstalled('model/redis') and \Model\Redis\Redis::isEnabled()) {
				// If there is a redis caching library installed, I use it to retrieve it (or store it) from there
				// I do not cache config for "redis" and "cache" libraries, or it would result in an infinite recursion

				$cache = \Model\Cache\Cache::getCacheAdapter('redis');
				self::$internalCache[$key] = $cache->get('model.config.' . $key, function (\Symfony\Contracts\Cache\ItemInterface $item) use ($key, $migrations) {
					$item->expiresAfter(3600 * 24);
					$item->tag('config');
					return self::retrieveConfig($key, $migrations);
				});

				\Model\Cache\Cache::registerInvalidation('tag', ['config'], 'redis');
			} else {
				// Otherwise I just retrieve it from file
				self::$internalCache[$key] = self::retrieveConfig($key, $migrations);
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
	 * Internal method for retrieving config directly without cache
	 *
	 * @param string $key
	 * @param array $migrations
	 * @return array
	 * @throws \Exception
	 */
	private static function retrieveConfig(string $key, array $migrations = []): array
	{
		$configPath = self::getConfigPath();

		if (!is_dir($configPath))
			mkdir($configPath, 0777, true);
		if (!is_writable($configPath))
			throw new \Exception('Config directory is not writable');

		$filepath = $configPath . DIRECTORY_SEPARATOR . $key . '.php';

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

			if (!file_put_contents($filepath, "<?php\nreturn " . var_export($config, true) . ";\n"))
				throw new \Exception('Error while writing config file');
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
		return realpath(__DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..') . '' . DIRECTORY_SEPARATOR;
	}
}

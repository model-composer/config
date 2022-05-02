<?php namespace Model\Config;

use Proyect\Root\Root;

class Config
{
	private static array $cache = [];

	// TODO: external caching (symfony/cache)

	/**
	 * Get specified config; if not present, gets the default
	 *
	 * @param string $key
	 * @param callable $default
	 * @return array
	 * @throws \Exception
	 */
	public static function get(string $key, callable $default): array
	{
		if (isset(self::$cache[$key]))
			return self::$cache[$key];

		$configPath = self::getConfigPath();

		if (!is_dir($configPath))
			mkdir($configPath, 0777, true);
		if (!is_writable($configPath))
			throw new \Exception('Config directory is not writable');

		$filepath = $configPath . DIRECTORY_SEPARATOR . $key . '.php';

		if (!file_exists($filepath)) {
			if (!self::migrateOldConfig($key)) {
				$default = call_user_func($default);
				if (!is_string($default))
					$default = var_export($default, true);

				if (!file_put_contents($filepath, "<?php\nreturn " . $default . ";\n"))
					throw new \Exception('Error while writing config file');
			}
		}

		self::$cache[$key] = require($filepath);
		return self::$cache[$key];
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
	 * @return bool
	 */
	private static function migrateOldConfig(string $key): bool
	{
		$configPath = self::getConfigPath();
		$oldKey = str_replace(' ', '', ucwords(str_replace('-', ' ', $key)));
		if (file_exists($configPath . DIRECTORY_SEPARATOR . $oldKey . DIRECTORY_SEPARATOR . 'config.php')) {
			$fileContent = file_get_contents($configPath . DIRECTORY_SEPARATOR . $oldKey . DIRECTORY_SEPARATOR . 'config.php');
			if (str_starts_with($fileContent, "<?php\n\$config = ")) {
				$fileContent = str_replace("<?php\n\$config = ", "<?php\nreturn ", $fileContent);
				return (bool)file_put_contents($configPath . DIRECTORY_SEPARATOR . $key . '.php', $fileContent);
			}
		}

		return false;
	}
}

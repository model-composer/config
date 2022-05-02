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
	 * @param callable|null $migrateFunction - Migration function from ModEl v3 - Takes the file path as a string and returns a new string
	 * @return array
	 * @throws \Exception
	 */
	public static function get(string $key, callable $default, ?callable $migrateFunction = null): array
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
			if (!self::migrateOldConfig($key, $migrateFunction)) {
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
	 * @param callable|null $migrateFunction
	 * @return bool
	 */
	private static function migrateOldConfig(string $key, ?callable $migrateFunction = null): bool
	{
		if ($migrateFunction === null) {
			$migrateFunction = function (string $configPath) {
				$fileContent = file_get_contents($configPath);
				if (str_starts_with($fileContent, "<?php\n\$config = "))
					return str_replace("<?php\n\$config = ", "<?php\nreturn ", $fileContent);
				else
					return null;
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

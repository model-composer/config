<?php namespace Model\Config;

use Proyect\Root\Root;

class Config
{
	// TODO: caching

	public static function get(string $key, callable $default): array
	{
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

		return require($filepath);
	}
}

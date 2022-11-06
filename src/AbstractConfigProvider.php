<?php namespace Model\Config;

use Model\ProvidersFinder\AbstractProvider;

abstract class AbstractConfigProvider extends AbstractProvider
{
	private static string $configKey;

	abstract public static function migrations(): array;

	public static function setConfigKey(string $configKey): void
	{
		self::$configKey = $configKey;
	}

	public static function getConfigKey(): ?string
	{
		return self::$configKey ?? null;
	}

	public static function templating(): array
	{
		return [];
	}
}

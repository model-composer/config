<?php namespace Model\Config;

use Model\ProvidersFinder\AbstractProvider;

abstract class AbstractConfigProvider extends AbstractProvider
{
	abstract public static function migrations(): array;

	public static function getConfigKey(): ?string
	{
		return null;
	}

	public static function templating(): array
	{
		return [];
	}
}

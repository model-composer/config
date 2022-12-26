<?php namespace Model\Config\Providers;

use Model\Config\Config;
use Model\Core\AbstractModelProvider;

class ModelProvider extends AbstractModelProvider
{
	public static function realign(): void
	{
		Config::resetCache();
	}
}

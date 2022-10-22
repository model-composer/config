<?php namespace Model\Config;

use Model\Core\AbstractModelProvider;

class ModelProvider extends AbstractModelProvider
{
	public static function realign(): void
	{
		Config::resetCache();
	}
}

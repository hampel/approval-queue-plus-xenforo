<?php namespace Hampel\ApprovalQueuePlus\Option;

use XF\Option\AbstractOption;

class UserAgentCleanUp extends AbstractOption
{
	public static function get()
	{
		return \XF::options()->approvalQueuePlusUserAgentCleanUp;
	}

	public static function isEnabled()
	{
		$value = self::get();
		if (isset($value['enabled']) && $value['enabled'] == "1")
		{
			return true;
		}
		else
		{
			return false;
		}
	}

	public static function getDelay()
	{
		$value = self::get();
		if (isset($value['delay']) && is_numeric($value['delay']))
		{
			return intval($value['delay']);
		}
		else
		{
			return 0;
		}
	}
}

<?php

class ModeratedUsers_Helper_ClassProxy
{
	public static function parentHasMethod($class, $method)
	{
		$parents = class_parents($class);
		$parentindex = array_flip(array_keys($parents));
		$realparents = array_slice($parents, $parentindex['XFCP_' . $class] + 1);
		return method_exists(reset($realparents), $method);
	}
}
<?php 

class ModeratedUsers_Listener_LoadClass
{
	public static function load_viewadmin_user_moderated($class, array &$extend)
	{
		$extend[] = 'ModeratedUsers_ViewAdmin_User_Moderated';
	}
}

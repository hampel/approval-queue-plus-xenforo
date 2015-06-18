<?php

class ModeratedUsers_ViewAdmin_User_Moderated extends XFCP_ModeratedUsers_ViewAdmin_User_Moderated
{
	public function renderHtml()
	{
		if (ModeratedUsers_Helper_ClassProxy::parentHasMethod(__CLASS__, 'renderHtml'))
		{
			parent::renderHtml();
		}

		$userIds = array_keys($this->_params['users']);

		if (!empty($userIds))
		{
			$userModel = XenForo_Model::create('XenForo_Model_User');
			$users = $userModel->getUsersByIds($userIds, ['join' => XenForo_Model_User::FETCH_USER_PROFILE]);

			foreach ($this->_params['users'] as $userid => $user)
			{
				if (isset($users[$userid]['location']))
				{
					$this->_params['users'][$userid]['location'] = $users[$userid]['location'];
				}
			}
		}
	}
}

<?php namespace Hampel\ApprovalQueuePlus\XF\Entity;

use XF\Mvc\Entity\Structure;

class User extends XFCP_User
{
	public static function getStructure(Structure $structure)
	{
		$structure = parent::getStructure($structure);

		$structure->relations['UserAgent'] = [
			'entity' => 'Hampel\ApprovalQueuePlus:UserAgent',
			'type' => self::TO_ONE,
			'conditions' => 'user_id',
			'primary' => true
		];

		return $structure;
	}

	public function canViewUserAgents()
	{
		return $this->exists() && $this->hasPermission('general', 'viewUserAgents');
	}
}
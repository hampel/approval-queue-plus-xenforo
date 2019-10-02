<?php namespace Hampel\ApprovalQueuePlus\Entity;

use XF\Mvc\Entity\Entity;
use XF\Mvc\Entity\Structure;

class UserAgent extends Entity
{
	public static function getStructure(Structure $structure)
	{
		$structure->table = 'xf_user_agent';
		$structure->shortName = 'Hampel\ApprovalQueuePlus:UserAgent';
		$structure->primaryKey = 'user_id';
		$structure->columns = [
			'user_id' => ['type' => self::UINT, 'required' => true],
			'user_agent' => ['type' => self::STR, 'required' => true],
		];

		$structure->relations = [
			'User' => [
				'entity' => 'XF:User',
				'type' => self::TO_ONE,
				'conditions' => 'user_id',
				'primary' => true
			]
		];

		return $structure;
	}
}
